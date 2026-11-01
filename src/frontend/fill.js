/**
 * Fills form controls from AI results: only EMPTY fields, with highlight and a full undo.
 * It never submits the form. Ported from DEV3 AI Form Autofill & Spam Shield (dev3fa).
 */

export const FILLED_CLASS = 'dev3fb-filled';

const cssEscape = ( value ) => ( window.CSS && window.CSS.escape ? window.CSS.escape( value ) : String( value ).replace( /(["\\\]\[])/g, '\\$1' ) );

/**
 * Controls for a field name. Checkbox groups may be named "x[]" or "x.1", "x.2" (Gravity Forms).
 *
 * @param {HTMLFormElement} form Form.
 * @param {string}          name Name attribute.
 * @return {HTMLElement[]} Controls.
 */
export function controlsFor( form, name ) {
	const exact = Array.from( form.querySelectorAll( `[name="${ cssEscape( name ) }"]` ) );
	if ( exact.length ) {
		return exact.filter( usable );
	}
	const base = name.replace( /\[\]$/, '' );
	return Array.from( form.querySelectorAll( 'input[type="checkbox"], input[type="radio"]' ) ).filter(
		( el ) => usable( el ) && ( el.name === base || el.name === `${ base }[]` || el.name.startsWith( `${ base }.` ) )
	);
}

function usable( el ) {
	return ! el.disabled && ! el.readOnly && el.type !== 'hidden' && el.type !== 'file' && el.type !== 'submit';
}

const isChoice = ( el ) => el.type === 'checkbox' || el.type === 'radio';

/**
 * Whether a field (its controls) is empty.
 *
 * @param {HTMLElement[]} controls Controls.
 * @return {boolean} Empty.
 */
export function isEmpty( controls ) {
	if ( ! controls.length ) {
		return false;
	}
	if ( isChoice( controls[ 0 ] ) ) {
		return ! controls.some( ( el ) => el.checked );
	}
	const el = controls[ 0 ];
	if ( el.tagName === 'SELECT' ) {
		return Array.from( el.selectedOptions || [] ).every( ( o ) => o.value === '' );
	}
	return String( el.value || '' ).trim() === '';
}

function snapshot( controls ) {
	return controls.map( ( el ) => {
		if ( isChoice( el ) ) {
			return { checked: el.checked };
		}
		if ( el.tagName === 'SELECT' ) {
			return { selected: Array.from( el.options ).map( ( o ) => o.selected ) };
		}
		return { value: el.value };
	} );
}

function restore( controls, snap ) {
	controls.forEach( ( el, i ) => {
		const s = snap[ i ];
		if ( isChoice( el ) ) {
			el.checked = s.checked;
		} else if ( el.tagName === 'SELECT' ) {
			Array.from( el.options ).forEach( ( o, j ) => ( o.selected = s.selected[ j ] ) );
		} else {
			el.value = s.value;
		}
	} );
}

const norm = ( v ) => String( v ).trim().toLowerCase();

function emit( el ) {
	el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
}

/**
 * Writes one value into controls.
 *
 * @param {HTMLElement[]} controls Controls.
 * @param {string|string[]} value  Value(s).
 * @return {boolean} Whether anything was set.
 */
function write( controls, value ) {
	const values = ( Array.isArray( value ) ? value : [ value ] ).map( norm );
	const first = controls[ 0 ];
	if ( isChoice( first ) ) {
		let any = false;
		controls.forEach( ( el ) => {
			const label = el.labels && el.labels[ 0 ] ? el.labels[ 0 ].textContent : '';
			if ( values.includes( norm( el.value ) ) || ( label && values.includes( norm( label ) ) ) ) {
				if ( first.type === 'radio' && any ) {
					return;
				}
				el.checked = true;
				any = true;
			}
		} );
		return any;
	}
	if ( first.tagName === 'SELECT' ) {
		let any = false;
		Array.from( first.options ).forEach( ( o ) => {
			const match = o.value !== '' && ( values.includes( norm( o.value ) ) || values.includes( norm( o.textContent ) ) );
			if ( match && ( first.multiple || ! any ) ) {
				o.selected = true;
				any = true;
			} else if ( first.multiple ) {
				o.selected = false;
			}
		} );
		return any;
	}
	first.value = Array.isArray( value ) ? value.join( ', ' ) : String( value );
	return true;
}

/**
 * Fills EMPTY fields only.
 *
 * @param {HTMLFormElement} form  Form.
 * @param {Array<{name: string, value: (string|string[])}>} items AI results.
 * @return {Array} Undo record (one entry per filled field).
 */
export function applyFill( form, items ) {
	const record = [];
	( items || [] ).forEach( ( item ) => {
		if ( ! item || ! item.name ) {
			return;
		}
		const controls = controlsFor( form, item.name );
		if ( ! controls.length || ! isEmpty( controls ) ) {
			return;
		}
		const snap = snapshot( controls );
		if ( ! write( controls, item.value ) ) {
			restore( controls, snap );
			return;
		}
		controls.forEach( ( el ) => {
			if ( ! isChoice( el ) || el.checked ) {
				el.classList.add( FILLED_CLASS );
			}
		} );
		emit( controls[ 0 ] );
		record.push( { controls, snap } );
	} );
	return record;
}

/**
 * Reverts a fill. Fields the visitor edited since are left alone.
 *
 * @param {Array} record Undo record.
 * @return {number} Fields reverted.
 */
export function undoFill( record ) {
	let count = 0;
	( record || [] ).forEach( ( { controls, snap } ) => {
		if ( ! controls.some( ( el ) => el.classList.contains( FILLED_CLASS ) ) ) {
			return;
		}
		restore( controls, snap );
		controls.forEach( ( el ) => el.classList.remove( FILLED_CLASS ) );
		emit( controls[ 0 ] );
		count++;
	} );
	return count;
}
