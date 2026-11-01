<?php
/**
 * Entry intelligence: summary, sentiment, intent, category, lead score and urgency per entry.
 * A rule-based score is used when AI is off or fails, so the column is never empty.
 *
 * Sent to the AI: site name, form title and "Label: value" lines (phone numbers, passwords,
 * files, signatures and hidden fields are left out; emails are reduced to their domain).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\AI;

use Dev3\AiFormBuilder\Core3\Api\Prompts;
use Dev3\AiFormBuilder\Data\Entries;
use Dev3\AiFormBuilder\Data\Entry_Log;
use Dev3\AiFormBuilder\Forms\Definition;
use Dev3\AiFormBuilder\Forms\Form_Repository;
use Dev3\AiFormBuilder\Submission\Entry_Format;

defined( 'ABSPATH' ) || exit;

/**
 * Insights.
 */
class Entry_Insights {

	const JOB = 'dev3fb_analyze';

	/**
	 * Text the AI sees.
	 *
	 * @param array $definition Definition.
	 * @param array $data       Values.
	 * @return string
	 */
	public static function compose( array $definition, array $data ) {
		return Classifier::compose( Entry_Format::for_classifier( $definition, $data ), 6000 );
	}

	/**
	 * Analyses one entry and stores the result.
	 *
	 * @param int  $entry_id Entry id.
	 * @param bool $use_ai   Use AI (else rule-based only).
	 * @return array|\WP_Error Insights.
	 */
	public static function analyze( $entry_id, $use_ai = true ) {
		$entry = Entries::get( $entry_id );
		if ( ! $entry ) {
			return new \WP_Error( 'dev3fb_not_found', __( 'Entry not found.', 'dev3-ai-form-builder' ), array( 'status' => 404 ) );
		}
		$form = Form_Repository::get( $entry['form_id'] );
		if ( ! $form ) {
			return new \WP_Error( 'dev3fb_not_found', __( 'Form not found.', 'dev3-ai-form-builder' ), array( 'status' => 404 ) );
		}
		$insights = $use_ai ? self::ai( $form, $entry ) : null;
		if ( is_wp_error( $insights ) ) {
			Entry_Log::add( $entry_id, 'ai', 'error', sprintf( 'Entry insights failed: %s', $insights->get_error_message() ) );
			$error    = $insights;
			$insights = null;
		}
		if ( null === $insights ) {
			$insights = self::rule_based( $form['definition'], $entry['data'] );
		}
		Entries::update(
			$entry_id,
			array(
				'ai'         => $insights,
				'lead_score' => (int) $insights['lead_score'],
				'sentiment'  => (string) $insights['sentiment'],
			)
		);
		if ( isset( $error ) && $use_ai ) {
			return $error;
		}
		return $insights;
	}

	/**
	 * AI analysis.
	 *
	 * @param array $form  Form.
	 * @param array $entry Entry.
	 * @return array|\WP_Error
	 */
	public static function ai( array $form, array $entry ) {
		$text = self::compose( $form['definition'], $entry['data'] );
		if ( '' === trim( $text ) ) {
			return null;
		}
		$result = Prompts::run(
			'entry_insights',
			array(
				'site'           => wp_strip_all_tags( get_bloginfo( 'name' ) ),
				'form'           => $form['title'],
				'entry'          => $text,
				'admin_language' => Form_Generator::language_name( get_locale() ),
			),
			'admin',
			array( 'max_tokens' => 1500 )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$out           = self::normalize( isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array() );
		$out['source'] = 'ai';
		$out['model']  = isset( $result['model'] ) ? $result['model'] : '';
		return $out;
	}

	/**
	 * Clamps AI output.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	public static function normalize( array $data ) {
		$sentiment = isset( $data['sentiment'] ) ? strtolower( (string) $data['sentiment'] ) : 'neutral';
		$category  = isset( $data['category'] ) ? strtolower( (string) $data['category'] ) : 'other';
		$urgency   = isset( $data['urgency'] ) ? strtolower( (string) $data['urgency'] ) : 'low';
		$tags      = array();
		foreach ( isset( $data['tags'] ) && is_array( $data['tags'] ) ? $data['tags'] : array() as $tag ) {
			$tag = sanitize_title( is_scalar( $tag ) ? (string) $tag : '' );
			if ( '' !== $tag && ! in_array( $tag, $tags, true ) ) {
				$tags[] = substr( $tag, 0, 30 );
			}
		}
		return array(
			'summary'     => mb_substr( sanitize_text_field( isset( $data['summary'] ) ? (string) $data['summary'] : '' ), 0, 400 ),
			'sentiment'   => in_array( $sentiment, Prompt_Library::SENTIMENTS, true ) ? $sentiment : 'neutral',
			'intent'      => mb_substr( sanitize_text_field( isset( $data['intent'] ) ? (string) $data['intent'] : '' ), 0, 80 ),
			'category'    => in_array( $category, Prompt_Library::CATEGORIES, true ) ? $category : 'other',
			'lead_score'  => max( 0, min( 100, (int) round( isset( $data['lead_score'] ) ? (float) $data['lead_score'] : 0 ) ) ),
			'lead_reason' => mb_substr( sanitize_text_field( isset( $data['lead_reason'] ) ? (string) $data['lead_reason'] : '' ), 0, 200 ),
			'urgency'     => in_array( $urgency, array( 'low', 'medium', 'high' ), true ) ? $urgency : 'low',
			'language'    => substr( strtolower( preg_replace( '/[^A-Za-z\-]/', '', isset( $data['language'] ) ? (string) $data['language'] : '' ) ), 0, 8 ),
			'tags'        => array_slice( $tags, 0, 5 ),
			'analyzed_at' => gmdate( 'c' ),
		);
	}

	/**
	 * Rule-based insights (no AI).
	 *
	 * @param array $definition Definition.
	 * @param array $data       Values.
	 * @return array
	 */
	public static function rule_based( array $definition, array $data ) {
		$score   = 20;
		$reasons = array();
		$text    = strtolower( Entry_Format::plain( $definition, $data ) );
		foreach ( Definition::fields( $definition ) as $field ) {
			$value = isset( $data[ $field['id'] ] ) ? $data[ $field['id'] ] : '';
			if ( '' === $value || array() === $value ) {
				continue;
			}
			if ( 'phone' === $field['type'] ) {
				$score    += 15;
				$reasons[] = __( 'left a phone number', 'dev3-ai-form-builder' );
			}
			if ( 'email' === $field['type'] ) {
				$domain = strtolower( (string) substr( strrchr( (string) $value, '@' ), 1 ) );
				if ( '' !== $domain && ! in_array( $domain, array( 'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'aol.com', 'gmx.de', 'web.de', 'proton.me', 'protonmail.com' ), true ) ) {
					$score    += 15;
					$reasons[] = __( 'uses a company email', 'dev3-ai-form-builder' );
				}
			}
			if ( preg_match( '/(company|organi[sz]ation|business)/i', $field['label'] . ' ' . $field['id'] ) ) {
				$score    += 10;
				$reasons[] = __( 'named a company', 'dev3-ai-form-builder' );
			}
			if ( preg_match( '/(budget|price|quote|amount)/i', $field['label'] . ' ' . $field['id'] ) ) {
				$score    += 15;
				$reasons[] = __( 'shared a budget', 'dev3-ai-form-builder' );
			}
		}
		if ( preg_match( '/\b(quote|pricing|price|buy|purchase|order|proposal|budget|asap|urgent|demo|hire)\b/', $text ) ) {
			$score    += 15;
			$reasons[] = __( 'mentions buying intent', 'dev3-ai-form-builder' );
		}
		$negative  = preg_match( '/\b(refund|broken|angry|terrible|worst|complain|disappointed|cancel)\b/', $text );
		$positive  = preg_match( '/\b(thanks|thank you|great|love|excellent|amazing|happy)\b/', $text );
		$sentiment = $negative ? 'negative' : ( $positive ? 'positive' : 'neutral' );
		$urgent    = preg_match( '/\b(asap|urgent|immediately|today|tomorrow)\b/', $text );
		return array(
			'summary'     => '',
			'sentiment'   => $sentiment,
			'intent'      => '',
			'category'    => $negative ? 'complaint' : 'other',
			'lead_score'  => max( 0, min( 100, $score ) ),
			'lead_reason' => $reasons ? ucfirst( implode( ', ', array_unique( $reasons ) ) ) . '.' : __( 'Little buying signal in the answers.', 'dev3-ai-form-builder' ),
			'urgency'     => $urgent ? 'high' : 'low',
			'language'    => '',
			'tags'        => array(),
			'source'      => 'rules',
			'analyzed_at' => gmdate( 'c' ),
		);
	}

	/**
	 * Background job handler (bulk + automatic analysis).
	 *
	 * @param array $items Entry ids.
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_chunk( array $items, array $job ) {
		unset( $job );
		$out = array();
		foreach ( $items as $id ) {
			$result     = self::analyze( (int) $id, true );
			$out[ $id ] = is_wp_error( $result ) ? $result->get_error_message() : true;
		}
		return $out;
	}
}
