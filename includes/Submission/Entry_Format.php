<?php
/**
 * Turns stored entry values into readable text/HTML (emails, exports, AI input, admin view).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Submission;

use Dev3\AiFormBuilder\Forms\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Formatter.
 */
class Entry_Format {

	/**
	 * Display string for one value.
	 *
	 * @param array $field Field.
	 * @param mixed $value Stored value.
	 * @return string
	 */
	public static function display( array $field, $value ) {
		if ( null === $value || '' === $value || array() === $value ) {
			return '';
		}
		switch ( $field['type'] ) {
			case 'name':
				return is_array( $value ) ? trim( ( isset( $value['first'] ) ? $value['first'] : '' ) . ' ' . ( isset( $value['last'] ) ? $value['last'] : '' ) ) : (string) $value;
			case 'address':
				if ( ! is_array( $value ) ) {
					return (string) $value;
				}
				$parts     = array();
				$countries = \Dev3\AiFormBuilder\Forms\Countries::all();
				foreach ( array( 'line1', 'line2', 'city', 'state', 'zip', 'country' ) as $key ) {
					if ( ! empty( $value[ $key ] ) ) {
						$parts[] = 'country' === $key && isset( $countries[ $value[ $key ] ] ) ? $countries[ $value[ $key ] ] : $value[ $key ];
					}
				}
				return implode( ', ', $parts );
			case 'country':
				$countries = \Dev3\AiFormBuilder\Forms\Countries::all();
				return isset( $countries[ (string) $value ] ) ? $countries[ (string) $value ] : (string) $value;
			case 'file':
				$names = array();
				foreach ( is_array( $value ) ? $value : array() as $file ) {
					$names[] = isset( $file['name'] ) ? $file['name'] : '';
				}
				return implode( ', ', array_filter( $names ) );
			case 'signature':
				return __( '(signed)', 'dev3-ai-form-builder' );
			case 'password':
				return '********';
			case 'toggle':
				return ! empty( $value ) ? ( isset( $field['on_label'] ) ? $field['on_label'] : __( 'Yes', 'dev3-ai-form-builder' ) ) : ( isset( $field['off_label'] ) ? $field['off_label'] : __( 'No', 'dev3-ai-form-builder' ) );
			case 'consent':
				return ! empty( $value ) ? __( 'Agreed', 'dev3-ai-form-builder' ) : '';
			case 'calculation':
				return ( isset( $field['prefix'] ) ? $field['prefix'] : '' ) . $value . ( isset( $field['suffix'] ) ? $field['suffix'] : '' );
		}
		if ( ! empty( $field['options'] ) ) {
			$selected = is_array( $value ) ? $value : array( $value );
			$labels   = array();
			foreach ( $field['options'] as $option ) {
				if ( in_array( (string) $option['value'], array_map( 'strval', $selected ), true ) ) {
					$labels[] = $option['label'];
				}
			}
			return implode( ', ', $labels ? $labels : array_map( 'strval', $selected ) );
		}
		if ( is_array( $value ) ) {
			return implode( ', ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) );
		}
		return (string) $value;
	}

	/**
	 * Rows for every input field with a value.
	 *
	 * @param array $definition Definition.
	 * @param array $data       Values.
	 * @param bool  $with_empty Include empty fields.
	 * @return array [ { id, label, type, value } ]
	 */
	public static function rows( array $definition, array $data, $with_empty = false ) {
		$out = array();
		foreach ( Definition::input_fields( $definition ) as $field ) {
			if ( 'hidden' === $field['type'] && ! $with_empty ) {
				continue;
			}
			$value = self::display( $field, isset( $data[ $field['id'] ] ) ? $data[ $field['id'] ] : '' );
			if ( '' === $value && ! $with_empty ) {
				continue;
			}
			$out[] = array(
				'id'    => $field['id'],
				'label' => '' !== $field['label'] ? $field['label'] : $field['id'],
				'type'  => $field['type'],
				'value' => $value,
			);
		}
		return $out;
	}

	/**
	 * "Label: value" text.
	 *
	 * @param array $definition Definition.
	 * @param array $data       Values.
	 * @param array $skip_types Types to leave out.
	 * @return string
	 */
	public static function plain( array $definition, array $data, array $skip_types = array() ) {
		$lines = array();
		foreach ( self::rows( $definition, $data ) as $row ) {
			if ( in_array( $row['type'], $skip_types, true ) ) {
				continue;
			}
			$lines[] = $row['label'] . ': ' . $row['value'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * HTML table for {all_fields}.
	 *
	 * @param array $definition Definition.
	 * @param array $data       Values.
	 * @return string
	 */
	public static function html( array $definition, array $data ) {
		$html = '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:640px;font-family:sans-serif;font-size:14px">';
		foreach ( self::rows( $definition, $data ) as $row ) {
			$html .= '<tr><th align="left" valign="top" style="border-bottom:1px solid #e5e7eb;width:35%;color:#374151">' . esc_html( $row['label'] ) . '</th><td style="border-bottom:1px solid #e5e7eb;color:#111827">' . nl2br( esc_html( $row['value'] ) ) . '</td></tr>';
		}
		return $html . '</table>';
	}

	/**
	 * Values for the AI spam check: [ { label, type, value } ].
	 *
	 * @param array $definition Definition.
	 * @param array $data       Values.
	 * @return array
	 */
	public static function for_classifier( array $definition, array $data ) {
		$out = array();
		foreach ( self::rows( $definition, $data ) as $row ) {
			$out[] = array(
				'label' => $row['label'],
				'type'  => $row['type'],
				'value' => $row['value'],
			);
		}
		return $out;
	}

	/**
	 * Visitor's name from a name field or first/last text fields ('' when unknown).
	 *
	 * @param array $definition Definition.
	 * @param array $data       Values.
	 * @return string
	 */
	public static function sender_name( array $definition, array $data ) {
		foreach ( Definition::fields( $definition ) as $field ) {
			if ( 'name' === $field['type'] && ! empty( $data[ $field['id'] ] ) ) {
				return self::display( $field, $data[ $field['id'] ] );
			}
		}
		foreach ( Definition::fields( $definition ) as $field ) {
			if ( 'text' === $field['type'] && preg_match( '/\bname\b/i', $field['label'] . ' ' . str_replace( '_', ' ', $field['id'] ) ) && ! empty( $data[ $field['id'] ] ) ) {
				return (string) $data[ $field['id'] ];
			}
		}
		return '';
	}
}
