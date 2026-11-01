<?php
/**
 * Conditional logic. Mirrors src/shared/logic.js exactly (both run tests/fixtures/logic-cases.json).
 *
 * A rule set is { enabled, action: show|hide, match: all|any, rules: [ { field, op, value } ] }.
 * Fields are evaluated in form order; a field hidden by logic counts as empty for later rules,
 * and every field of a hidden step is hidden.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Logic evaluator.
 */
class Logic {

	const OPS = array( 'is', 'is_not', 'contains', 'not_contains', 'starts_with', 'ends_with', 'gt', 'lt', 'gte', 'lte', 'empty', 'not_empty' );

	/**
	 * Normalizes a value for comparison: a list of lower-cased trimmed strings.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	public static function as_list( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? array( '1' ) : array();
		}
		if ( null === $value || '' === $value ) {
			return array();
		}
		if ( is_array( $value ) ) {
			if ( array_values( $value ) === $value ) {
				$out = array();
				foreach ( $value as $item ) {
					if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
						$out[] = self::lower( trim( (string) $item ) );
					}
				}
				return $out;
			}
			$parts = array();
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
					$parts[] = trim( (string) $item );
				}
			}
			return $parts ? array( self::lower( implode( ' ', $parts ) ) ) : array();
		}
		return is_scalar( $value ) ? array( self::lower( trim( (string) $value ) ) ) : array();
	}

	/**
	 * Lower-case (multibyte safe).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
	}

	/**
	 * One rule against a value.
	 *
	 * @param string $op     Operator.
	 * @param mixed  $actual Field value.
	 * @param mixed  $target Rule value.
	 * @return bool
	 */
	public static function compare( $op, $actual, $target ) {
		$list   = self::as_list( $actual );
		$target = self::lower( trim( is_scalar( $target ) ? (string) $target : '' ) );
		switch ( $op ) {
			case 'empty':
				return ! $list;
			case 'not_empty':
				return (bool) $list;
			case 'is':
				return in_array( $target, $list, true ) || ( ! $list && '' === $target );
			case 'is_not':
				return ! self::compare( 'is', $actual, $target );
			case 'contains':
				foreach ( $list as $item ) {
					if ( '' !== $target && false !== strpos( $item, $target ) ) {
						return true;
					}
				}
				return false;
			case 'not_contains':
				return ! self::compare( 'contains', $actual, $target );
			case 'starts_with':
				foreach ( $list as $item ) {
					if ( '' !== $target && 0 === strpos( $item, $target ) ) {
						return true;
					}
				}
				return false;
			case 'ends_with':
				foreach ( $list as $item ) {
					if ( '' !== $target && strlen( $item ) >= strlen( $target ) && substr( $item, -strlen( $target ) ) === $target ) {
						return true;
					}
				}
				return false;
			case 'gt':
			case 'lt':
			case 'gte':
			case 'lte':
				if ( 1 !== count( $list ) || ! is_numeric( $list[0] ) || ! is_numeric( $target ) ) {
					return false;
				}
				$a = (float) $list[0];
				$b = (float) $target;
				if ( 'gt' === $op ) {
					return $a > $b;
				}
				if ( 'lt' === $op ) {
					return $a < $b;
				}
				return 'gte' === $op ? $a >= $b : $a <= $b;
		}
		return false;
	}

	/**
	 * Whether something with this rule set is visible.
	 *
	 * @param array|null $logic  Rule set.
	 * @param array      $values Current values (hidden fields already blanked).
	 * @return bool
	 */
	public static function visible( $logic, array $values ) {
		if ( ! is_array( $logic ) || empty( $logic['enabled'] ) || empty( $logic['rules'] ) || ! is_array( $logic['rules'] ) ) {
			return true;
		}
		$any     = isset( $logic['match'] ) && 'any' === $logic['match'];
		$matched = ! $any;
		$count   = 0;
		foreach ( $logic['rules'] as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['field'] ) || empty( $rule['op'] ) || ! in_array( $rule['op'], self::OPS, true ) ) {
				continue;
			}
			++$count;
			$actual = isset( $values[ $rule['field'] ] ) ? $values[ $rule['field'] ] : '';
			$ok     = self::compare( $rule['op'], $actual, isset( $rule['value'] ) ? $rule['value'] : '' );
			if ( $any && $ok ) {
				$matched = true;
				break;
			}
			if ( ! $any && ! $ok ) {
				$matched = false;
				break;
			}
			if ( $any ) {
				$matched = false;
			}
		}
		if ( 0 === $count ) {
			return true;
		}
		$hide = isset( $logic['action'] ) && 'hide' === $logic['action'];
		return $hide ? ! $matched : $matched;
	}

	/**
	 * Visibility of every step and field.
	 *
	 * @param array $definition Normalized definition.
	 * @param array $values     Submitted values keyed by field id.
	 * @return array { steps: { id: bool }, fields: { id: bool }, values: values with hidden blanked }
	 */
	public static function evaluate( array $definition, array $values ) {
		$steps  = array();
		$fields = array();
		foreach ( isset( $definition['steps'] ) ? $definition['steps'] : array() as $step ) {
			$step_visible         = self::visible( isset( $step['logic'] ) ? $step['logic'] : null, $values );
			$steps[ $step['id'] ] = $step_visible;
			foreach ( isset( $step['fields'] ) ? $step['fields'] : array() as $field ) {
				$visible                = $step_visible && self::visible( isset( $field['logic'] ) ? $field['logic'] : null, $values );
				$fields[ $field['id'] ] = $visible;
				if ( ! $visible ) {
					$values[ $field['id'] ] = '';
				}
			}
		}
		return array(
			'steps'  => $steps,
			'fields' => $fields,
			'values' => $values,
		);
	}
}
