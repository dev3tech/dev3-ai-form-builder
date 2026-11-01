/**
 * Live calculations and totals. Mirrors includes/Engine/Calculator.php (the server recomputes
 * everything; this only drives what the visitor sees).
 */
import { roundFor, tryEvaluate } from './formula';

const NUMERIC = /^\s*[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\s*$/;

/**
 * Numeric value of one field for formulas.
 *
 * @param {Object} field Field { type, options, price }.
 * @param {*}      value Value.
 * @return {number} Number.
 */
export function numeric( field, value ) {
	if ( field.type === 'product' && ! ( field.options && field.options.length ) ) {
		return value === '' || value === null || value === undefined ? 0 : Number( field.price || 0 );
	}
	if ( field.options && field.options.length ) {
		let selected = [];
		if ( Array.isArray( value ) ) {
			selected = value.map( String );
		} else if ( value !== '' && value !== null && value !== undefined ) {
			selected = [ String( value ) ];
		}
		let sum = 0;
		field.options.forEach( ( option ) => {
			if ( selected.includes( String( option.value ) ) ) {
				if ( option.price !== undefined && option.price !== null && option.price !== '' ) {
					sum += Number( option.price );
				} else if ( NUMERIC.test( String( option.value ) ) ) {
					sum += parseFloat( option.value );
				}
			}
		} );
		return sum;
	}
	if ( field.type === 'toggle' || field.type === 'consent' ) {
		return value && value !== '0' ? 1 : 0;
	}
	if ( Array.isArray( value ) || ( value && typeof value === 'object' ) ) {
		return 0;
	}
	const clean = String( value === undefined || value === null ? '' : value ).replace( /[,\s]/g, '' );
	return NUMERIC.test( clean ) ? parseFloat( clean ) : 0;
}

/**
 * Computes calculation and total fields.
 *
 * @param {Array}  fields  Flat field list (form order).
 * @param {Object} values  Values (hidden already blanked).
 * @param {Object} visible Field id => visible.
 * @return {{values: Object, total: number, lines: Array}} Result.
 */
export function run( fields, values, visible = {} ) {
	const out = { ...values };
	const isOn = ( id ) => visible[ id ] === undefined || visible[ id ];
	const vars = {};
	fields.forEach( ( f ) => {
		if ( f.type !== 'calculation' && f.type !== 'total' ) {
			vars[ f.id ] = isOn( f.id ) ? numeric( f, values[ f.id ] === undefined ? '' : values[ f.id ] ) : 0;
		}
	} );
	const qty = {};
	fields.forEach( ( f ) => {
		const raw = values[ f.id ];
		if ( f.type === 'quantity' && f.product && isOn( f.id ) && raw !== undefined && raw !== null && String( raw ).trim() !== '' ) {
			qty[ f.product ] = Math.max( 0, vars[ f.id ] );
		}
	} );
	const lines = [];
	let subtotal = 0;
	fields.forEach( ( f ) => {
		if ( ! isOn( f.id ) ) {
			return;
		}
		if ( f.type === 'product' ) {
			const amount = vars[ f.id ] * ( qty[ f.id ] !== undefined ? qty[ f.id ] : 1 );
			if ( amount > 0 ) {
				lines.push( { field: f.id, amount: Math.round( amount * 100 ) / 100 } );
				subtotal += amount;
			}
		} else if ( f.type === 'amount' && vars[ f.id ] > 0 ) {
			lines.push( { field: f.id, amount: Math.round( vars[ f.id ] * 100 ) / 100 } );
			subtotal += vars[ f.id ];
		}
	} );
	fields.forEach( ( f ) => {
		if ( f.type !== 'calculation' ) {
			return;
		}
		vars._subtotal = subtotal;
		let result = 0;
		if ( isOn( f.id ) ) {
			const r = tryEvaluate( f.formula || '', vars );
			result = r.error ? 0 : roundFor( r.value, f.decimals === undefined ? 2 : f.decimals );
		}
		vars[ f.id ] = result;
		out[ f.id ] = isOn( f.id ) ? String( result ) : '';
		if ( f.add_to_total && isOn( f.id ) && result !== 0 ) {
			lines.push( { field: f.id, amount: Math.round( result * 100 ) / 100 } );
			subtotal += result;
		}
	} );
	const total = Math.round( Math.max( 0, subtotal ) * 100 ) / 100;
	fields.forEach( ( f ) => {
		if ( f.type === 'total' ) {
			out[ f.id ] = isOn( f.id ) ? total.toFixed( 2 ) : '';
		}
	} );
	return { values: out, total, lines };
}
