<?php
/**
 * Form definition: the JSON document behind every form (steps → fields, settings, messages,
 * integrations, payments, protection, translations). normalize() is the single gate for
 * everything that writes a definition (builder saves, templates, imports and AI drafts) and
 * reports every automatic repair in $fixes.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Forms;

use Dev3\AiFormBuilder\Engine\Formula;
use Dev3\AiFormBuilder\Engine\Logic;

defined( 'ABSPATH' ) || exit;

/**
 * Definition helpers.
 */
class Definition {

	const VERSION = 1;

	/**
	 * Type aliases the AI (or imports) may use.
	 */
	const ALIASES = array(
		'string'          => 'text',
		'short_text'      => 'text',
		'single_line'     => 'text',
		'input'           => 'text',
		'long_text'       => 'textarea',
		'paragraph_text'  => 'textarea',
		'message'         => 'textarea',
		'multiline'       => 'textarea',
		'dropdown'        => 'select',
		'choice'          => 'radio',
		'multiple_choice' => 'radio',
		'single_choice'   => 'radio',
		'checkboxes'      => 'checkbox',
		'multi_choice'    => 'checkbox',
		'multiple_select' => 'multiselect',
		'tel'             => 'phone',
		'telephone'       => 'phone',
		'phone_number'    => 'phone',
		'website'         => 'url',
		'link'            => 'url',
		'integer'         => 'number',
		'decimal'         => 'number',
		'upload'          => 'file',
		'file_upload'     => 'file',
		'attachment'      => 'file',
		'stars'           => 'rating',
		'star_rating'     => 'rating',
		'likert'          => 'scale',
		'opinion_scale'   => 'scale',
		'slider'          => 'range',
		'yes_no'          => 'toggle',
		'boolean'         => 'toggle',
		'switch'          => 'toggle',
		'gdpr'            => 'consent',
		'terms'           => 'consent',
		'agreement'       => 'consent',
		'datetime'        => 'date',
		'full_name'       => 'name',
		'section'         => 'heading',
		'title'           => 'heading',
		'html'            => 'paragraph',
		'text_block'      => 'paragraph',
		'separator'       => 'divider',
		'price'           => 'product',
		'donation'        => 'amount',
		'formula'         => 'calculation',
		'calculated'      => 'calculation',
		'order_total'     => 'total',
		'colour'          => 'color',
	);

	/**
	 * Field type registry (shared with the builder through field-types.json).
	 *
	 * @var array|null
	 */
	private static $types = null;

	/**
	 * Field types.
	 *
	 * @return array type => meta
	 */
	public static function types() {
		if ( null === self::$types ) {
			$file = __DIR__ . '/field-types.json';
			$json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			/**
			 * Filters the field type registry.
			 *
			 * @param array $types Types.
			 */
			self::$types = (array) apply_filters( 'dev3fb_field_types', is_array( $json ) ? $json : array() );
		}
		return self::$types;
	}

	/**
	 * Value kind of a type.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	public static function kind( $type ) {
		$types = self::types();
		return isset( $types[ $type ]['kind'] ) ? $types[ $type ]['kind'] : 'string';
	}

	/**
	 * Whether a type has options.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function has_options( $type ) {
		$types = self::types();
		return ! empty( $types[ $type ]['options'] ) || 'product' === $type;
	}

	/**
	 * Blank definition.
	 *
	 * @return array
	 */
	public static function blank() {
		return self::normalize(
			array(
				'steps' => array(
					array(
						'id'     => 'step_1',
						'title'  => '',
						'fields' => array(),
					),
				),
			)
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'description'    => '',
			'submit_label'   => __( 'Submit', 'dev3-ai-form-builder' ),
			'next_label'     => __( 'Next', 'dev3-ai-form-builder' ),
			'prev_label'     => __( 'Back', 'dev3-ai-form-builder' ),
			'progress'       => 'bar',
			'save_continue'  => false,
			'save_label'     => __( 'Save and continue later', 'dev3-ai-form-builder' ),
			'limit_entries'  => 0,
			'open_at'        => '',
			'close_at'       => '',
			'closed_message' => __( 'This form is closed.', 'dev3-ai-form-builder' ),
			'require_login'  => false,
			'login_message'  => __( 'Please log in to fill in this form.', 'dev3-ai-form-builder' ),
			'store_entries'  => true,
			'accent'         => '',
			'css_class'      => '',
			'show_title'     => false,
		);
	}

	/**
	 * Default protection settings.
	 *
	 * @return array
	 */
	public static function default_protection() {
		return array(
			'honeypot'       => true,
			'min_seconds'    => 2,
			'rate_limit'     => 6,
			'ai_spam'        => false,
			'spam_threshold' => 80,
			'spam_action'    => 'spam',
			'block_abusive'  => true,
			'paste_fill'     => false,
		);
	}

	/**
	 * Flat field list (form order).
	 *
	 * @param array $definition Definition.
	 * @return array
	 */
	public static function fields( array $definition ) {
		$out = array();
		foreach ( isset( $definition['steps'] ) ? (array) $definition['steps'] : array() as $step ) {
			foreach ( isset( $step['fields'] ) ? (array) $step['fields'] : array() as $field ) {
				$out[] = $field;
			}
		}
		return $out;
	}

	/**
	 * Finds a field.
	 *
	 * @param array  $definition Definition.
	 * @param string $id         Field id.
	 * @return array|null
	 */
	public static function field( array $definition, $id ) {
		foreach ( self::fields( $definition ) as $field ) {
			if ( $field['id'] === $id ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * Fields that hold input (not layout/computed).
	 *
	 * @param array $definition Definition.
	 * @param bool  $computed   Include calculation/total.
	 * @return array
	 */
	public static function input_fields( array $definition, $computed = true ) {
		return array_values(
			array_filter(
				self::fields( $definition ),
				static function ( $field ) use ( $computed ) {
					$kind = self::kind( $field['type'] );
					return 'content' !== $kind && ( $computed || 'computed' !== $kind );
				}
			)
		);
	}

	/**
	 * First email field id ('' when none).
	 *
	 * @param array $definition Definition.
	 * @return string
	 */
	public static function email_field( array $definition ) {
		foreach ( self::fields( $definition ) as $field ) {
			if ( 'email' === $field['type'] ) {
				return $field['id'];
			}
		}
		return '';
	}

	/**
	 * Slug for ids.
	 *
	 * @param string $text   Text.
	 * @param string $prefix Prefix when the slug would start with a digit.
	 * @return string
	 */
	public static function slug( $text, $prefix = 'field' ) {
		$slug = strtolower( remove_accents( (string) $text ) );
		$slug = trim( preg_replace( '/[^a-z0-9]+/', '_', $slug ), '_' );
		$slug = substr( $slug, 0, 40 );
		$slug = rtrim( $slug, '_' );
		if ( '' === $slug || ! ctype_alpha( $slug[0] ) ) {
			$slug = $prefix . ( '' === $slug ? '' : '_' . $slug );
		}
		return substr( $slug, 0, 40 );
	}

	/**
	 * Normalizes and repairs a definition.
	 *
	 * @param mixed      $raw   Raw definition (array or JSON string).
	 * @param array|null $fixes Receives human-readable notes about automatic repairs.
	 * @return array
	 */
	public static function normalize( $raw, &$fixes = null ) {
		$fixes = array();
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		$raw = is_array( $raw ) ? $raw : array();

		$steps_in = isset( $raw['steps'] ) && is_array( $raw['steps'] ) ? array_values( $raw['steps'] ) : array();
		if ( ! $steps_in && isset( $raw['fields'] ) && is_array( $raw['fields'] ) ) {
			$steps_in = array(
				array(
					'id'     => 'step_1',
					'fields' => $raw['fields'],
				),
			);
		}
		if ( ! $steps_in ) {
			$steps_in = array( array( 'id' => 'step_1' ) );
		}

		$ids      = array();
		$step_ids = array();
		$steps    = array();
		foreach ( $steps_in as $index => $step_raw ) {
			$step_raw = is_array( $step_raw ) ? $step_raw : array();
			$sid      = self::unique( self::slug( isset( $step_raw['id'] ) && '' !== $step_raw['id'] ? (string) $step_raw['id'] : 'step_' . ( $index + 1 ), 'step' ), $step_ids );
			$fields   = array();
			foreach ( isset( $step_raw['fields'] ) && is_array( $step_raw['fields'] ) ? $step_raw['fields'] : array() as $field_raw ) {
				$field = self::normalize_field( $field_raw, $ids, $fixes );
				if ( $field ) {
					$fields[] = $field;
				}
			}
			$steps[] = array(
				'id'          => $sid,
				'title'       => self::text( isset( $step_raw['title'] ) ? $step_raw['title'] : '', 120 ),
				'description' => self::text( isset( $step_raw['description'] ) ? $step_raw['description'] : '', 300 ),
				'logic'       => isset( $step_raw['logic'] ) ? $step_raw['logic'] : null,
				'fields'      => $fields,
			);
		}
		// Drop empty steps (keep at least one).
		$non_empty = array_values(
			array_filter(
				$steps,
				static function ( $s ) {
					return (bool) $s['fields'];
				}
			)
		);
		if ( count( $non_empty ) !== count( $steps ) && $non_empty ) {
			$fixes[] = __( 'Removed empty steps.', 'dev3-ai-form-builder' );
			$steps   = $non_empty;
		}

		$definition = array(
			'version' => self::VERSION,
			'steps'   => $steps,
		);
		$known      = array();
		foreach ( self::fields( $definition ) as $field ) {
			$known[ $field['id'] ] = $field;
		}

		// Second pass: logic, formulas and links can only point to fields that exist (and come earlier).
		$seen = array();
		foreach ( $definition['steps'] as $s => $step ) {
			$definition['steps'][ $s ]['logic'] = self::normalize_logic( $step['logic'], $seen, '', $fixes, $step['title'] );
			foreach ( $step['fields'] as $f => $field ) {
				$definition['steps'][ $s ]['fields'][ $f ]['logic'] = self::normalize_logic( $field['logic'], $seen, $field['id'], $fixes, $field['label'] );
				if ( 'calculation' === $field['type'] && '' !== $field['formula'] ) {
					$tokens = Formula::tokenize( $field['formula'] );
					$bad    = array_diff( Formula::references( $field['formula'] ), array_merge( array_keys( $known ), array( '_subtotal' ) ) );
					if ( is_wp_error( $tokens ) || $bad || is_wp_error( Formula::evaluate( $field['formula'], array() ) ) ) {
						/* translators: %s: field label */
						$fixes[] = sprintf( __( 'Cleared an invalid formula in "%s".', 'dev3-ai-form-builder' ), $field['label'] );
						$definition['steps'][ $s ]['fields'][ $f ]['formula'] = '';
					}
				}
				if ( 'quantity' === $field['type'] && '' !== $field['product'] && ( ! isset( $known[ $field['product'] ] ) || 'product' !== $known[ $field['product'] ]['type'] ) ) {
					$definition['steps'][ $s ]['fields'][ $f ]['product'] = '';
				}
				$seen[ $field['id'] ] = $field;
			}
		}

		$definition['settings']      = self::normalize_settings( isset( $raw['settings'] ) ? $raw['settings'] : array() );
		$definition['confirmations'] = self::normalize_confirmations( isset( $raw['confirmations'] ) ? $raw['confirmations'] : array(), $known );
		$definition['notifications'] = self::normalize_notifications( isset( $raw['notifications'] ) ? $raw['notifications'] : null, $known );
		$definition['webhooks']      = self::normalize_webhooks( isset( $raw['webhooks'] ) ? $raw['webhooks'] : array(), $known );
		$definition['integrations']  = self::normalize_integrations( isset( $raw['integrations'] ) ? $raw['integrations'] : array(), $known );
		$definition['payments']      = self::normalize_payments( isset( $raw['payments'] ) ? $raw['payments'] : array(), $known );
		$definition['protection']    = self::normalize_protection( isset( $raw['protection'] ) ? $raw['protection'] : array(), $known );
		$definition['translations']  = self::normalize_translations( isset( $raw['translations'] ) ? $raw['translations'] : array() );
		return $definition;
	}

	/**
	 * Unique id within a set.
	 *
	 * @param string $id   Candidate.
	 * @param array  $used Used ids (by reference).
	 * @return string
	 */
	private static function unique( $id, array &$used ) {
		$base = $id;
		$n    = 2;
		while ( isset( $used[ $id ] ) ) {
			$id = substr( $base, 0, 36 ) . '_' . $n;
			++$n;
		}
		$used[ $id ] = true;
		return $id;
	}

	/**
	 * Plain text, trimmed and clipped.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max length.
	 * @return string
	 */
	public static function text( $value, $max = 200 ) {
		return is_scalar( $value ) ? mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, $max ) : '';
	}

	/**
	 * Limited HTML (messages, paragraph blocks, consent text).
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max length.
	 * @return string
	 */
	public static function html( $value, $max = 5000 ) {
		return is_scalar( $value ) ? mb_substr( trim( wp_kses_post( (string) $value ) ), 0, $max ) : '';
	}

	/**
	 * Number or ''.
	 *
	 * @param mixed $value Value.
	 * @return float|string
	 */
	private static function num( $value ) {
		return is_numeric( $value ) ? 0 + $value : '';
	}

	/**
	 * Resolves a raw type (aliases, unknown → text).
	 *
	 * @param string     $type  Raw type.
	 * @param array|null $fixes Fixes.
	 * @param string     $label Label.
	 * @return string
	 */
	public static function resolve_type( $type, &$fixes, $label ) {
		$type  = strtolower( trim( (string) $type ) );
		$type  = str_replace( array( '-', ' ' ), '_', $type );
		$types = self::types();
		if ( isset( $types[ $type ] ) ) {
			return $type;
		}
		if ( isset( self::ALIASES[ $type ] ) ) {
			return self::ALIASES[ $type ];
		}
		/* translators: 1: field label, 2: unknown type */
		$fixes[] = sprintf( __( 'Changed "%1$s" from unknown type "%2$s" to single line text.', 'dev3-ai-form-builder' ), $label, $type );
		return 'text';
	}

	/**
	 * Normalizes one field.
	 *
	 * @param mixed      $raw   Raw field.
	 * @param array      $ids   Used ids (by reference).
	 * @param array|null $fixes Fixes (by reference).
	 * @return array|null
	 */
	public static function normalize_field( $raw, array &$ids, &$fixes ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$label = self::text( isset( $raw['label'] ) ? $raw['label'] : '', 200 );
		$type  = self::resolve_type( isset( $raw['type'] ) ? $raw['type'] : 'text', $fixes, $label );
		if ( '' === $label && ! in_array( $type, array( 'divider', 'paragraph', 'hidden' ), true ) ) {
			$types = self::types();
			$label = isset( $types[ $type ]['label'] ) ? $types[ $type ]['label'] : ucfirst( $type );
		}
		$raw_id = isset( $raw['id'] ) && '' !== (string) $raw['id'] ? (string) $raw['id'] : ( isset( $raw['key'] ) && '' !== (string) $raw['key'] ? (string) $raw['key'] : ( '' !== $label ? $label : $type ) );
		$id     = self::unique( self::slug( $raw_id ), $ids );
		$width  = isset( $raw['width'] ) && in_array( $raw['width'], array( 'full', 'half', 'third' ), true ) ? $raw['width'] : 'full';

		$field = array(
			'id'          => $id,
			'type'        => $type,
			'label'       => $label,
			'placeholder' => self::text( isset( $raw['placeholder'] ) ? $raw['placeholder'] : '', 200 ),
			'help'        => self::text( isset( $raw['help'] ) ? $raw['help'] : '', 500 ),
			'required'    => ! empty( $raw['required'] ) && 'content' !== self::kind( $type ) && 'computed' !== self::kind( $type ),
			'width'       => $width,
			'hide_label'  => ! empty( $raw['hide_label'] ),
			'default'     => self::text( isset( $raw['default'] ) ? ( is_array( $raw['default'] ) ? implode( ',', array_filter( $raw['default'], 'is_scalar' ) ) : $raw['default'] ) : '', 500 ),
			'css'         => implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', isset( $raw['css'] ) && is_string( $raw['css'] ) ? $raw['css'] : '', -1, PREG_SPLIT_NO_EMPTY ) ) ),
			'role'        => isset( $raw['role'] ) ? sanitize_key( (string) $raw['role'] ) : '',
			'logic'       => isset( $raw['logic'] ) ? $raw['logic'] : null,
		);

		if ( self::has_options( $type ) || 'country' === $type ) {
			$field['options'] = self::normalize_options( isset( $raw['options'] ) ? $raw['options'] : array(), $type );
			if ( ! $field['options'] && 'country' !== $type && 'product' !== $type ) {
				/* translators: %s: field label */
				$fixes[]          = sprintf( __( 'Added placeholder options to "%s".', 'dev3-ai-form-builder' ), $label );
				$field['options'] = self::normalize_options( array( __( 'Option 1', 'dev3-ai-form-builder' ), __( 'Option 2', 'dev3-ai-form-builder' ) ), $type );
			}
			$field['layout'] = isset( $raw['layout'] ) && in_array( $raw['layout'], array( 'stacked', 'inline', 'grid' ), true ) ? $raw['layout'] : 'stacked';
		}
		switch ( $type ) {
			case 'text':
			case 'textarea':
			case 'password':
				$field['maxlength'] = isset( $raw['maxlength'] ) ? max( 0, min( 20000, (int) $raw['maxlength'] ) ) : 0;
				if ( 'textarea' === $type ) {
					$field['rows'] = isset( $raw['rows'] ) ? max( 2, min( 20, (int) $raw['rows'] ) ) : 4;
				}
				break;
			case 'number':
			case 'range':
			case 'quantity':
			case 'amount':
				$field['min']  = self::num( isset( $raw['min'] ) ? $raw['min'] : ( in_array( $type, array( 'quantity', 'amount' ), true ) ? 0 : '' ) );
				$field['max']  = self::num( isset( $raw['max'] ) ? $raw['max'] : ( 'range' === $type ? 100 : '' ) );
				$field['step'] = self::num( isset( $raw['step'] ) ? $raw['step'] : ( 'amount' === $type ? 0.01 : 1 ) );
				if ( '' === $field['step'] || $field['step'] <= 0 ) {
					$field['step'] = 1;
				}
				if ( 'range' === $type && '' === $field['min'] ) {
					$field['min'] = 0;
				}
				$field['prefix'] = self::text( isset( $raw['prefix'] ) ? $raw['prefix'] : '', 10 );
				$field['suffix'] = self::text( isset( $raw['suffix'] ) ? $raw['suffix'] : '', 10 );
				if ( 'quantity' === $type ) {
					$field['product'] = isset( $raw['product'] ) ? self::slug( (string) $raw['product'] ) : '';
				}
				break;
			case 'rating':
				$field['max'] = isset( $raw['max'] ) ? max( 3, min( 10, (int) $raw['max'] ) ) : 5;
				break;
			case 'scale':
				$field['min']       = isset( $raw['min'] ) ? max( 0, min( 1, (int) $raw['min'] ) ) : 1;
				$field['max']       = isset( $raw['max'] ) ? max( 3, min( 10, (int) $raw['max'] ) ) : 5;
				$field['min_label'] = self::text( isset( $raw['min_label'] ) ? $raw['min_label'] : '', 40 );
				$field['max_label'] = self::text( isset( $raw['max_label'] ) ? $raw['max_label'] : '', 40 );
				break;
			case 'nps':
				$field['min_label'] = self::text( isset( $raw['min_label'] ) ? $raw['min_label'] : __( 'Not likely', 'dev3-ai-form-builder' ), 40 );
				$field['max_label'] = self::text( isset( $raw['max_label'] ) ? $raw['max_label'] : __( 'Very likely', 'dev3-ai-form-builder' ), 40 );
				break;
			case 'date':
				$field['min_date'] = isset( $raw['min_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $raw['min_date'] ) ? $raw['min_date'] : '';
				$field['max_date'] = isset( $raw['max_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $raw['max_date'] ) ? $raw['max_date'] : '';
				break;
			case 'file':
				$accept             = isset( $raw['accept'] ) ? (string) $raw['accept'] : 'pdf,jpg,jpeg,png,doc,docx';
				$exts               = array_filter( array_map( 'sanitize_key', preg_split( '/[\s,.]+/', strtolower( $accept ) ) ) );
				$field['accept']    = implode( ',', array_slice( array_unique( $exts ), 0, 30 ) );
				$field['max_size']  = isset( $raw['max_size'] ) ? (float) max( 0.1, min( 512, (float) $raw['max_size'] ) ) : 5.0;
				$field['max_files'] = isset( $raw['max_files'] ) ? max( 1, min( 20, (int) $raw['max_files'] ) ) : 1;
				break;
			case 'calculation':
				$field['formula']      = isset( $raw['formula'] ) && is_string( $raw['formula'] ) ? mb_substr( preg_replace( '/[^\w\s{}\-+*\/%().,<>=!]/u', '', $raw['formula'] ), 0, 500 ) : '';
				$field['decimals']     = isset( $raw['decimals'] ) ? max( 0, min( 6, (int) $raw['decimals'] ) ) : 2;
				$field['prefix']       = self::text( isset( $raw['prefix'] ) ? $raw['prefix'] : '', 10 );
				$field['suffix']       = self::text( isset( $raw['suffix'] ) ? $raw['suffix'] : '', 10 );
				$field['add_to_total'] = ! empty( $raw['add_to_total'] );
				$field['show']         = ! isset( $raw['show'] ) || ! empty( $raw['show'] );
				break;
			case 'total':
			case 'product':
				$field['price'] = isset( $raw['price'] ) && is_numeric( $raw['price'] ) ? round( max( 0, (float) $raw['price'] ), 2 ) : 0.0;
				break;
			case 'heading':
				$field['level'] = isset( $raw['level'] ) && in_array( (int) $raw['level'], array( 2, 3, 4 ), true ) ? (int) $raw['level'] : 3;
				break;
			case 'paragraph':
				$field['content'] = self::html( isset( $raw['content'] ) ? $raw['content'] : ( isset( $raw['help'] ) ? $raw['help'] : '' ) );
				break;
			case 'consent':
				$field['content'] = self::html( isset( $raw['content'] ) ? $raw['content'] : '', 1000 );
				break;
			case 'toggle':
				$field['on_label']  = self::text( isset( $raw['on_label'] ) ? $raw['on_label'] : __( 'Yes', 'dev3-ai-form-builder' ), 40 );
				$field['off_label'] = self::text( isset( $raw['off_label'] ) ? $raw['off_label'] : __( 'No', 'dev3-ai-form-builder' ), 40 );
				break;
		}
		return $field;
	}

	/**
	 * Options: strings or { label, value, price, image }.
	 *
	 * @param mixed  $raw  Raw options.
	 * @param string $type Field type.
	 * @return array
	 */
	public static function normalize_options( $raw, $type ) {
		$out  = array();
		$seen = array();
		foreach ( is_array( $raw ) ? $raw : array() as $option ) {
			if ( is_scalar( $option ) ) {
				$option = array( 'label' => (string) $option );
			}
			if ( ! is_array( $option ) ) {
				continue;
			}
			$label = self::text( isset( $option['label'] ) ? $option['label'] : ( isset( $option['value'] ) ? $option['value'] : '' ), 200 );
			$value = self::text( isset( $option['value'] ) && '' !== (string) $option['value'] ? $option['value'] : $label, 200 );
			if ( '' === $label || isset( $seen[ strtolower( $value ) ] ) ) {
				continue;
			}
			$seen[ strtolower( $value ) ] = true;
			$item                         = array(
				'label' => $label,
				'value' => $value,
			);
			if ( isset( $option['price'] ) && is_numeric( $option['price'] ) ) {
				$item['price'] = round( (float) $option['price'], 2 );
			} elseif ( 'product' === $type ) {
				$item['price'] = 0.0;
			}
			if ( 'image_choice' === $type ) {
				$item['image'] = isset( $option['image'] ) ? esc_url_raw( (string) $option['image'] ) : '';
			}
			$out[] = $item;
			if ( count( $out ) >= 200 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Logic: only rules that reference earlier fields.
	 *
	 * @param mixed      $raw     Raw logic.
	 * @param array      $earlier Fields that come before (id => field).
	 * @param string     $self_id Own id.
	 * @param array|null $fixes   Fixes.
	 * @param string     $label   Label for messages.
	 * @return array|null
	 */
	public static function normalize_logic( $raw, array $earlier, $self_id, &$fixes, $label = '' ) {
		if ( ! is_array( $raw ) || empty( $raw['rules'] ) || ! is_array( $raw['rules'] ) ) {
			return null;
		}
		$rules   = array();
		$dropped = 0;
		foreach ( $raw['rules'] as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$field = isset( $rule['field'] ) ? self::slug( (string) $rule['field'] ) : '';
			$op    = isset( $rule['op'] ) ? (string) $rule['op'] : ( isset( $rule['operator'] ) ? (string) $rule['operator'] : 'is' );
			$op    = str_replace( array( 'equals', 'not_equals', 'greater_than', 'less_than', 'is_empty', 'is_not_empty' ), array( 'is', 'is_not', 'gt', 'lt', 'empty', 'not_empty' ), strtolower( $op ) );
			if ( '' === $field || $field === $self_id || ! isset( $earlier[ $field ] ) || ! in_array( $op, Logic::OPS, true ) ) {
				++$dropped;
				continue;
			}
			$rules[] = array(
				'field' => $field,
				'op'    => $op,
				'value' => self::text( isset( $rule['value'] ) ? ( is_bool( $rule['value'] ) ? ( $rule['value'] ? '1' : '' ) : $rule['value'] ) : '', 200 ),
			);
		}
		if ( $dropped ) {
			/* translators: 1: number of rules, 2: label */
			$fixes[] = sprintf( _n( 'Removed %1$d logic rule on "%2$s" that pointed to a missing or later field.', 'Removed %1$d logic rules on "%2$s" that pointed to missing or later fields.', $dropped, 'dev3-ai-form-builder' ), $dropped, $label );
		}
		if ( ! $rules ) {
			return null;
		}
		return array(
			'enabled' => ! isset( $raw['enabled'] ) || ! empty( $raw['enabled'] ),
			'action'  => isset( $raw['action'] ) && 'hide' === $raw['action'] ? 'hide' : 'show',
			'match'   => isset( $raw['match'] ) && 'any' === $raw['match'] ? 'any' : 'all',
			'rules'   => array_slice( $rules, 0, 20 ),
		);
	}

	/**
	 * Logic for messages/notifications (any field may be referenced).
	 *
	 * @param mixed $raw   Raw.
	 * @param array $known Known fields.
	 * @return array|null
	 */
	private static function message_logic( $raw, array $known ) {
		$fixes = array();
		return self::normalize_logic( $raw, $known, '', $fixes );
	}

	/**
	 * Settings.
	 *
	 * @param mixed $raw Raw.
	 * @return array
	 */
	private static function normalize_settings( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$d   = self::default_settings();
		$out = array();
		foreach ( array( 'submit_label', 'next_label', 'prev_label', 'save_label' ) as $key ) {
			$out[ $key ] = isset( $raw[ $key ] ) && '' !== trim( (string) $raw[ $key ] ) ? self::text( $raw[ $key ], 60 ) : $d[ $key ];
		}
		$out['description']    = self::html( isset( $raw['description'] ) ? $raw['description'] : '', 2000 );
		$out['progress']       = isset( $raw['progress'] ) && in_array( $raw['progress'], array( 'bar', 'steps', 'none' ), true ) ? $raw['progress'] : 'bar';
		$out['save_continue']  = ! empty( $raw['save_continue'] );
		$out['limit_entries']  = isset( $raw['limit_entries'] ) ? max( 0, (int) $raw['limit_entries'] ) : 0;
		$out['open_at']        = isset( $raw['open_at'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', (string) $raw['open_at'] ) ? (string) $raw['open_at'] : '';
		$out['close_at']       = isset( $raw['close_at'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', (string) $raw['close_at'] ) ? (string) $raw['close_at'] : '';
		$out['closed_message'] = isset( $raw['closed_message'] ) && '' !== trim( (string) $raw['closed_message'] ) ? self::text( $raw['closed_message'], 300 ) : $d['closed_message'];
		$out['require_login']  = ! empty( $raw['require_login'] );
		$out['login_message']  = isset( $raw['login_message'] ) && '' !== trim( (string) $raw['login_message'] ) ? self::text( $raw['login_message'], 300 ) : $d['login_message'];
		$out['store_entries']  = ! isset( $raw['store_entries'] ) || ! empty( $raw['store_entries'] );
		$accent                = isset( $raw['accent'] ) ? sanitize_hex_color( (string) $raw['accent'] ) : '';
		$out['accent']         = $accent ? $accent : '';
		$out['css_class']      = isset( $raw['css_class'] ) ? implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $raw['css_class'], -1, PREG_SPLIT_NO_EMPTY ) ) ) : '';
		$out['show_title']     = ! empty( $raw['show_title'] );
		return $out;
	}

	/**
	 * Confirmations (at least one).
	 *
	 * @param mixed $raw   Raw.
	 * @param array $known Known fields.
	 * @return array
	 */
	private static function normalize_confirmations( $raw, array $known ) {
		$out  = array();
		$used = array();
		foreach ( is_array( $raw ) ? $raw : array() as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type  = isset( $item['type'] ) && in_array( $item['type'], array( 'message', 'redirect', 'page' ), true ) ? $item['type'] : 'message';
			$out[] = array(
				'id'      => self::unique( self::slug( isset( $item['id'] ) ? (string) $item['id'] : 'confirmation_' . ( $i + 1 ), 'confirmation' ), $used ),
				'name'    => self::text( isset( $item['name'] ) ? $item['name'] : __( 'Default confirmation', 'dev3-ai-form-builder' ), 80 ),
				'type'    => $type,
				'message' => self::html( isset( $item['message'] ) ? $item['message'] : '' ),
				'url'     => isset( $item['url'] ) ? self::url_with_tags( (string) $item['url'] ) : '',
				'page_id' => isset( $item['page_id'] ) ? absint( $item['page_id'] ) : 0,
				'logic'   => self::message_logic( isset( $item['logic'] ) ? $item['logic'] : null, $known ),
			);
		}
		if ( ! $out ) {
			$out[] = array(
				'id'      => 'confirmation_1',
				'name'    => __( 'Default confirmation', 'dev3-ai-form-builder' ),
				'type'    => 'message',
				'message' => __( 'Thanks! Your submission has been received.', 'dev3-ai-form-builder' ),
				'url'     => '',
				'page_id' => 0,
				'logic'   => null,
			);
		}
		return array_slice( $out, 0, 20 );
	}

	/**
	 * Redirect URL that may contain merge tags (escaped again after merging).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function url_with_tags( $url ) {
		$url = trim( $url );
		if ( false === strpos( $url, '{' ) ) {
			return esc_url_raw( $url, array( 'http', 'https' ) );
		}
		return preg_match( '#^(https?://|/)#i', $url ) ? mb_substr( sanitize_text_field( $url ), 0, 2000 ) : '';
	}

	/**
	 * Notifications (null = create the default admin email).
	 *
	 * @param mixed $raw   Raw.
	 * @param array $known Known fields.
	 * @return array
	 */
	private static function normalize_notifications( $raw, array $known ) {
		if ( null === $raw ) {
			$raw = array(
				array(
					'name'    => __( 'Admin notification', 'dev3-ai-form-builder' ),
					'to'      => '{admin_email}',
					'subject' => __( 'New entry: {form_title}', 'dev3-ai-form-builder' ),
					'message' => '{all_fields}',
				),
			);
		}
		$out  = array();
		$used = array();
		foreach ( is_array( $raw ) ? $raw : array() as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$out[] = array(
				'id'        => self::unique( self::slug( isset( $item['id'] ) ? (string) $item['id'] : 'notification_' . ( $i + 1 ), 'notification' ), $used ),
				'name'      => self::text( isset( $item['name'] ) ? $item['name'] : __( 'Notification', 'dev3-ai-form-builder' ), 80 ),
				'enabled'   => ! isset( $item['enabled'] ) || ! empty( $item['enabled'] ),
				'to'        => self::text( isset( $item['to'] ) ? $item['to'] : '{admin_email}', 500 ),
				'cc'        => self::text( isset( $item['cc'] ) ? $item['cc'] : '', 500 ),
				'bcc'       => self::text( isset( $item['bcc'] ) ? $item['bcc'] : '', 500 ),
				'from_name' => self::text( isset( $item['from_name'] ) ? $item['from_name'] : '', 100 ),
				'reply_to'  => self::text( isset( $item['reply_to'] ) ? $item['reply_to'] : '', 200 ),
				'subject'   => self::text( isset( $item['subject'] ) ? $item['subject'] : '', 200 ),
				'message'   => self::html( isset( $item['message'] ) ? $item['message'] : '{all_fields}', 20000 ),
				'attach'    => ! empty( $item['attach'] ),
				'logic'     => self::message_logic( isset( $item['logic'] ) ? $item['logic'] : null, $known ),
			);
		}
		return array_slice( $out, 0, 20 );
	}

	/**
	 * Webhooks.
	 *
	 * @param mixed $raw   Raw.
	 * @param array $known Known fields.
	 * @return array
	 */
	private static function normalize_webhooks( $raw, array $known ) {
		$out  = array();
		$used = array();
		foreach ( is_array( $raw ) ? $raw : array() as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$url   = isset( $item['url'] ) ? esc_url_raw( (string) $item['url'], array( 'http', 'https' ) ) : '';
			$out[] = array(
				'id'      => self::unique( self::slug( isset( $item['id'] ) ? (string) $item['id'] : 'webhook_' . ( $i + 1 ), 'webhook' ), $used ),
				'name'    => self::text( isset( $item['name'] ) ? $item['name'] : __( 'Webhook', 'dev3-ai-form-builder' ), 80 ),
				'enabled' => ! empty( $item['enabled'] ) && '' !== $url,
				'url'     => $url,
				'format'  => isset( $item['format'] ) && 'form' === $item['format'] ? 'form' : 'json',
				'secret'  => self::text( isset( $item['secret'] ) ? $item['secret'] : '', 120 ),
				'logic'   => self::message_logic( isset( $item['logic'] ) ? $item['logic'] : null, $known ),
			);
		}
		return array_slice( $out, 0, 20 );
	}

	/**
	 * Field id that must exist ('' otherwise).
	 *
	 * @param mixed $id    Raw id.
	 * @param array $known Known fields.
	 * @return string
	 */
	private static function ref( $id, array $known ) {
		$id = is_scalar( $id ) ? (string) $id : '';
		return '' !== $id && isset( $known[ $id ] ) ? $id : '';
	}

	/**
	 * Integrations.
	 *
	 * @param mixed $raw   Raw.
	 * @param array $known Known fields.
	 * @return array
	 */
	private static function normalize_integrations( $raw, array $known ) {
		$raw      = is_array( $raw ) ? $raw : array();
		$out      = array( 'consent_field' => self::ref( isset( $raw['consent_field'] ) ? $raw['consent_field'] : '', $known ) );
		$map_keys = array(
			'mailchimp' => array( 'email', 'first_name', 'last_name', 'phone' ),
			'brevo'     => array( 'email', 'first_name', 'last_name', 'phone' ),
			'hubspot'   => array( 'email', 'first_name', 'last_name', 'phone', 'company', 'website', 'message' ),
		);
		foreach ( $map_keys as $service => $keys ) {
			$item = isset( $raw[ $service ] ) && is_array( $raw[ $service ] ) ? $raw[ $service ] : array();
			$map  = array();
			foreach ( $keys as $key ) {
				$map[ $key ] = self::ref( isset( $item['map'][ $key ] ) ? $item['map'][ $key ] : '', $known );
			}
			$out[ $service ] = array(
				'enabled' => ! empty( $item['enabled'] ),
				'list_id' => self::text( isset( $item['list_id'] ) ? $item['list_id'] : '', 80 ),
				'tags'    => self::text( isset( $item['tags'] ) ? $item['tags'] : '', 200 ),
				'double'  => ! empty( $item['double'] ),
				'map'     => $map,
			);
		}
		foreach ( array( 'sheets', 'zapier' ) as $service ) {
			$item            = isset( $raw[ $service ] ) && is_array( $raw[ $service ] ) ? $raw[ $service ] : array();
			$url             = isset( $item['url'] ) ? esc_url_raw( (string) $item['url'], array( 'https' ) ) : '';
			$out[ $service ] = array(
				'enabled' => ! empty( $item['enabled'] ) && '' !== $url,
				'url'     => $url,
			);
		}
		return $out;
	}

	/**
	 * Payments.
	 *
	 * @param mixed $raw   Raw.
	 * @param array $known Known fields.
	 * @return array
	 */
	private static function normalize_payments( $raw, array $known ) {
		$raw       = is_array( $raw ) ? $raw : array();
		$providers = array_values( array_intersect( array( 'stripe', 'paypal' ), isset( $raw['providers'] ) && is_array( $raw['providers'] ) ? $raw['providers'] : array( 'stripe' ) ) );
		$currency  = isset( $raw['currency'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $raw['currency'] ) ) : 'USD';
		return array(
			'enabled'      => ! empty( $raw['enabled'] ) && (bool) $providers,
			'providers'    => $providers ? $providers : array( 'stripe' ),
			'currency'     => 3 === strlen( $currency ) ? $currency : 'USD',
			'description'  => self::text( isset( $raw['description'] ) ? $raw['description'] : '', 200 ),
			'amount_field' => self::ref( isset( $raw['amount_field'] ) ? $raw['amount_field'] : '', $known ),
		);
	}

	/**
	 * Protection.
	 *
	 * @param mixed $raw   Raw.
	 * @param array $known Known fields.
	 * @return array
	 */
	private static function normalize_protection( $raw, array $known ) {
		$raw = is_array( $raw ) ? $raw : array();
		$d   = self::default_protection();
		return array(
			'honeypot'       => isset( $raw['honeypot'] ) ? (bool) $raw['honeypot'] : $d['honeypot'],
			'min_seconds'    => isset( $raw['min_seconds'] ) ? max( 0, min( 60, (int) $raw['min_seconds'] ) ) : $d['min_seconds'],
			'rate_limit'     => isset( $raw['rate_limit'] ) ? max( 1, min( 120, (int) $raw['rate_limit'] ) ) : $d['rate_limit'],
			'ai_spam'        => ! empty( $raw['ai_spam'] ),
			'spam_threshold' => isset( $raw['spam_threshold'] ) ? max( 50, min( 99, (int) $raw['spam_threshold'] ) ) : $d['spam_threshold'],
			'spam_action'    => isset( $raw['spam_action'] ) && in_array( $raw['spam_action'], array( 'spam', 'block', 'flag' ), true ) ? $raw['spam_action'] : 'spam',
			'block_abusive'  => isset( $raw['block_abusive'] ) ? (bool) $raw['block_abusive'] : true,
			'paste_fill'     => ! empty( $raw['paste_fill'] ),
			'paste_source'   => self::ref( isset( $raw['paste_source'] ) ? $raw['paste_source'] : '', $known ),
		);
	}

	/**
	 * Translations: { lang: { key: text } }.
	 *
	 * @param mixed $raw Raw.
	 * @return array
	 */
	private static function normalize_translations( $raw ) {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $lang => $strings ) {
			$lang = self::lang_code( $lang );
			if ( '' === $lang || ! is_array( $strings ) ) {
				continue;
			}
			$clean = array();
			foreach ( $strings as $key => $text ) {
				if ( ! is_string( $key ) || ! preg_match( '/^[a-z_]+(\.[a-z0-9_\-]+){1,3}$/', $key ) || ! is_scalar( $text ) ) {
					continue;
				}
				$clean[ $key ] = self::is_html_key( $key ) ? self::html( $text, 5000 ) : self::text( $text, 500 );
			}
			$out[ $lang ] = $clean;
		}
		return $out;
	}

	/**
	 * Language code like "de" or "pt_br".
	 *
	 * @param mixed $lang Raw.
	 * @return string
	 */
	public static function lang_code( $lang ) {
		$lang = strtolower( str_replace( '-', '_', (string) $lang ) );
		return preg_match( '/^[a-z]{2,3}(_[a-z]{2,4})?$/', $lang ) ? $lang : '';
	}

	/**
	 * Translation keys that hold HTML.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_html_key( $key ) {
		return (bool) preg_match( '/\.(content|message|description)$/', $key );
	}

	/**
	 * Every translatable string as key => text.
	 *
	 * @param array $definition Definition.
	 * @return array
	 */
	public static function strings( array $definition ) {
		$out = array();
		foreach ( $definition['steps'] as $step ) {
			if ( '' !== $step['title'] ) {
				$out[ 'step.' . $step['id'] . '.title' ] = $step['title'];
			}
			if ( '' !== $step['description'] ) {
				$out[ 'step.' . $step['id'] . '.description' ] = $step['description'];
			}
			foreach ( $step['fields'] as $field ) {
				foreach ( array( 'label', 'placeholder', 'help', 'content', 'min_label', 'max_label', 'on_label', 'off_label' ) as $prop ) {
					if ( isset( $field[ $prop ] ) && '' !== $field[ $prop ] && ! ( 'label' === $prop && 'hidden' === $field['type'] ) ) {
						$out[ 'field.' . $field['id'] . '.' . $prop ] = $field[ $prop ];
					}
				}
				if ( ! empty( $field['options'] ) ) {
					foreach ( $field['options'] as $i => $option ) {
						$out[ 'field.' . $field['id'] . '.option_' . $i ] = $option['label'];
					}
				}
			}
		}
		foreach ( array( 'submit_label', 'next_label', 'prev_label', 'save_label', 'closed_message', 'login_message', 'description' ) as $key ) {
			if ( '' !== $definition['settings'][ $key ] ) {
				$out[ 'settings.' . $key ] = $definition['settings'][ $key ];
			}
		}
		foreach ( $definition['confirmations'] as $confirmation ) {
			if ( 'message' === $confirmation['type'] && '' !== $confirmation['message'] ) {
				$out[ 'confirmation.' . $confirmation['id'] . '.message' ] = $confirmation['message'];
			}
		}
		// Visitor-facing validation and UI messages, so translated forms speak one language.
		$messages = array_merge( \Dev3\AiFormBuilder\Submission\Validator::messages(), \Dev3\AiFormBuilder\Frontend\Renderer::frontend_messages() );
		foreach ( $messages as $key => $text ) {
			$out[ 'message.' . $key ] = $text;
		}
		return $out;
	}

	/**
	 * Applies a stored translation (visitor-facing strings only; option values stay the same).
	 *
	 * @param array  $definition Definition.
	 * @param string $lang       Language code.
	 * @return array
	 */
	public static function translate( array $definition, $lang ) {
		$lang = self::lang_code( $lang );
		if ( '' === $lang ) {
			return $definition;
		}
		$map = array();
		if ( isset( $definition['translations'][ $lang ] ) ) {
			$map = $definition['translations'][ $lang ];
		} else {
			$short = substr( $lang, 0, 2 );
			if ( isset( $definition['translations'][ $short ] ) ) {
				$map  = $definition['translations'][ $short ];
				$lang = $short;
			}
		}
		if ( ! $map ) {
			return $definition;
		}
		$t = static function ( $key, $fallback ) use ( $map ) {
			return isset( $map[ $key ] ) && '' !== $map[ $key ] ? $map[ $key ] : $fallback;
		};
		foreach ( $definition['steps'] as $s => $step ) {
			$definition['steps'][ $s ]['title']       = $t( 'step.' . $step['id'] . '.title', $step['title'] );
			$definition['steps'][ $s ]['description'] = $t( 'step.' . $step['id'] . '.description', $step['description'] );
			foreach ( $step['fields'] as $f => $field ) {
				foreach ( array( 'label', 'placeholder', 'help', 'content', 'min_label', 'max_label', 'on_label', 'off_label' ) as $prop ) {
					if ( isset( $field[ $prop ] ) ) {
						$definition['steps'][ $s ]['fields'][ $f ][ $prop ] = $t( 'field.' . $field['id'] . '.' . $prop, $field[ $prop ] );
					}
				}
				if ( ! empty( $field['options'] ) ) {
					foreach ( $field['options'] as $i => $option ) {
						$definition['steps'][ $s ]['fields'][ $f ]['options'][ $i ]['label'] = $t( 'field.' . $field['id'] . '.option_' . $i, $option['label'] );
					}
				}
			}
		}
		foreach ( array( 'submit_label', 'next_label', 'prev_label', 'save_label', 'closed_message', 'login_message', 'description' ) as $key ) {
			$definition['settings'][ $key ] = $t( 'settings.' . $key, $definition['settings'][ $key ] );
		}
		foreach ( $definition['confirmations'] as $c => $confirmation ) {
			$definition['confirmations'][ $c ]['message'] = $t( 'confirmation.' . $confirmation['id'] . '.message', $confirmation['message'] );
		}
		$definition['lang'] = $lang;
		return $definition;
	}
}
