<?php
/**
 * "Describe your form": a prompt becomes a complete multi-step form with logic.
 * The result is a draft: the admin previews it and only "Use this form" saves anything.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\AI;

use Dev3\AiFormBuilder\Core3\Api\Prompts;
use Dev3\AiFormBuilder\Forms\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Generator.
 */
class Form_Generator {

	/**
	 * Generates a draft.
	 *
	 * @param string $prompt  Description.
	 * @param array  $options { steps: auto|single|multi, language }.
	 * @return array|\WP_Error { title, definition, fixes, model, tokens, cached }
	 */
	public static function generate( $prompt, array $options = array() ) {
		$prompt = trim( (string) $prompt );
		if ( mb_strlen( $prompt ) < 8 ) {
			return new \WP_Error( 'dev3fb_prompt_short', __( 'Describe the form in at least a few words.', 'dev3-ai-form-builder' ), array( 'status' => 400 ) );
		}
		$steps  = isset( $options['steps'] ) && in_array( $options['steps'], array( 'auto', 'single', 'multi' ), true ) ? $options['steps'] : 'auto';
		$hint   = array(
			'auto'   => 'auto (use steps only for long forms)',
			'single' => 'no, one step',
			'multi'  => 'yes, 2-5 steps',
		);
		$result = Prompts::run(
			'form_generate',
			array(
				'site'     => wp_strip_all_tags( get_bloginfo( 'name' ) . ' - ' . get_bloginfo( 'description' ) ),
				'language' => self::language_name( isset( $options['language'] ) ? (string) $options['language'] : '' ),
				'steps'    => $hint[ $steps ],
				'prompt'   => mb_substr( $prompt, 0, 2000 ),
			),
			'admin',
			array( 'max_tokens' => 12000 )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data  = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$draft = self::from_ai( $data );
		return array_merge(
			$draft,
			array(
				'model'  => isset( $result['model'] ) ? $result['model'] : '',
				'tokens' => isset( $result['usage']['total'] ) ? (int) $result['usage']['total'] : 0,
				'cached' => ! empty( $result['cached'] ),
			)
		);
	}

	/**
	 * Converts AI JSON into a normalized definition and lists every automatic repair.
	 *
	 * @param array $data AI data.
	 * @return array { title, definition, fixes }
	 */
	public static function from_ai( array $data ) {
		$fixes = array();
		$steps = array();
		foreach ( isset( $data['steps'] ) && is_array( $data['steps'] ) ? $data['steps'] : array() as $i => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$fields = array();
			foreach ( isset( $step['fields'] ) && is_array( $step['fields'] ) ? $step['fields'] : array() as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
					$prices  = isset( $field['option_prices'] ) && is_array( $field['option_prices'] ) ? array_values( $field['option_prices'] ) : array();
					$options = array();
					foreach ( array_values( $field['options'] ) as $n => $label ) {
						$option = array( 'label' => is_scalar( $label ) ? (string) $label : '' );
						if ( isset( $prices[ $n ] ) && is_numeric( $prices[ $n ] ) ) {
							$option['price'] = (float) $prices[ $n ];
						}
						$options[] = $option;
					}
					$field['options'] = $options;
				}
				unset( $field['option_prices'] );
				if ( isset( $field['key'] ) ) {
					$field['id'] = $field['key'];
				}
				$fields[] = $field;
			}
			$steps[] = array(
				'id'     => 'step_' . ( $i + 1 ),
				'title'  => isset( $step['title'] ) ? $step['title'] : '',
				'fields' => $fields,
			);
		}
		$raw        = array(
			'steps'         => $steps,
			'settings'      => array(
				'description'  => isset( $data['description'] ) ? $data['description'] : '',
				'submit_label' => isset( $data['submit_label'] ) ? $data['submit_label'] : '',
			),
			'confirmations' => array(
				array(
					'type'    => 'message',
					'message' => isset( $data['confirmation_message'] ) ? $data['confirmation_message'] : '',
				),
			),
		);
		$definition = Definition::normalize( $raw, $fixes );
		$inputs     = Definition::input_fields( $definition );
		if ( ! $inputs ) {
			$fixes[] = __( 'The AI returned no usable fields.', 'dev3-ai-form-builder' );
		}
		// Single-step forms don't need a step title.
		if ( 1 === count( $definition['steps'] ) ) {
			$definition['steps'][0]['title'] = '';
		}
		// Reply-to the visitor when there is an email field.
		$email = Definition::email_field( $definition );
		if ( '' !== $email && isset( $definition['notifications'][0] ) ) {
			$definition['notifications'][0]['reply_to'] = '{field:' . $email . '}';
		}
		if ( '' === $definition['confirmations'][0]['message'] ) {
			$definition['confirmations'][0]['message'] = __( 'Thanks! Your submission has been received.', 'dev3-ai-form-builder' );
		}
		$title = Definition::text( isset( $data['title'] ) ? $data['title'] : '', 120 );
		return array(
			'title'      => '' === $title ? __( 'New form', 'dev3-ai-form-builder' ) : $title,
			'definition' => $definition,
			'fixes'      => array_values( array_unique( $fixes ) ),
		);
	}

	/**
	 * Human language name for a code (defaults to the site language).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function language_name( $code ) {
		$code  = '' === $code ? get_locale() : $code;
		$map   = array(
			'en' => 'English',
			'de' => 'German',
			'fr' => 'French',
			'es' => 'Spanish',
			'it' => 'Italian',
			'pt' => 'Portuguese',
			'nl' => 'Dutch',
			'pl' => 'Polish',
			'sv' => 'Swedish',
			'da' => 'Danish',
			'fi' => 'Finnish',
			'nb' => 'Norwegian',
			'cs' => 'Czech',
			'tr' => 'Turkish',
			'ru' => 'Russian',
			'uk' => 'Ukrainian',
			'ar' => 'Arabic',
			'he' => 'Hebrew',
			'hi' => 'Hindi',
			'ja' => 'Japanese',
			'ko' => 'Korean',
			'zh' => 'Chinese (Simplified)',
			'id' => 'Indonesian',
			'th' => 'Thai',
			'vi' => 'Vietnamese',
			'el' => 'Greek',
			'ro' => 'Romanian',
			'hu' => 'Hungarian',
		);
		$short = strtolower( substr( $code, 0, 2 ) );
		return isset( $map[ $short ] ) ? $map[ $short ] : 'English';
	}

	/**
	 * Supported languages for UI pickers.
	 *
	 * @return array [ { value, label } ]
	 */
	public static function languages() {
		$out = array();
		foreach ( array( 'en', 'de', 'fr', 'es', 'it', 'pt', 'nl', 'pl', 'sv', 'da', 'fi', 'nb', 'cs', 'tr', 'ru', 'uk', 'ar', 'he', 'hi', 'ja', 'ko', 'zh', 'id', 'th', 'vi', 'el', 'ro', 'hu' ) as $code ) {
			$out[] = array(
				'value' => $code,
				'label' => self::language_name( $code ),
			);
		}
		return $out;
	}
}
