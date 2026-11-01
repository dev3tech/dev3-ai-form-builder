<?php
/**
 * Server-side spam / abuse gate. Ported from DEV3 AI Form Autofill & Spam Shield (dev3fa)
 * and wired to this plugin's own forms.
 *
 * Layers, cheapest first: honeypot, time trap (signed render token), per-IP submit rate limit,
 * then (per form, optional) the AI classifier with a site-wide daily cap and fail-open/closed.
 * Decision = { action: allow|block|spam|flag, triggered, trigger, verdict, message, reason, log_id }.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Spam;

use Dev3\AiFormBuilder\AI\Classifier;
use Dev3\AiFormBuilder\Core3\Core\Rate_Limiter;
use Dev3\AiFormBuilder\Core3\Core\Settings_Store;
use Dev3\AiFormBuilder\Submission\Entry_Format;

defined( 'ABSPATH' ) || exit;

/**
 * Gate.
 */
class Spam_Gate {

	/**
	 * Cheap checks that need no AI. Returns a decision when triggered, else null.
	 *
	 * @param array      $form     Form.
	 * @param array      $input    Raw request params (honeypot lives here).
	 * @param int|string $issued   Token issue time (or WP_Error).
	 * @param int|null   $now      Clock.
	 * @return array|null
	 */
	public static function precheck( array $form, array $input, $issued, $now = null ) {
		$protection = $form['definition']['protection'];
		$now        = null === $now ? time() : (int) $now;
		$row        = array(
			'form_id'    => $form['id'],
			'form_title' => $form['title'],
			'ip_hash'    => Spam_Log::hash( Rate_Limiter::client_ip() ),
		);
		if ( $protection['honeypot'] && ! empty( $input['dev3fb_hp'] ) ) {
			return self::trigger( 'block', 'honeypot', array(), $row, __( 'The hidden honeypot field was filled in (bot).', 'dev3-ai-form-builder' ), true );
		}
		if ( is_wp_error( $issued ) ) {
			return null; // The handler reports an out-of-date token separately.
		}
		if ( $protection['min_seconds'] > 0 && ( $now - (int) $issued ) < $protection['min_seconds'] ) {
			return self::trigger( 'block', 'too_fast', array(), $row, __( 'Submitted faster than a person can fill the form.', 'dev3-ai-form-builder' ), true );
		}
		if ( ! Rate_Limiter::hit( 'submit_' . $form['id'], Rate_Limiter::client_ip(), (int) $protection['rate_limit'] ) ) {
			return self::trigger( 'block', 'rate_limit', array(), $row, __( 'Too many submissions from the same address in a minute.', 'dev3-ai-form-builder' ) );
		}
		return null;
	}

	/**
	 * AI check of validated values.
	 *
	 * @param array $form   Form.
	 * @param array $values Sanitized, logic-applied values.
	 * @return array Decision.
	 */
	public static function check( array $form, array $values ) {
		$protection = $form['definition']['protection'];
		$allow      = self::result( 'allow' );
		if ( empty( $protection['ai_spam'] ) || '' === Settings_Store::api_key() ) {
			return $allow;
		}
		$items = Entry_Format::for_classifier( $form['definition'], $values );
		$text  = Classifier::compose( $items );
		if ( '' === trim( $text ) ) {
			return $allow;
		}
		$email = '';
		foreach ( $items as $item ) {
			if ( 'email' === $item['type'] ) {
				$email = (string) $item['value'];
				break;
			}
		}
		$email_hash = Spam_Log::hash( $email );
		$skip       = Spam_Log::is_allowlisted( $email_hash ) || ( Settings_Store::get( 'skip_logged_in' ) && is_user_logged_in() );
		/**
		 * Skip the AI spam check for a submission.
		 *
		 * @param bool  $skip   Skip.
		 * @param array $form   Form.
		 * @param array $values Values.
		 */
		if ( apply_filters( 'dev3fb_skip_spam_check', $skip, $form, $values ) ) {
			return $allow;
		}
		$ip  = Rate_Limiter::client_ip();
		$row = array(
			'form_id'      => $form['id'],
			'form_title'   => $form['title'],
			'excerpt'      => Spam_Log::excerpt( self::free_text( $items ) ),
			'content_hash' => hash( 'sha256', $text ),
			'email_hash'   => $email_hash,
			'ip_hash'      => Spam_Log::hash( $ip ),
		);
		if ( ! Rate_Limiter::hit( 'classify', $ip, (int) Settings_Store::get( 'classify_rate_limit', 12 ) ) ) {
			return self::trigger( $protection['spam_action'], 'rate_limit', array(), $row, __( 'Too many submissions from the same address in a minute.', 'dev3-ai-form-builder' ) );
		}
		$cap     = (int) Settings_Store::get( 'daily_ai_cap', 2000 );
		$verdict = $cap > 0 && ! Rate_Limiter::hit( 'classify_day', 'site', $cap, DAY_IN_SECONDS )
			? new \WP_Error( 'dev3fb_daily_cap', 'Daily AI check limit reached.' )
			: Classifier::classify( $text, $form['title'] );

		if ( is_wp_error( $verdict ) ) {
			$fail_open = (bool) Settings_Store::get( 'fail_open', true );
			Spam_Log::insert(
				$row + array(
					'action'       => $fail_open ? 'allow' : 'block',
					'trigger_type' => 'ai_error',
					'reason'       => sprintf( '%s: %s', $verdict->get_error_code(), $fail_open ? 'AI unavailable, delivered (fail-open).' : 'AI unavailable, blocked (fail-closed).' ),
				)
			);
			if ( $fail_open ) {
				return self::result( 'allow', array( 'trigger' => 'ai_error' ) );
			}
			return self::result(
				'block',
				array(
					'triggered' => true,
					'trigger'   => 'ai_error',
					'message'   => __( 'We could not verify your submission right now. Please try again in a few minutes.', 'dev3-ai-form-builder' ),
				)
			);
		}
		$is_spam    = round( $verdict['spam_probability'] * 100 ) >= (int) $protection['spam_threshold'];
		$is_abusive = ! empty( $protection['block_abusive'] ) && $verdict['abusive'];
		/**
		 * Final say on whether a classified submission is triggered.
		 *
		 * @param bool  $triggered  Triggered.
		 * @param array $verdict    AI verdict.
		 * @param array $protection Protection settings.
		 */
		$triggered = (bool) apply_filters( 'dev3fb_spam_triggered', $is_spam || $is_abusive, $verdict, $protection );
		if ( ! $triggered ) {
			return self::result( 'allow', array( 'verdict' => $verdict ) );
		}
		return self::trigger( $protection['spam_action'], $is_spam ? 'ai' : 'abusive', $verdict, $row, $verdict['reason'] );
	}

	/**
	 * Builds and logs a triggered decision.
	 *
	 * @param string $action  block|spam|flag.
	 * @param string $trigger Trigger.
	 * @param array  $verdict Verdict.
	 * @param array  $row     Log row base.
	 * @param string $reason  Reason.
	 * @param bool   $silent  Pretend success to the bot.
	 * @return array
	 */
	private static function trigger( $action, $trigger, array $verdict, array $row, $reason, $silent = false ) {
		$log_id   = Spam_Log::insert(
			$row + array(
				'action'       => $action,
				'trigger_type' => $trigger,
				'score'        => isset( $verdict['spam_probability'] ) ? (int) round( $verdict['spam_probability'] * 100 ) : 100,
				'abusive'      => ! empty( $verdict['abusive'] ) ? 1 : 0,
				'sentiment'    => isset( $verdict['sentiment'] ) ? $verdict['sentiment'] : '',
				'language'     => isset( $verdict['language'] ) ? $verdict['language'] : '',
				'reason'       => (string) $reason,
			)
		);
		$decision = self::result(
			$action,
			array(
				'triggered' => true,
				'trigger'   => $trigger,
				'verdict'   => $verdict,
				'message'   => 'block' === $action ? (string) Settings_Store::get( 'block_message', '' ) : '',
				'reason'    => (string) $reason,
				'log_id'    => $log_id,
				'silent'    => $silent,
			)
		);
		/**
		 * Fires when a submission triggers the spam shield.
		 *
		 * @param array $decision Decision.
		 * @param array $row      Log row (hashes and excerpt only).
		 */
		do_action( 'dev3fb_submission_flagged', $decision, $row );
		return $decision;
	}

	/**
	 * Decision shape.
	 *
	 * @param string $action Action.
	 * @param array  $extra  Extra.
	 * @return array
	 */
	private static function result( $action, array $extra = array() ) {
		return array_merge(
			array(
				'action'    => $action,
				'triggered' => false,
				'trigger'   => '',
				'verdict'   => null,
				'message'   => '',
				'reason'    => '',
				'log_id'    => 0,
				'silent'    => false,
			),
			$extra
		);
	}

	/**
	 * Free-text values (for the excerpt).
	 *
	 * @param array $items Items.
	 * @return string
	 */
	private static function free_text( array $items ) {
		$parts = array();
		foreach ( $items as $item ) {
			if ( in_array( $item['type'], array( 'textarea', 'text' ), true ) ) {
				$parts[] = $item['value'];
			}
		}
		return implode( ' — ', $parts );
	}
}
