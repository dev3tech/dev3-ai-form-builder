/**
 * Safe calculation formulas (no eval). Mirrors includes/Engine/Formula.php; both run
 * tests/fixtures/formula-cases.json.
 */

export const FUNCTIONS = [ 'min', 'max', 'round', 'floor', 'ceil', 'abs', 'sqrt', 'pow', 'if' ];

export class FormulaError extends Error {}

/**
 * Lexer.
 *
 * @param {string} src Source.
 * @return {Array} Tokens [ type, value ].
 */
export function tokenize( src ) {
	const tokens = [];
	const s = String( src || '' );
	let i = 0;
	while ( i < s.length ) {
		const c = s[ i ];
		if ( /\s/.test( c ) ) {
			i++;
			continue;
		}
		if ( /\d/.test( c ) || ( c === '.' && /\d/.test( s[ i + 1 ] || '' ) ) ) {
			const m = /^\d*\.?\d+(?:[eE][+-]?\d+)?/.exec( s.slice( i ) );
			tokens.push( [ 'num', parseFloat( m[ 0 ] ) ] );
			i += m[ 0 ].length;
			continue;
		}
		if ( c === '{' ) {
			const end = s.indexOf( '}', i );
			if ( end === -1 ) {
				throw new FormulaError( 'Unclosed {' );
			}
			tokens.push( [ 'var', s.slice( i + 1, end ).trim() ] );
			i = end + 1;
			continue;
		}
		if ( /[a-zA-Z]/.test( c ) ) {
			const m = /^[a-zA-Z_]+/.exec( s.slice( i ) );
			const name = m[ 0 ].toLowerCase();
			if ( ! FUNCTIONS.includes( name ) ) {
				throw new FormulaError( 'Unknown function ' + name );
			}
			tokens.push( [ 'fn', name ] );
			i += m[ 0 ].length;
			continue;
		}
		const two = s.slice( i, i + 2 );
		if ( [ '>=', '<=', '==', '!=' ].includes( two ) ) {
			tokens.push( [ 'op', two ] );
			i += 2;
			continue;
		}
		if ( '+-*/%()<>,'.includes( c ) ) {
			tokens.push( [ 'op', c ] );
			i++;
			continue;
		}
		throw new FormulaError( 'Unexpected character ' + c );
	}
	return tokens;
}

function roundHalfAway( a, decimals ) {
	const factor = Math.pow( 10, decimals );
	return ( a < 0 ? -1 : 1 ) * Math.floor( Math.abs( a ) * factor + 0.5 + 1e-9 ) / factor;
}

function call( name, args ) {
	const a = args.length > 0 ? args[ 0 ] : 0;
	const b = args.length > 1 ? args[ 1 ] : 0;
	switch ( name ) {
		case 'min':
			return args.length ? Math.min( ...args ) : 0;
		case 'max':
			return args.length ? Math.max( ...args ) : 0;
		case 'round':
			return roundHalfAway( a, Math.max( 0, Math.min( 6, Math.trunc( b ) ) ) );
		case 'floor':
			return Math.floor( a );
		case 'ceil':
			return Math.ceil( a );
		case 'abs':
			return Math.abs( a );
		case 'sqrt':
			return a < 0 ? 0 : Math.sqrt( a );
		case 'pow':
			return Math.pow( a, b );
		case 'if':
			return a !== 0 ? b : ( args.length > 2 ? args[ 2 ] : 0 );
	}
	return 0;
}

/**
 * Evaluates a formula.
 *
 * @param {string} formula Formula.
 * @param {Object} vars    Field id => number.
 * @return {number} Result (throws FormulaError on syntax errors).
 */
export function evaluate( formula, vars = {} ) {
	const tokens = tokenize( formula );
	if ( ! tokens.length ) {
		return 0;
	}
	let pos = 0;
	const isOp = ( v ) => tokens[ pos ] && tokens[ pos ][ 0 ] === 'op' && tokens[ pos ][ 1 ] === v;
	const expect = ( v ) => {
		if ( ! isOp( v ) ) {
			throw new FormulaError( 'Expected ' + v );
		}
		pos++;
	};
	let comparison;
	const primary = () => {
		const tok = tokens[ pos ];
		if ( ! tok ) {
			throw new FormulaError( 'Unexpected end' );
		}
		const [ type, value ] = tok;
		if ( type === 'num' ) {
			pos++;
			return value;
		}
		if ( type === 'var' ) {
			pos++;
			const v = vars[ value ];
			const n = typeof v === 'number' ? v : parseFloat( v );
			return Number.isFinite( n ) && ( typeof v === 'number' || /^\s*[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\s*$/.test( String( v ) ) ) ? n : 0;
		}
		if ( type === 'fn' ) {
			pos++;
			expect( '(' );
			const args = [];
			if ( ! isOp( ')' ) ) {
				args.push( comparison() );
				while ( isOp( ',' ) ) {
					pos++;
					args.push( comparison() );
				}
			}
			expect( ')' );
			return call( value, args );
		}
		if ( isOp( '(' ) ) {
			pos++;
			const inner = comparison();
			expect( ')' );
			return inner;
		}
		throw new FormulaError( 'Unexpected ' + value );
	};
	const unary = () => {
		if ( isOp( '-' ) ) {
			pos++;
			return -unary();
		}
		if ( isOp( '+' ) ) {
			pos++;
			return unary();
		}
		return primary();
	};
	const product = () => {
		let value = unary();
		while ( isOp( '*' ) || isOp( '/' ) || isOp( '%' ) ) {
			const op = tokens[ pos ][ 1 ];
			pos++;
			const right = unary();
			if ( op === '*' ) {
				value *= right;
			} else if ( right === 0 ) {
				value = 0;
			} else {
				value = op === '/' ? value / right : value % right;
			}
		}
		return value;
	};
	const sum = () => {
		let value = product();
		while ( isOp( '+' ) || isOp( '-' ) ) {
			const op = tokens[ pos ][ 1 ];
			pos++;
			const right = product();
			value = op === '+' ? value + right : value - right;
		}
		return value;
	};
	comparison = () => {
		const left = sum();
		for ( const op of [ '>=', '<=', '==', '!=', '>', '<' ] ) {
			if ( isOp( op ) ) {
				pos++;
				const right = sum();
				switch ( op ) {
					case '>=':
						return left >= right ? 1 : 0;
					case '<=':
						return left <= right ? 1 : 0;
					case '==':
						return Math.abs( left - right ) < 1e-9 ? 1 : 0;
					case '!=':
						return Math.abs( left - right ) >= 1e-9 ? 1 : 0;
					case '>':
						return left > right ? 1 : 0;
					default:
						return left < right ? 1 : 0;
				}
			}
		}
		return left;
	};
	const value = comparison();
	if ( pos < tokens.length ) {
		throw new FormulaError( 'Unexpected token' );
	}
	return Number.isFinite( value ) ? value : 0;
}

/**
 * Safe evaluate: returns { value, error }.
 *
 * @param {string} formula Formula.
 * @param {Object} vars    Vars.
 * @return {{value: number, error: string}} Result.
 */
export function tryEvaluate( formula, vars = {} ) {
	try {
		return { value: evaluate( formula, vars ), error: '' };
	} catch ( e ) {
		return { value: 0, error: e.message };
	}
}

/**
 * Field ids referenced by a formula.
 *
 * @param {string} formula Formula.
 * @return {string[]} Ids.
 */
export function references( formula ) {
	const out = [];
	String( formula || '' ).replace( /\{([a-z0-9_\-]+)\}/gi, ( m, id ) => {
		if ( ! out.includes( id ) ) {
			out.push( id );
		}
		return m;
	} );
	return out;
}

export const roundFor = ( value, decimals = 2 ) => roundHalfAway( value, Math.max( 0, Math.min( 6, decimals ) ) );
