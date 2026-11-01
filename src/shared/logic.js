/**
 * Conditional logic. Mirrors includes/Engine/Logic.php exactly; both run
 * tests/fixtures/logic-cases.json.
 */

export const OPS = [ 'is', 'is_not', 'contains', 'not_contains', 'starts_with', 'ends_with', 'gt', 'lt', 'gte', 'lte', 'empty', 'not_empty' ];

const lower = ( s ) => String( s ).toLowerCase();
const isNumeric = ( v ) => typeof v === 'string' && v.trim() !== '' && /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/.test( v.trim() );

/**
 * Normalizes a value to a list of lower-cased trimmed strings.
 *
 * @param {*} value Value.
 * @return {string[]} List.
 */
export function asList( value ) {
	if ( typeof value === 'boolean' ) {
		return value ? [ '1' ] : [];
	}
	if ( value === null || value === undefined || value === '' ) {
		return [];
	}
	if ( Array.isArray( value ) ) {
		return value.filter( ( v ) => ( typeof v === 'string' || typeof v === 'number' ) && String( v ).trim() !== '' ).map( ( v ) => lower( String( v ).trim() ) );
	}
	if ( typeof value === 'object' ) {
		const parts = Object.values( value ).filter( ( v ) => ( typeof v === 'string' || typeof v === 'number' ) && String( v ).trim() !== '' ).map( ( v ) => String( v ).trim() );
		return parts.length ? [ lower( parts.join( ' ' ) ) ] : [];
	}
	return [ lower( String( value ).trim() ) ];
}

/**
 * One rule.
 *
 * @param {string} op     Operator.
 * @param {*}      actual Value.
 * @param {*}      target Rule value.
 * @return {boolean} Match.
 */
export function compare( op, actual, target ) {
	const list = asList( actual );
	const t = lower( String( target === null || target === undefined || typeof target === 'object' ? '' : target ).trim() );
	switch ( op ) {
		case 'empty':
			return ! list.length;
		case 'not_empty':
			return !! list.length;
		case 'is':
			return list.includes( t ) || ( ! list.length && t === '' );
		case 'is_not':
			return ! compare( 'is', actual, t );
		case 'contains':
			return list.some( ( item ) => t !== '' && item.includes( t ) );
		case 'not_contains':
			return ! compare( 'contains', actual, t );
		case 'starts_with':
			return list.some( ( item ) => t !== '' && item.startsWith( t ) );
		case 'ends_with':
			return list.some( ( item ) => t !== '' && item.endsWith( t ) );
		case 'gt':
		case 'lt':
		case 'gte':
		case 'lte': {
			if ( list.length !== 1 || ! isNumeric( list[ 0 ] ) || ! isNumeric( t ) ) {
				return false;
			}
			const a = parseFloat( list[ 0 ] );
			const b = parseFloat( t );
			if ( op === 'gt' ) {
				return a > b;
			}
			if ( op === 'lt' ) {
				return a < b;
			}
			return op === 'gte' ? a >= b : a <= b;
		}
	}
	return false;
}

/**
 * Whether something with this rule set is visible.
 *
 * @param {Object|null} logic  Rule set.
 * @param {Object}      values Values.
 * @return {boolean} Visible.
 */
export function visible( logic, values ) {
	if ( ! logic || ! logic.enabled || ! Array.isArray( logic.rules ) || ! logic.rules.length ) {
		return true;
	}
	const any = logic.match === 'any';
	let matched = ! any;
	let count = 0;
	for ( const rule of logic.rules ) {
		if ( ! rule || ! rule.field || ! rule.op || ! OPS.includes( rule.op ) ) {
			continue;
		}
		count++;
		const ok = compare( rule.op, values[ rule.field ] === undefined ? '' : values[ rule.field ], rule.value === undefined ? '' : rule.value );
		if ( any && ok ) {
			matched = true;
			break;
		}
		if ( ! any && ! ok ) {
			matched = false;
			break;
		}
	}
	if ( count === 0 ) {
		return true;
	}
	return logic.action === 'hide' ? ! matched : matched;
}

/**
 * Visibility of all steps and fields.
 *
 * @param {Object} definition { steps: [ { id, logic, fields: [ { id, logic } ] } ] }.
 * @param {Object} input      Values.
 * @return {{steps: Object, fields: Object, values: Object}} Result.
 */
export function evaluate( definition, input ) {
	const values = { ...input };
	const steps = {};
	const fields = {};
	( definition.steps || [] ).forEach( ( step ) => {
		const stepVisible = visible( step.logic || null, values );
		steps[ step.id ] = stepVisible;
		( step.fields || [] ).forEach( ( field ) => {
			const v = stepVisible && visible( field.logic || null, values );
			fields[ field.id ] = v;
			if ( ! v ) {
				values[ field.id ] = '';
			}
		} );
	} );
	return { steps, fields, values };
}
