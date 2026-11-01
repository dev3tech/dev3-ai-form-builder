/**
 * Pure, immutable operations on a form definition (unit-tested).
 */
import { newField } from './fieldTypes';

export const allFields = ( def ) => ( def.steps || [] ).flatMap( ( s ) => s.fields || [] );

/**
 * Slug for ids (same rules as PHP Definition::slug).
 *
 * @param {string} text   Text.
 * @param {string} prefix Prefix.
 * @return {string} Slug.
 */
export function slug( text, prefix = 'field' ) {
	let s = String( text || '' )
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^_+|_+$/g, '' )
		.slice( 0, 40 )
		.replace( /_+$/, '' );
	if ( ! s || ! /^[a-z]/.test( s ) ) {
		s = prefix + ( s ? '_' + s : '' );
	}
	return s.slice( 0, 40 );
}

/**
 * Unique field id.
 *
 * @param {Object} def  Definition.
 * @param {string} base Base text.
 * @return {string} Id.
 */
export function uniqueId( def, base ) {
	const used = new Set( allFields( def ).map( ( f ) => f.id ) );
	const root = slug( base );
	let id = root;
	let n = 2;
	while ( used.has( id ) ) {
		id = `${ root.slice( 0, 36 ) }_${ n++ }`;
	}
	return id;
}

const mapSteps = ( def, fn ) => ( { ...def, steps: def.steps.map( fn ) } );

/**
 * Locates a field.
 *
 * @param {Object} def Definition.
 * @param {string} id  Field id.
 * @return {{step: number, index: number}|null} Position.
 */
export function locate( def, id ) {
	for ( let s = 0; s < def.steps.length; s++ ) {
		const i = def.steps[ s ].fields.findIndex( ( f ) => f.id === id );
		if ( i !== -1 ) {
			return { step: s, index: i };
		}
	}
	return null;
}

export function findField( def, id ) {
	return allFields( def ).find( ( f ) => f.id === id ) || null;
}

/**
 * Adds a field of a type at a position (end of the step by default).
 *
 * @param {Object} def   Definition.
 * @param {string} type  Type.
 * @param {number} step  Step index.
 * @param {number} index Insert index (-1 = end).
 * @return {{def: Object, id: string}} New definition and field id.
 */
export function addField( def, type, step = 0, index = -1 ) {
	const field = newField( type, 'tmp' );
	const id = uniqueId( def, field.label || type );
	field.id = id;
	const target = Math.max( 0, Math.min( def.steps.length - 1, step ) );
	const next = mapSteps( def, ( s, i ) => {
		if ( i !== target ) {
			return s;
		}
		const fields = [ ...s.fields ];
		fields.splice( index < 0 || index > fields.length ? fields.length : index, 0, field );
		return { ...s, fields };
	} );
	return { def: next, id };
}

/**
 * Inserts an existing field object (e.g. from AI or paste).
 *
 * @param {Object} def   Definition.
 * @param {Object} field Field.
 * @param {number} step  Step.
 * @return {Object} Definition.
 */
export function insertField( def, field, step = 0 ) {
	const copy = { ...field, id: uniqueId( def, field.id || field.label ) };
	return mapSteps( def, ( s, i ) => ( i === step ? { ...s, fields: [ ...s.fields, copy ] } : s ) );
}

export function updateField( def, id, patch ) {
	return mapSteps( def, ( s ) => ( { ...s, fields: s.fields.map( ( f ) => ( f.id === id ? { ...f, ...patch } : f ) ) } ) );
}

/**
 * Renames a field id and rewrites references (logic, formulas, quantity links, settings).
 *
 * @param {Object} def   Definition.
 * @param {string} oldId Old id.
 * @param {string} raw   Requested id.
 * @return {Object} Definition.
 */
export function renameField( def, oldId, raw ) {
	const wanted = slug( raw );
	if ( ! wanted || wanted === oldId ) {
		return def;
	}
	if ( allFields( def ).some( ( f ) => f.id === wanted ) ) {
		return def;
	}
	const fixLogic = ( logic ) => ( logic && Array.isArray( logic.rules ) ? { ...logic, rules: logic.rules.map( ( r ) => ( r.field === oldId ? { ...r, field: wanted } : r ) ) } : logic );
	const re = new RegExp( `\\{${ oldId.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) }\\}`, 'g' );
	const tag = new RegExp( `\\{field:${ oldId.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) }\\}`, 'g' );
	const fixText = ( t ) => ( typeof t === 'string' ? t.replace( tag, `{field:${ wanted }}` ) : t );
	const out = mapSteps( def, ( s ) => ( {
		...s,
		logic: fixLogic( s.logic ),
		fields: s.fields.map( ( f ) => {
			const next = { ...f, logic: fixLogic( f.logic ) };
			if ( f.id === oldId ) {
				next.id = wanted;
			}
			if ( typeof f.formula === 'string' ) {
				next.formula = f.formula.replace( re, `{${ wanted }}` );
			}
			if ( f.product === oldId ) {
				next.product = wanted;
			}
			return next;
		} ),
	} ) );
	if ( out.notifications ) {
		out.notifications = out.notifications.map( ( n ) => ( { ...n, to: fixText( n.to ), reply_to: fixText( n.reply_to ), subject: fixText( n.subject ), message: fixText( n.message ), logic: fixLogic( n.logic ) } ) );
	}
	if ( out.confirmations ) {
		out.confirmations = out.confirmations.map( ( c ) => ( { ...c, message: fixText( c.message ), url: fixText( c.url ), logic: fixLogic( c.logic ) } ) );
	}
	return out;
}

export function removeField( def, id ) {
	return mapSteps( def, ( s ) => ( { ...s, fields: s.fields.filter( ( f ) => f.id !== id ) } ) );
}

export function duplicateField( def, id ) {
	const pos = locate( def, id );
	if ( ! pos ) {
		return { def, id };
	}
	const src = def.steps[ pos.step ].fields[ pos.index ];
	const copy = { ...src, id: uniqueId( def, src.id ), label: src.label };
	const next = mapSteps( def, ( s, i ) => {
		if ( i !== pos.step ) {
			return s;
		}
		const fields = [ ...s.fields ];
		fields.splice( pos.index + 1, 0, copy );
		return { ...s, fields };
	} );
	return { def: next, id: copy.id };
}

/**
 * Moves a field to a step/index.
 *
 * @param {Object} def   Definition.
 * @param {string} id    Field id.
 * @param {number} step  Target step.
 * @param {number} index Target index (in the target step, before removal is accounted for).
 * @return {Object} Definition.
 */
export function moveField( def, id, step, index ) {
	const pos = locate( def, id );
	if ( ! pos ) {
		return def;
	}
	const field = def.steps[ pos.step ].fields[ pos.index ];
	let target = index;
	if ( pos.step === step && pos.index < index ) {
		target = index - 1;
	}
	const removed = removeField( def, id );
	return mapSteps( removed, ( s, i ) => {
		if ( i !== step ) {
			return s;
		}
		const fields = [ ...s.fields ];
		fields.splice( Math.max( 0, Math.min( fields.length, target ) ), 0, field );
		return { ...s, fields };
	} );
}

/**
 * Moves a field one place up/down (crossing step boundaries).
 *
 * @param {Object} def Definition.
 * @param {string} id  Field id.
 * @param {number} dir -1 | 1.
 * @return {Object} Definition.
 */
export function nudgeField( def, id, dir ) {
	const pos = locate( def, id );
	if ( ! pos ) {
		return def;
	}
	const count = def.steps[ pos.step ].fields.length;
	if ( dir < 0 && pos.index === 0 ) {
		return pos.step > 0 ? moveField( def, id, pos.step - 1, def.steps[ pos.step - 1 ].fields.length ) : def;
	}
	if ( dir > 0 && pos.index === count - 1 ) {
		return pos.step < def.steps.length - 1 ? moveField( def, id, pos.step + 1, 0 ) : def;
	}
	return moveField( def, id, pos.step, dir < 0 ? pos.index - 1 : pos.index + 2 );
}

export function addStep( def, title = '' ) {
	const used = new Set( def.steps.map( ( s ) => s.id ) );
	let n = def.steps.length + 1;
	while ( used.has( `step_${ n }` ) ) {
		n++;
	}
	return { ...def, steps: [ ...def.steps, { id: `step_${ n }`, title, description: '', logic: null, fields: [] } ] };
}

/**
 * Removes a step; its fields move to the previous (or next) step.
 *
 * @param {Object} def   Definition.
 * @param {number} index Step index.
 * @return {Object} Definition.
 */
export function removeStep( def, index ) {
	if ( def.steps.length <= 1 ) {
		return def;
	}
	const moved = def.steps[ index ].fields;
	const steps = def.steps.filter( ( s, i ) => i !== index );
	const target = Math.max( 0, index - 1 );
	steps[ target ] = { ...steps[ target ], fields: index === 0 ? [ ...moved, ...steps[ target ].fields ] : [ ...steps[ target ].fields, ...moved ] };
	return { ...def, steps };
}

export function updateStep( def, index, patch ) {
	return mapSteps( def, ( s, i ) => ( i === index ? { ...s, ...patch } : s ) );
}

export function moveStep( def, index, dir ) {
	const to = index + dir;
	if ( to < 0 || to >= def.steps.length ) {
		return def;
	}
	const steps = [ ...def.steps ];
	[ steps[ index ], steps[ to ] ] = [ steps[ to ], steps[ index ] ];
	return { ...def, steps };
}

/**
 * Fields a given field may reference in logic (only earlier input fields).
 *
 * @param {Object} def Definition.
 * @param {string} id  Field id ('' = all fields, e.g. for notifications).
 * @return {Array} Fields.
 */
export function earlierFields( def, id = '' ) {
	const out = [];
	for ( const f of allFields( def ) ) {
		if ( id && f.id === id ) {
			break;
		}
		if ( ! [ 'heading', 'paragraph', 'divider' ].includes( f.type ) ) {
			out.push( f );
		}
	}
	return out;
}

/**
 * Fields before a step (for step logic).
 *
 * @param {Object} def   Definition.
 * @param {number} index Step index.
 * @return {Array} Fields.
 */
export function fieldsBeforeStep( def, index ) {
	return def.steps.slice( 0, index ).flatMap( ( s ) => s.fields ).filter( ( f ) => ! [ 'heading', 'paragraph', 'divider' ].includes( f.type ) );
}
