<?php
/**
 * Server-side sanitising and validation of submitted values (never trusts the browser).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Submission;

use Dev3\AiFormBuilder\Forms\Countries;
use Dev3\AiFormBuilder\Forms\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Validator.
 */
class Validator {

	/**
	 * Validation messages (translatable per form through translations "message.*").
	 *
	 * @return array
	 */
	public static function messages() {
		return array(
			'required' => __( 'This field is required.', 'dev3-ai-form-builder' ),
			'email'    => __( 'Enter a valid email address.', 'dev3-ai-form-builder' ),
			'url'      => __( 'Enter a valid web address.', 'dev3-ai-form-builder' ),
			'phone'    => __( 'Enter a valid phone number.', 'dev3-ai-form-builder' ),
			'number'   => __( 'Enter a number.', 'dev3-ai-form-builder' ),
			'min'      => __( 'The value is too small.', 'dev3-ai-form-builder' ),
			'max'      => __( 'The value is too large.', 'dev3-ai-form-builder' ),
			'choice'   => __( 'Choose one of the options.', 'dev3-ai-form-builder' ),
			'date'     => __( 'Enter a valid date.', 'dev3-ai-form-builder' ),
			'time'     => __( 'Enter a valid time.', 'dev3-ai-form-builder' ),
			'length'   => __( 'The text is too long.', 'dev3-ai-form-builder' ),
			'consent'  => __( 'Please agree to continue.', 'dev3-ai-form-builder' ),
			'color'    => __( 'Choose a colour.', 'dev3-ai-form-builder' ),
		);
	}

	/**
	 * Option values of a field.
	 *
	 * @param array $field Field.
	 * @return string[]
	 */
	private static function option_values( array $field ) {
		if ( 'country' === $field['type'] ) {
			return array_map( 'strval', array_keys( Countries::all() ) );
		}
		return array_map( 'strval', wp_list_pluck( isset( $field['options'] ) ? $field['options'] : array(), 'value' ) );
	}

	/**
	 * Sanitizes one raw value (no validation).
	 *
	 * @param array $field Field.
	 * @param mixed $raw   Raw value.
	 * @return mixed
	 */
	public static function sanitize( array $field, $raw ) {
		$kind = Definition::kind( $field['type'] );
		if ( is_string( $raw ) ) {
			$raw = wp_unslash( $raw );
		} elseif ( is_array( $raw ) ) {
			$raw = wp_unslash( $raw );
		}
		switch ( $kind ) {
			case 'text':
				return is_scalar( $raw ) ? trim( sanitize_textarea_field( (string) $raw ) ) : '';
			case 'email':
				return is_scalar( $raw ) ? strtolower( trim( sanitize_email( (string) $raw ) ) ) : '';
			case 'url':
				if ( ! is_scalar( $raw ) || '' === trim( (string) $raw ) ) {
					return '';
				}
				$url = trim( (string) $raw );
				$url = preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $url ) ? $url : 'https://' . $url;
				return esc_url_raw( $url, array( 'http', 'https' ) );
			case 'phone':
				return is_scalar( $raw ) ? trim( preg_replace( '/[^\d+()\-.\s\/x]/i', '', (string) $raw ) ) : '';
			case 'number':
				if ( ! is_scalar( $raw ) ) {
					return '';
				}
				$num = str_replace( array( ' ', ',' ), array( '', '.' ), trim( (string) $raw ) );
				return '' === $num ? '' : $num;
			case 'choice':
			case 'product':
				return is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
			case 'choices':
				$list = is_array( $raw ) ? $raw : ( is_scalar( $raw ) && '' !== (string) $raw ? array( $raw ) : array() );
				$out  = array();
				foreach ( $list as $item ) {
					if ( is_scalar( $item ) && '' !== (string) $item ) {
						$out[] = sanitize_text_field( (string) $item );
					}
				}
				return array_values( array_unique( $out ) );
			case 'bool':
				return ! empty( $raw ) && '0' !== $raw ? '1' : '';
			case 'date':
			case 'time':
			case 'color':
				return is_scalar( $raw ) ? trim( sanitize_text_field( (string) $raw ) ) : '';
			case 'name':
				$raw = is_array( $raw ) ? $raw : array();
				return array(
					'first' => isset( $raw['first'] ) && is_scalar( $raw['first'] ) ? mb_substr( trim( sanitize_text_field( (string) $raw['first'] ) ), 0, 100 ) : '',
					'last'  => isset( $raw['last'] ) && is_scalar( $raw['last'] ) ? mb_substr( trim( sanitize_text_field( (string) $raw['last'] ) ), 0, 100 ) : '',
				);
			case 'address':
				$raw = is_array( $raw ) ? $raw : array();
				$out = array();
				foreach ( array( 'line1', 'line2', 'city', 'state', 'zip', 'country' ) as $key ) {
					$out[ $key ] = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? mb_substr( trim( sanitize_text_field( (string) $raw[ $key ] ) ), 0, 200 ) : '';
				}
				$out['country'] = '' === $out['country'] ? '' : Countries::code( $out['country'] );
				return $out;
			case 'password':
			case 'string':
			default:
				return is_scalar( $raw ) ? trim( sanitize_text_field( (string) $raw ) ) : '';
		}
	}

	/**
	 * Whether a sanitized value is empty.
	 *
	 * @param array $field Field.
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_empty( array $field, $value ) {
		if ( 'name' === $field['type'] ) {
			return ! is_array( $value ) || ( '' === $value['first'] && '' === $value['last'] );
		}
		if ( 'address' === $field['type'] ) {
			return ! is_array( $value ) || '' === implode( '', $value );
		}
		return null === $value || '' === $value || array() === $value;
	}

	/**
	 * URL check without DNS lookups: http(s) and a real-looking host name or IP.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function valid_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! $parts || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		return (bool) preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $parts['host'] ) || false !== filter_var( $parts['host'], FILTER_VALIDATE_IP );
	}

	/**
	 * Validates a sanitized value. Returns an error message or ''.
	 *
	 * @param array $field    Field.
	 * @param mixed $value    Sanitized value.
	 * @param array $messages Messages.
	 * @return string
	 */
	public static function validate( array $field, $value, array $messages = array() ) {
		$m = array_merge( self::messages(), $messages );
		if ( self::is_empty( $field, $value ) ) {
			if ( ! empty( $field['required'] ) ) {
				return 'consent' === $field['type'] ? $m['consent'] : $m['required'];
			}
			return '';
		}
		$kind = Definition::kind( $field['type'] );
		switch ( $field['type'] ) {
			case 'name':
				if ( ! empty( $field['required'] ) && ( '' === $value['first'] || '' === $value['last'] ) ) {
					return $m['required'];
				}
				return '';
			case 'address':
				if ( ! empty( $field['required'] ) && ( '' === $value['line1'] || '' === $value['city'] ) ) {
					return $m['required'];
				}
				return '';
			case 'email':
				return is_email( $value ) ? '' : $m['email'];
			case 'url':
				return self::valid_url( $value ) ? '' : $m['url'];
			case 'phone':
				$digits = strlen( preg_replace( '/\D/', '', $value ) );
				return $digits >= 6 && $digits <= 20 ? '' : $m['phone'];
			case 'date':
				$d = \DateTime::createFromFormat( 'Y-m-d', $value );
				if ( ! $d || $d->format( 'Y-m-d' ) !== $value ) {
					return $m['date'];
				}
				if ( ! empty( $field['min_date'] ) && $value < $field['min_date'] ) {
					return $m['min'];
				}
				if ( ! empty( $field['max_date'] ) && $value > $field['max_date'] ) {
					return $m['max'];
				}
				return '';
			case 'time':
				return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? '' : $m['time'];
			case 'color':
				return preg_match( '/^#[0-9a-f]{6}$/i', $value ) ? '' : $m['color'];
			case 'consent':
			case 'toggle':
				return '';
		}
		if ( 'number' === $kind ) {
			if ( ! is_numeric( $value ) ) {
				return $m['number'];
			}
			$num = (float) $value;
			$min = isset( $field['min'] ) && '' !== $field['min'] ? (float) $field['min'] : null;
			$max = isset( $field['max'] ) && '' !== $field['max'] ? (float) $field['max'] : null;
			if ( 'rating' === $field['type'] ) {
				$min = 1;
				$max = (float) $field['max'];
			} elseif ( 'nps' === $field['type'] ) {
				$min = 0;
				$max = 10;
			}
			if ( null !== $min && $num < $min ) {
				return $m['min'];
			}
			if ( null !== $max && $num > $max ) {
				return $m['max'];
			}
			if ( in_array( $field['type'], array( 'rating', 'nps', 'scale', 'quantity' ), true ) && floor( $num ) !== $num ) {
				return $m['number'];
			}
			return '';
		}
		if ( 'choice' === $kind || ( 'product' === $kind && ! empty( $field['options'] ) ) ) {
			return in_array( (string) $value, self::option_values( $field ), true ) ? '' : $m['choice'];
		}
		if ( 'choices' === $kind ) {
			return array_diff( $value, self::option_values( $field ) ) ? $m['choice'] : '';
		}
		if ( in_array( $kind, array( 'string', 'text', 'password' ), true ) ) {
			$max = isset( $field['maxlength'] ) ? (int) $field['maxlength'] : 0;
			if ( $max > 0 && mb_strlen( $value ) > $max ) {
				return $m['length'];
			}
			if ( mb_strlen( $value ) > 20000 ) {
				return $m['length'];
			}
		}
		return '';
	}
}
