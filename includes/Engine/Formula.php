<?php
/**
 * Safe calculation formulas (no eval). Mirrors src/shared/formula.js; both run
 * tests/fixtures/formula-cases.json.
 *
 * Grammar: numbers, {field_id} references, + - * / %, unary minus, parentheses,
 * comparisons (> < >= <= == !=, result 1 or 0) and functions
 * min, max, round(x[, decimals]), floor, ceil, abs, sqrt, pow(x, y), if(cond, a, b).
 * Division or modulo by zero gives 0. Unknown references are 0.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Recursive-descent evaluator.
 */
class Formula {

	const FUNCTIONS = array( 'min', 'max', 'round', 'floor', 'ceil', 'abs', 'sqrt', 'pow', 'if' );

	/**
	 * Tokens.
	 *
	 * @var array
	 */
	private $tokens = array();

	/**
	 * Cursor.
	 *
	 * @var int
	 */
	private $pos = 0;

	/**
	 * Variables.
	 *
	 * @var array
	 */
	private $vars = array();

	/**
	 * Evaluates a formula.
	 *
	 * @param string $formula Formula.
	 * @param array  $vars    Field id => number.
	 * @return float|\WP_Error
	 * @throws \InvalidArgumentException Internally on a syntax error (caught and returned as WP_Error).
	 */
	public static function evaluate( $formula, array $vars = array() ) {
		$parser = new self();
		$tokens = self::tokenize( (string) $formula );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		if ( ! $tokens ) {
			return 0.0;
		}
		$parser->tokens = $tokens;
		$parser->vars   = $vars;
		try {
			$value = $parser->comparison();
			if ( $parser->pos < count( $parser->tokens ) ) {
				throw new \InvalidArgumentException( 'Unexpected token' );
			}
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'dev3fb_formula', $e->getMessage() );
		}
		return is_finite( $value ) ? (float) $value : 0.0;
	}

	/**
	 * Field ids referenced by a formula.
	 *
	 * @param string $formula Formula.
	 * @return string[]
	 */
	public static function references( $formula ) {
		preg_match_all( '/\{([a-z0-9_\-]+)\}/i', (string) $formula, $m );
		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * Lexer.
	 *
	 * @param string $src Source.
	 * @return array|\WP_Error [ [ type, value ] ]
	 */
	public static function tokenize( $src ) {
		$tokens = array();
		$len    = strlen( $src );
		$i      = 0;
		while ( $i < $len ) {
			$c = $src[ $i ];
			if ( ctype_space( $c ) ) {
				++$i;
				continue;
			}
			if ( ctype_digit( $c ) || ( '.' === $c && $i + 1 < $len && ctype_digit( $src[ $i + 1 ] ) ) ) {
				preg_match( '/\G\d*\.?\d+(?:[eE][+-]?\d+)?/', $src, $m, 0, $i );
				$tokens[] = array( 'num', (float) $m[0] );
				$i       += strlen( $m[0] );
				continue;
			}
			if ( '{' === $c ) {
				$end = strpos( $src, '}', $i );
				if ( false === $end ) {
					return new \WP_Error( 'dev3fb_formula', 'Unclosed {' );
				}
				$tokens[] = array( 'var', trim( substr( $src, $i + 1, $end - $i - 1 ) ) );
				$i        = $end + 1;
				continue;
			}
			if ( ctype_alpha( $c ) ) {
				preg_match( '/\G[a-zA-Z_]+/', $src, $m, 0, $i );
				$name = strtolower( $m[0] );
				if ( ! in_array( $name, self::FUNCTIONS, true ) ) {
					return new \WP_Error( 'dev3fb_formula', 'Unknown function ' . $name );
				}
				$tokens[] = array( 'fn', $name );
				$i       += strlen( $m[0] );
				continue;
			}
			$two = substr( $src, $i, 2 );
			if ( in_array( $two, array( '>=', '<=', '==', '!=' ), true ) ) {
				$tokens[] = array( 'op', $two );
				$i       += 2;
				continue;
			}
			if ( false !== strpos( '+-*/%()<>,', $c ) ) {
				$tokens[] = array( 'op', $c );
				++$i;
				continue;
			}
			return new \WP_Error( 'dev3fb_formula', 'Unexpected character ' . $c );
		}
		return $tokens;
	}

	/**
	 * Peek.
	 *
	 * @param string $value Operator.
	 * @return bool
	 */
	private function is_op( $value ) {
		return isset( $this->tokens[ $this->pos ] ) && 'op' === $this->tokens[ $this->pos ][0] && $value === $this->tokens[ $this->pos ][1];
	}

	/**
	 * Consumes an operator or fails.
	 *
	 * @param string $value Operator.
	 * @throws \InvalidArgumentException On a syntax error.
	 */
	private function expect( $value ) {
		if ( ! $this->is_op( $value ) ) {
			throw new \InvalidArgumentException( esc_html( 'Expected ' . $value ) );
		}
		++$this->pos;
	}

	/**
	 * Comparison: sum ( (> < >= <= == !=) sum )?
	 *
	 * @return float
	 * @throws \InvalidArgumentException On a syntax error.
	 */
	private function comparison() {
		$left = $this->sum();
		foreach ( array( '>=', '<=', '==', '!=', '>', '<' ) as $op ) {
			if ( $this->is_op( $op ) ) {
				++$this->pos;
				$right = $this->sum();
				switch ( $op ) {
					case '>=':
						return $left >= $right ? 1.0 : 0.0;
					case '<=':
						return $left <= $right ? 1.0 : 0.0;
					case '==':
						return abs( $left - $right ) < 1e-9 ? 1.0 : 0.0;
					case '!=':
						return abs( $left - $right ) >= 1e-9 ? 1.0 : 0.0;
					case '>':
						return $left > $right ? 1.0 : 0.0;
					default:
						return $left < $right ? 1.0 : 0.0;
				}
			}
		}
		return $left;
	}

	/**
	 * Sum: product ( (+|-) product )*
	 *
	 * @return float
	 * @throws \InvalidArgumentException On a syntax error.
	 */
	private function sum() {
		$value = $this->product();
		while ( $this->is_op( '+' ) || $this->is_op( '-' ) ) {
			$op = $this->tokens[ $this->pos ][1];
			++$this->pos;
			$right = $this->product();
			$value = '+' === $op ? $value + $right : $value - $right;
		}
		return $value;
	}

	/**
	 * Product: unary ( (*|/|%) unary )*
	 *
	 * @return float
	 * @throws \InvalidArgumentException On a syntax error.
	 */
	private function product() {
		$value = $this->unary();
		while ( $this->is_op( '*' ) || $this->is_op( '/' ) || $this->is_op( '%' ) ) {
			$op = $this->tokens[ $this->pos ][1];
			++$this->pos;
			$right = $this->unary();
			if ( '*' === $op ) {
				$value *= $right;
			} elseif ( 0.0 === (float) $right ) {
				$value = 0.0;
			} else {
				$value = '/' === $op ? $value / $right : fmod( $value, $right );
			}
		}
		return $value;
	}

	/**
	 * Unary: - unary | + unary | primary
	 *
	 * @return float
	 * @throws \InvalidArgumentException On a syntax error.
	 */
	private function unary() {
		if ( $this->is_op( '-' ) ) {
			++$this->pos;
			return -$this->unary();
		}
		if ( $this->is_op( '+' ) ) {
			++$this->pos;
			return $this->unary();
		}
		return $this->primary();
	}

	/**
	 * Primary: num | var | fn ( args ) | ( comparison )
	 *
	 * @return float
	 * @throws \InvalidArgumentException On a syntax error.
	 */
	private function primary() {
		if ( ! isset( $this->tokens[ $this->pos ] ) ) {
			throw new \InvalidArgumentException( 'Unexpected end' );
		}
		list( $type, $value ) = $this->tokens[ $this->pos ];
		if ( 'num' === $type ) {
			++$this->pos;
			return (float) $value;
		}
		if ( 'var' === $type ) {
			++$this->pos;
			return isset( $this->vars[ $value ] ) && is_numeric( $this->vars[ $value ] ) ? (float) $this->vars[ $value ] : 0.0;
		}
		if ( 'fn' === $type ) {
			++$this->pos;
			$this->expect( '(' );
			$args = array();
			if ( ! $this->is_op( ')' ) ) {
				$args[] = $this->comparison();
				while ( $this->is_op( ',' ) ) {
					++$this->pos;
					$args[] = $this->comparison();
				}
			}
			$this->expect( ')' );
			return self::call( $value, $args );
		}
		if ( $this->is_op( '(' ) ) {
			++$this->pos;
			$inner = $this->comparison();
			$this->expect( ')' );
			return $inner;
		}
		throw new \InvalidArgumentException( esc_html( 'Unexpected ' . $value ) );
	}

	/**
	 * Built-in functions.
	 *
	 * @param string $name Name.
	 * @param array  $args Args.
	 * @return float
	 */
	private static function call( $name, array $args ) {
		$a = isset( $args[0] ) ? $args[0] : 0.0;
		$b = isset( $args[1] ) ? $args[1] : 0.0;
		switch ( $name ) {
			case 'min':
				return $args ? (float) min( $args ) : 0.0;
			case 'max':
				return $args ? (float) max( $args ) : 0.0;
			case 'round':
				$decimals = (int) max( 0, min( 6, $b ) );
				$factor   = pow( 10, $decimals );
				// Half away from zero, same as Math.round on the absolute value in JS.
				return ( $a < 0 ? -1 : 1 ) * floor( abs( $a ) * $factor + 0.5 + 1e-9 ) / $factor;
			case 'floor':
				return floor( $a );
			case 'ceil':
				return ceil( $a );
			case 'abs':
				return abs( $a );
			case 'sqrt':
				return $a < 0 ? 0.0 : sqrt( $a );
			case 'pow':
				return (float) pow( $a, $b );
			case 'if':
				$c = isset( $args[2] ) ? $args[2] : 0.0;
				return 0.0 !== (float) $a ? $b : $c;
		}
		return 0.0;
	}
}
