<?php
/**
 * Computes calculation and total fields. Mirrors src/shared/compute.js.
 *
 * The server always recomputes these values (and the payment amount) from the submitted inputs;
 * whatever the browser sends for computed fields is ignored.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Engine;

use Dev3\AiFormBuilder\Forms\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Calculator.
 */
class Calculator {

	/**
	 * Numeric value of one field for formulas.
	 *
	 * @param array $field Field.
	 * @param mixed $value Value.
	 * @return float
	 */
	public static function numeric( array $field, $value ) {
		$type = $field['type'];
		if ( 'product' === $type && empty( $field['options'] ) ) {
			return '' === $value || null === $value ? 0.0 : (float) ( isset( $field['price'] ) ? $field['price'] : 0 );
		}
		if ( ! empty( $field['options'] ) ) {
			$selected = is_array( $value ) ? $value : ( '' === (string) $value ? array() : array( (string) $value ) );
			$sum      = 0.0;
			foreach ( $field['options'] as $option ) {
				if ( in_array( (string) $option['value'], array_map( 'strval', $selected ), true ) ) {
					if ( isset( $option['price'] ) && '' !== $option['price'] && null !== $option['price'] ) {
						$sum += (float) $option['price'];
					} elseif ( is_numeric( $option['value'] ) ) {
						$sum += (float) $option['value'];
					}
				}
			}
			return $sum;
		}
		if ( in_array( $type, array( 'toggle', 'consent' ), true ) ) {
			return empty( $value ) ? 0.0 : 1.0;
		}
		if ( is_array( $value ) ) {
			return 0.0;
		}
		$clean = str_replace( array( ',', ' ' ), '', (string) $value );
		return is_numeric( $clean ) ? (float) $clean : 0.0;
	}

	/**
	 * Rounds to the field's decimals.
	 *
	 * @param float $value Value.
	 * @param array $field Field.
	 * @return float
	 */
	public static function round_for( $value, array $field ) {
		$decimals = isset( $field['decimals'] ) ? max( 0, min( 6, (int) $field['decimals'] ) ) : 2;
		$factor   = pow( 10, $decimals );
		return ( $value < 0 ? -1 : 1 ) * floor( abs( $value ) * $factor + 0.5 + 1e-9 ) / $factor;
	}

	/**
	 * Computes every calculation/total field.
	 *
	 * @param array $definition Normalized definition.
	 * @param array $values     Values (hidden fields already blanked).
	 * @param array $visible    Field id => visible.
	 * @return array { values, total, lines: [ { field, label, amount } ] }
	 */
	public static function run( array $definition, array $values, array $visible = array() ) {
		$fields = Definition::fields( $definition );
		$vars   = array();
		$is_on  = static function ( $id ) use ( $visible ) {
			return ! isset( $visible[ $id ] ) || $visible[ $id ];
		};
		foreach ( $fields as $field ) {
			if ( 'calculation' === $field['type'] || 'total' === $field['type'] ) {
				continue;
			}
			$vars[ $field['id'] ] = $is_on( $field['id'] ) ? self::numeric( $field, isset( $values[ $field['id'] ] ) ? $values[ $field['id'] ] : '' ) : 0.0;
		}
		// Quantities linked to products.
		$qty = array();
		foreach ( $fields as $field ) {
			$raw_qty = isset( $values[ $field['id'] ] ) ? $values[ $field['id'] ] : '';
			if ( 'quantity' === $field['type'] && ! empty( $field['product'] ) && $is_on( $field['id'] ) && is_scalar( $raw_qty ) && '' !== trim( (string) $raw_qty ) ) {
				$qty[ $field['product'] ] = max( 0.0, $vars[ $field['id'] ] );
			}
		}
		$lines    = array();
		$subtotal = 0.0;
		foreach ( $fields as $field ) {
			if ( ! $is_on( $field['id'] ) ) {
				continue;
			}
			if ( 'product' === $field['type'] ) {
				$amount = $vars[ $field['id'] ] * ( isset( $qty[ $field['id'] ] ) ? $qty[ $field['id'] ] : 1.0 );
				if ( $amount > 0 ) {
					$lines[]   = array(
						'field'  => $field['id'],
						'label'  => $field['label'],
						'amount' => round( $amount, 2 ),
					);
					$subtotal += $amount;
				}
			} elseif ( 'amount' === $field['type'] && $vars[ $field['id'] ] > 0 ) {
				$lines[]   = array(
					'field'  => $field['id'],
					'label'  => $field['label'],
					'amount' => round( $vars[ $field['id'] ], 2 ),
				);
				$subtotal += $vars[ $field['id'] ];
			}
		}
		foreach ( $fields as $field ) {
			if ( 'calculation' !== $field['type'] ) {
				continue;
			}
			$vars['_subtotal']      = $subtotal;
			$result                 = $is_on( $field['id'] ) ? Formula::evaluate( isset( $field['formula'] ) ? $field['formula'] : '', $vars ) : 0.0;
			$result                 = is_wp_error( $result ) ? 0.0 : self::round_for( $result, $field );
			$vars[ $field['id'] ]   = $result;
			$values[ $field['id'] ] = $is_on( $field['id'] ) ? (string) $result : '';
			if ( ! empty( $field['add_to_total'] ) && $is_on( $field['id'] ) && 0.0 !== $result ) {
				$lines[]   = array(
					'field'  => $field['id'],
					'label'  => $field['label'],
					'amount' => round( $result, 2 ),
				);
				$subtotal += $result;
			}
		}
		$total = round( max( 0.0, $subtotal ), 2 );
		foreach ( $fields as $field ) {
			if ( 'total' === $field['type'] ) {
				$values[ $field['id'] ] = $is_on( $field['id'] ) ? number_format( $total, 2, '.', '' ) : '';
			}
		}
		return array(
			'values' => $values,
			'total'  => $total,
			'lines'  => $lines,
		);
	}
}
