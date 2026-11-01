/**
 * Front-end form controller: multi-step navigation, conditional logic, live calculations,
 * client-side validation, AJAX submit, save-and-continue, paste-to-fill, signature pads and
 * privacy-light funnel analytics. The server re-validates everything.
 */
import { evaluate as evaluateLogic } from '../shared/logic';
import { run as compute } from '../shared/compute';
import { applyFill, undoFill } from './fill';
import { signaturePad } from './signature';

const boot = () => window.dev3fbConfig || {};

/**
 * REST URL that works with plain and pretty permalinks.
 *
 * @param {string} route Route.
 * @return {string} URL.
 */
export function endpoint( route ) {
	const base = boot().restUrl || '/wp-json/dev3fb/v1/';
	const [ path, query ] = route.split( '?' );
	const url = base + path;
	return query ? url + ( url.includes( '?' ) ? '&' : '?' ) + query : url;
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

function randomId() {
	const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	let out = '';
	const bytes = window.crypto && window.crypto.getRandomValues ? window.crypto.getRandomValues( new Uint8Array( 32 ) ) : null;
	for ( let i = 0; i < 32; i++ ) {
		out += chars[ ( bytes ? bytes[ i ] : Math.floor( Math.random() * 256 ) ) % chars.length ];
	}
	return out;
}

function formatMoney( amount, symbol ) {
	const n = Number( amount || 0 );
	return ( symbol || '' ) + n.toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
}

/**
 * Reads the current value of every field.
 *
 * @param {HTMLFormElement} form   Form.
 * @param {Array}           fields Config fields.
 * @return {Object} id => value.
 */
export function readValues( form, fields ) {
	const values = {};
	fields.forEach( ( f ) => {
		const base = `dev3fb[${ f.id }]`;
		const q = ( sel ) => form.querySelectorAll( sel );
		switch ( f.type ) {
			case 'checkbox':
				values[ f.id ] = Array.from( q( `input[name="${ base }[]"]:checked` ) ).map( ( el ) => el.value );
				break;
			case 'multiselect': {
				const sel = form.querySelector( `select[name="${ base }[]"]` );
				values[ f.id ] = sel ? Array.from( sel.selectedOptions ).map( ( o ) => o.value ) : [];
				break;
			}
			case 'radio':
			case 'image_choice':
			case 'rating':
			case 'nps':
			case 'scale': {
				const el = form.querySelector( `input[name="${ base }"]:checked` );
				values[ f.id ] = el ? el.value : '';
				break;
			}
			case 'product': {
				if ( ! f.options || ! f.options.length ) {
					values[ f.id ] = '1';
					break;
				}
				const radio = form.querySelector( `input[name="${ base }"]:checked` );
				values[ f.id ] = radio ? radio.value : '';
				break;
			}
			case 'toggle':
			case 'consent': {
				const el = form.querySelector( `input[name="${ base }"]` );
				values[ f.id ] = el && el.checked ? '1' : '';
				break;
			}
			case 'name':
			case 'address': {
				const parts = f.type === 'name' ? [ 'first', 'last' ] : [ 'line1', 'line2', 'city', 'state', 'zip', 'country' ];
				const obj = {};
				parts.forEach( ( p ) => {
					const el = form.querySelector( `[name="${ base }[${ p }]"]` );
					obj[ p ] = el ? el.value.trim() : '';
				} );
				values[ f.id ] = obj;
				break;
			}
			case 'file': {
				const el = form.querySelector( `input[name="dev3fb_files_${ f.id }[]"]` );
				values[ f.id ] = el && el.files ? Array.from( el.files ).map( ( file ) => file.name ) : [];
				break;
			}
			case 'heading':
			case 'paragraph':
			case 'divider':
			case 'calculation':
			case 'total':
				break;
			default: {
				const el = form.querySelector( `[name="${ base }"]` );
				values[ f.id ] = el ? String( el.value ).trim() : '';
			}
		}
	} );
	return values;
}

const isEmptyValue = ( f, v ) => {
	if ( f.type === 'name' ) {
		return ! v || ( ! v.first && ! v.last );
	}
	if ( f.type === 'address' ) {
		return ! v || Object.values( v ).every( ( x ) => ! x );
	}
	return v === undefined || v === null || v === '' || ( Array.isArray( v ) && ! v.length );
};

/**
 * Client-side validation for one field (mirrors the server's main rules).
 *
 * @param {Object}          f        Field config.
 * @param {*}               v        Value.
 * @param {Object}          messages Messages.
 * @param {HTMLFormElement} form     Form.
 * @return {string} Error or ''.
 */
export function validateField( f, v, messages, form ) {
	const m = messages || {};
	if ( f.type === 'signature' ) {
		const input = form && form.querySelector( `input[name="dev3fb[${ f.id }]"]` );
		return f.required && input && ! input.value ? m.required : '';
	}
	if ( isEmptyValue( f, v ) ) {
		if ( ! f.required ) {
			return '';
		}
		return f.type === 'consent' ? m.consent : m.required;
	}
	if ( f.type === 'name' && f.required && ( ! v.first || ! v.last ) ) {
		return m.required;
	}
	if ( f.type === 'address' && f.required && ( ! v.line1 || ! v.city ) ) {
		return m.required;
	}
	if ( f.type === 'email' && ! EMAIL.test( v ) ) {
		return m.email;
	}
	if ( f.type === 'phone' ) {
		const digits = String( v ).replace( /\D/g, '' ).length;
		if ( digits < 6 || digits > 20 ) {
			return m.phone;
		}
	}
	if ( f.type === 'url' && ! /^(https?:\/\/)?[^\s/$.?#]+\.[^\s]+$/i.test( v ) ) {
		return m.url;
	}
	if ( [ 'number', 'quantity', 'amount', 'range' ].includes( f.type ) ) {
		const n = Number( v );
		if ( ! Number.isFinite( n ) || String( v ).trim() === '' ) {
			return m.number;
		}
		if ( f.min !== undefined && f.min !== '' && n < Number( f.min ) ) {
			return m.min;
		}
		if ( f.max !== undefined && f.max !== '' && n > Number( f.max ) ) {
			return m.max;
		}
	}
	if ( ( f.type === 'text' || f.type === 'textarea' ) && f.maxlength && String( v ).length > f.maxlength ) {
		return m.length;
	}
	if ( f.type === 'file' && form ) {
		const el = form.querySelector( `input[name="dev3fb_files_${ f.id }[]"]` );
		const files = el && el.files ? Array.from( el.files ) : [];
		if ( f.max_files && files.length > f.max_files ) {
			return m.max;
		}
		const allowed = String( f.accept || '' ).split( ',' ).filter( Boolean );
		for ( const file of files ) {
			const ext = ( file.name.split( '.' ).pop() || '' ).toLowerCase();
			if ( allowed.length && ! allowed.includes( ext ) ) {
				return m.choice;
			}
			if ( f.max_size && file.size > f.max_size * 1024 * 1024 ) {
				return m.max;
			}
		}
	}
	return '';
}

/**
 * Initialises one form wrapper.
 *
 * @param {HTMLElement} root .dev3fb[data-dev3fb] element.
 * @return {Object|null} Controller (for tests).
 */
export function initForm( root ) {
	if ( ! root || root.dataset.dev3fbReady ) {
		return null;
	}
	let config;
	try {
		config = JSON.parse( root.dataset.dev3fb || '{}' );
	} catch ( e ) {
		return null;
	}
	const form = root.querySelector( 'form.dev3fb-form' );
	if ( ! form ) {
		return null;
	}
	root.dataset.dev3fbReady = '1';
	root.classList.add( 'dev3fb-js' );

	const fields = config.fields || [];
	const steps = Array.from( form.querySelectorAll( '.dev3fb-step' ) );
	const messages = config.messages || {};
	const alertBox = form.querySelector( '.dev3fb-alert' );
	const confirmation = root.querySelector( '.dev3fb-confirmation' );
	const prev = form.querySelector( '.dev3fb-prev' );
	const next = form.querySelector( '.dev3fb-next' );
	const submit = form.querySelector( '.dev3fb-submit' );
	const saveBtn = form.querySelector( '.dev3fb-save' );
	const savedBox = form.querySelector( '.dev3fb-saved' );
	const definition = {
		steps: ( config.steps || [] ).map( ( s, i ) => ( {
			id: s.id,
			logic: s.logic,
			fields: fields.filter( ( f ) => f.step === i ),
		} ) ),
	};
	let current = Math.max( 0, steps.findIndex( ( s ) => ! s.classList.contains( 'dev3fb-step--later' ) && ! s.hidden ) );
	let state = { fields: config.visible || {}, steps: {} };
	let submitted = false;
	let started = false;
	let lastField = '';

	// Analytics (anonymous random session; no values are sent).
	const sessionKey = `dev3fb_s_${ config.id }`;
	let session = '';
	try {
		session = window.sessionStorage.getItem( sessionKey ) || '';
		if ( ! session ) {
			session = randomId();
			window.sessionStorage.setItem( sessionKey, session );
		}
	} catch ( e ) {
		session = randomId();
	}
	const sessionInput = form.querySelector( 'input[name="dev3fb_session"]' );
	if ( sessionInput && config.analytics ) {
		sessionInput.value = session;
	}
	const queue = [];
	let timer = null;
	const flush = ( beacon = false ) => {
		if ( ! config.analytics || ! queue.length ) {
			return;
		}
		const body = JSON.stringify( { form: config.id, session, events: queue.splice( 0, queue.length ) } );
		const url = endpoint( `track?form=${ config.id }` );
		if ( beacon && navigator.sendBeacon ) {
			navigator.sendBeacon( url, new window.Blob( [ body ], { type: 'application/json' } ) );
			return;
		}
		if ( window.fetch ) {
			window.fetch( url, { method: 'POST', body, headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', keepalive: true } ).catch( () => {} );
		}
	};
	const track = ( event, step = 0, field = '' ) => {
		if ( ! config.analytics ) {
			return;
		}
		queue.push( { event, step, field } );
		clearTimeout( timer );
		timer = setTimeout( () => flush(), 1500 );
	};

	const fieldEl = ( id ) => form.querySelector( `[data-field="${ id }"]` );
	const visibleSteps = () => steps.filter( ( s ) => state.steps[ s.dataset.stepId ] !== false );

	const setError = ( id, message ) => {
		const el = fieldEl( id );
		if ( ! el ) {
			return;
		}
		const box = el.querySelector( '.dev3fb-error' );
		el.classList.toggle( 'dev3fb-has-error', !! message );
		if ( box ) {
			box.textContent = message || '';
			box.hidden = ! message;
		}
		el.querySelectorAll( 'input, select, textarea' ).forEach( ( input ) => {
			if ( input.type === 'hidden' ) {
				return;
			}
			if ( message ) {
				input.setAttribute( 'aria-invalid', 'true' );
			} else {
				input.removeAttribute( 'aria-invalid' );
			}
		} );
	};

	const showAlert = ( text ) => {
		if ( ! alertBox ) {
			return;
		}
		alertBox.textContent = text || '';
		alertBox.hidden = ! text;
	};

	const updateNav = () => {
		const vis = visibleSteps();
		const index = vis.indexOf( steps[ current ] );
		const multi = steps.length > 1;
		steps.forEach( ( s, i ) => {
			const shown = ! multi || i === current;
			s.classList.toggle( 'dev3fb-step--later', ! shown );
			s.hidden = state.steps[ s.dataset.stepId ] === false || ! shown;
		} );
		if ( multi ) {
			const last = index === vis.length - 1;
			if ( prev ) {
				prev.hidden = index <= 0;
			}
			if ( next ) {
				next.hidden = last;
			}
			if ( submit ) {
				submit.hidden = ! last;
			}
		}
		if ( saveBtn ) {
			saveBtn.hidden = false;
		}
		const bar = form.querySelector( '.dev3fb-progress-track' );
		if ( bar ) {
			const pct = Math.round( ( 100 * ( index + 1 ) ) / Math.max( 1, vis.length ) );
			bar.setAttribute( 'aria-valuenow', String( pct ) );
			const fill = bar.querySelector( 'span' );
			if ( fill ) {
				fill.style.width = `${ pct }%`;
			}
			const text = form.querySelector( '.dev3fb-progress-text' );
			if ( text ) {
				text.textContent = text.textContent.replace( /\d+(\D+)\d+/, `${ index + 1 }$1${ vis.length }` );
			}
		}
		form.querySelectorAll( '.dev3fb-progress-steps li' ).forEach( ( li ) => {
			const i = Number( li.dataset.step );
			li.hidden = state.steps[ steps[ i ].dataset.stepId ] === false;
			if ( i === current ) {
				li.setAttribute( 'aria-current', 'step' );
			} else {
				li.removeAttribute( 'aria-current' );
			}
			li.classList.toggle( 'is-done', vis.indexOf( steps[ i ] ) < index && vis.includes( steps[ i ] ) );
		} );
	};

	const update = () => {
		const values = readValues( form, fields );
		state = evaluateLogic( definition, values );
		fields.forEach( ( f ) => {
			const el = fieldEl( f.id );
			if ( el && f.type !== 'hidden' ) {
				el.hidden = ! state.fields[ f.id ];
			}
		} );
		const result = compute( fields, state.values, state.fields );
		fields.forEach( ( f ) => {
			if ( f.type !== 'calculation' && f.type !== 'total' ) {
				return;
			}
			const out = form.querySelector( `[data-field="${ f.id }"] output` );
			if ( ! out ) {
				return;
			}
			const v = Number( result.values[ f.id ] || 0 );
			out.textContent = f.type === 'total'
				? formatMoney( v, config.symbol )
				: `${ f.prefix || '' }${ v.toLocaleString( undefined, { minimumFractionDigits: 0, maximumFractionDigits: f.decimals === undefined ? 2 : f.decimals } ) }${ f.suffix || '' }`;
		} );
		form.querySelectorAll( '.dev3fb-range' ).forEach( ( wrap ) => {
			const input = wrap.querySelector( 'input' );
			const out = wrap.querySelector( 'output' );
			const f = fields.find( ( x ) => input && input.name === `dev3fb[${ x.id }]` );
			if ( input && out ) {
				out.textContent = `${ ( f && f.prefix ) || '' }${ input.value }${ ( f && f.suffix ) || '' }`;
			}
		} );
		updateNav();
		return { values: result.values, total: result.total };
	};

	const validateStep = ( index ) => {
		const values = readValues( form, fields );
		let first = null;
		fields.forEach( ( f ) => {
			if ( ( index !== null && f.step !== index ) || ! state.fields[ f.id ] ) {
				if ( index === null || f.step === index ) {
					setError( f.id, '' );
				}
				return;
			}
			const error = validateField( f, values[ f.id ], messages, form );
			setError( f.id, error );
			if ( error && ! first ) {
				first = f;
			}
		} );
		if ( first ) {
			const el = fieldEl( first.id );
			const input = el && el.querySelector( 'input:not([type="hidden"]), select, textarea, canvas' );
			if ( input && input.focus ) {
				input.focus();
			}
			return false;
		}
		return true;
	};

	const goTo = ( index, focus = true ) => {
		current = Math.max( 0, Math.min( steps.length - 1, index ) );
		updateNav();
		track( 'step', current );
		if ( focus ) {
			const legend = steps[ current ].querySelector( 'legend, input, select, textarea' );
			if ( legend ) {
				if ( legend.tagName === 'LEGEND' ) {
					legend.setAttribute( 'tabindex', '-1' );
				}
				legend.focus( { preventScroll: true } );
			}
			const top = root.getBoundingClientRect().top + window.scrollY - 24;
			if ( window.scrollY > top ) {
				window.scrollTo( { top, behavior: 'smooth' } );
			}
		}
	};

	const step = ( dir ) => {
		const vis = visibleSteps();
		const index = vis.indexOf( steps[ current ] );
		if ( dir > 0 && ! validateStep( current ) ) {
			return;
		}
		const target = vis[ index + dir ];
		if ( target ) {
			showAlert( '' );
			goTo( steps.indexOf( target ) );
		}
	};

	if ( prev ) {
		prev.addEventListener( 'click', () => step( -1 ) );
	}
	if ( next ) {
		next.addEventListener( 'click', () => step( 1 ) );
	}

	// Enter in a text input moves to the next step instead of submitting early.
	form.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Enter' && e.target.tagName === 'INPUT' && steps.length > 1 && next && ! next.hidden ) {
			e.preventDefault();
			step( 1 );
		}
	} );

	const onInput = ( e ) => {
		const wrap = e.target.closest ? e.target.closest( '[data-field]' ) : null;
		if ( wrap ) {
			lastField = wrap.dataset.field;
			if ( wrap.classList.contains( 'dev3fb-has-error' ) ) {
				const f = fields.find( ( x ) => x.id === wrap.dataset.field );
				if ( f ) {
					setError( f.id, validateField( f, readValues( form, [ f ] )[ f.id ], messages, form ) );
				}
			}
		}
		if ( ! started ) {
			started = true;
			track( 'start', current );
		}
		update();
	};
	form.addEventListener( 'input', onInput );
	form.addEventListener( 'change', onInput );
	form.addEventListener( 'focusin', ( e ) => {
		const wrap = e.target.closest ? e.target.closest( '[data-field]' ) : null;
		if ( wrap ) {
			lastField = wrap.dataset.field;
		}
	} );

	form.querySelectorAll( '.dev3fb-signature' ).forEach( ( wrap ) => signaturePad( wrap, () => update() ) );

	// Token refresh for long-open/cached pages.
	const tokenInput = form.querySelector( 'input[name="dev3fb_token"]' );
	const freshToken = () => {
		const issued = Number( String( tokenInput ? tokenInput.value : '' ).split( '.' )[ 0 ] || 0 );
		const age = Date.now() / 1000 - issued;
		if ( ! tokenInput || age < ( config.ttl || 604800 ) - 3600 ) {
			return Promise.resolve();
		}
		return window
			.fetch( endpoint( `token?form=${ config.id }` ), { method: 'POST', credentials: 'same-origin' } )
			.then( ( r ) => r.json() )
			.then( ( data ) => {
				if ( data && data.token ) {
					tokenInput.value = data.token;
				}
			} )
			.catch( () => {} );
	};

	const headers = () => ( boot().nonce ? { 'X-WP-Nonce': boot().nonce } : {} );

	const setBusy = ( busy ) => {
		form.classList.toggle( 'is-busy', busy );
		[ submit, next, prev ].forEach( ( b ) => {
			if ( b ) {
				b.disabled = busy;
			}
		} );
		if ( submit ) {
			submit.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		}
	};

	const showConfirmation = ( html ) => {
		form.hidden = true;
		if ( confirmation ) {
			confirmation.innerHTML = html;
			confirmation.hidden = false;
			confirmation.focus( { preventScroll: true } );
			const top = root.getBoundingClientRect().top + window.scrollY - 24;
			if ( window.scrollY > top ) {
				window.scrollTo( { top, behavior: 'smooth' } );
			}
		}
	};

	form.addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		if ( form.classList.contains( 'is-busy' ) ) {
			return;
		}
		update();
		if ( ! validateStep( null ) ) {
			const bad = fields.find( ( f ) => fieldEl( f.id ) && fieldEl( f.id ).classList.contains( 'dev3fb-has-error' ) );
			if ( bad && bad.step !== current ) {
				goTo( bad.step, false );
				validateStep( bad.step );
			}
			showAlert( messages.fix || '' );
			return;
		}
		setBusy( true );
		showAlert( '' );
		freshToken()
			.then( () => {
				const data = new window.FormData( form );
				return window.fetch( endpoint( `submit?form=${ config.id }` ), { method: 'POST', body: data, credentials: 'same-origin', headers: headers() } );
			} )
			.then( ( r ) => r.json().then( ( body ) => ( { status: r.status, body } ) ) )
			.then( ( { body } ) => {
				if ( body && body.ok ) {
					submitted = true;
					queue.length = 0;
					if ( body.redirect || ( body.confirmation && body.confirmation.type === 'redirect' ) ) {
						window.location.assign( body.redirect || body.confirmation.url );
						return;
					}
					showConfirmation( ( body.confirmation && body.confirmation.message ) || '' );
					root.dispatchEvent( new window.CustomEvent( 'dev3fb:submitted', { bubbles: true, detail: { form: config.id } } ) );
					return;
				}
				const errors = ( body && body.errors ) || {};
				Object.keys( errors ).forEach( ( id ) => setError( id, errors[ id ] ) );
				if ( Object.keys( errors ).length && typeof body.step === 'number' && body.step !== current ) {
					goTo( body.step, false );
				}
				showAlert( ( body && body.message ) || messages.required || '' );
			} )
			.catch( () => showAlert( messages.network || 'Something went wrong. Please try again.' ) )
			.finally( () => setBusy( false ) );
	} );

	// Save and continue later.
	if ( saveBtn && savedBox ) {
		saveBtn.addEventListener( 'click', () => {
			const data = new window.FormData( form );
			data.append( 'dev3fb_step', String( current ) );
			const email = fields.find( ( f ) => f.type === 'email' );
			const emailValue = email ? readValues( form, [ email ] )[ email.id ] : '';
			if ( emailValue && EMAIL.test( emailValue ) ) {
				data.append( 'dev3fb_save_email', emailValue );
			}
			saveBtn.disabled = true;
			window
				.fetch( endpoint( `save?form=${ config.id }` ), { method: 'POST', body: data, credentials: 'same-origin' } )
				.then( ( r ) => r.json() )
				.then( ( body ) => {
					if ( ! body || ! body.link ) {
						throw new Error( 'save' );
					}
					const resume = form.querySelector( 'input[name="dev3fb_resume"]' );
					if ( resume ) {
						resume.value = body.token;
					}
					savedBox.innerHTML = '';
					const p = document.createElement( 'p' );
					p.textContent = body.sent ? messages.saved_sent || 'Saved. We emailed you a link to continue:' : messages.saved || 'Saved. Use this link to continue later:';
					const row = document.createElement( 'div' );
					row.className = 'dev3fb-saved-row';
					const input = document.createElement( 'input' );
					input.className = 'dev3fb-input';
					input.readOnly = true;
					input.value = body.link;
					input.setAttribute( 'aria-label', p.textContent );
					const copy = document.createElement( 'button' );
					copy.type = 'button';
					copy.className = 'dev3fb-btn dev3fb-btn--small dev3fb-btn--ghost';
					copy.textContent = messages.copy || 'Copy link';
					copy.addEventListener( 'click', () => {
						input.select();
						if ( navigator.clipboard ) {
							navigator.clipboard.writeText( body.link ).catch( () => {} );
						}
					} );
					row.append( input, copy );
					savedBox.append( p, row );
					savedBox.hidden = false;
					savedBox.setAttribute( 'role', 'status' );
				} )
				.catch( () => showAlert( messages.network || 'Something went wrong. Please try again.' ) )
				.finally( () => {
					saveBtn.disabled = false;
				} );
		} );
	}

	// Paste-to-fill.
	const paste = form.querySelector( '.dev3fb-paste' );
	if ( paste ) {
		const toggle = paste.querySelector( '.dev3fb-paste-toggle' );
		const panel = paste.querySelector( '.dev3fb-paste-panel' );
		const text = paste.querySelector( 'textarea' );
		const fillBtn = paste.querySelector( '.dev3fb-paste-fill' );
		const undoBtn = paste.querySelector( '.dev3fb-paste-undo' );
		const status = paste.querySelector( '.dev3fb-paste-status' );
		let record = [];
		toggle.addEventListener( 'click', () => {
			const open = panel.hidden;
			panel.hidden = ! open;
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			if ( open ) {
				text.focus();
			}
		} );
		fillBtn.addEventListener( 'click', () => {
			if ( String( text.value ).trim().length < 10 ) {
				status.textContent = messages.paste_short || 'Paste a little more text first.';
				return;
			}
			fillBtn.disabled = true;
			status.textContent = messages.paste_busy || 'Reading your details…';
			window
				.fetch( endpoint( `parse?form=${ config.id }` ), {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { form: config.id, token: tokenInput ? tokenInput.value : '', text: text.value, lang: ( form.querySelector( 'input[name="dev3fb_lang"]' ) || {} ).value || '' } ),
				} )
				.then( ( r ) => r.json() )
				.then( ( body ) => {
					if ( ! body || ! Array.isArray( body.fields ) ) {
						throw new Error( ( body && body.message ) || 'parse' );
					}
					record = applyFill( form, body.fields );
					update();
					status.textContent = record.length ? ( messages.paste_done || 'Filled %d fields. Please check them.' ).replace( '%d', record.length ) : messages.paste_none || 'No new details found.';
					undoBtn.hidden = ! record.length;
				} )
				.catch( ( err ) => {
					status.textContent = err && err.message && err.message !== 'parse' ? err.message : messages.paste_error || 'Autofill is unavailable right now.';
				} )
				.finally( () => {
					fillBtn.disabled = false;
				} );
		} );
		undoBtn.addEventListener( 'click', () => {
			undoFill( record );
			record = [];
			undoBtn.hidden = true;
			status.textContent = messages.paste_undone || 'Undone.';
			update();
		} );
	}

	// View / abandon tracking.
	if ( config.analytics ) {
		if ( window.IntersectionObserver ) {
			const io = new window.IntersectionObserver( ( entries ) => {
				if ( entries.some( ( en ) => en.isIntersecting ) ) {
					track( 'view', 0 );
					io.disconnect();
				}
			}, { threshold: 0.3 } );
			io.observe( root );
		} else {
			track( 'view', 0 );
		}
		const leave = () => {
			if ( started && ! submitted ) {
				queue.push( { event: 'abandon', step: current, field: lastField } );
			}
			flush( true );
		};
		window.addEventListener( 'pagehide', leave );
		document.addEventListener( 'visibilitychange', () => {
			if ( document.visibilityState === 'hidden' ) {
				leave();
			}
		} );
	}

	update();
	if ( steps.length > 1 ) {
		track( 'step', current );
	}

	return { config, form, update, step, goTo, validateStep, readValues: () => readValues( form, fields ), flush, queue, get current() {
		return current;
	} };
}

/**
 * Boots every form on the page.
 */
export function initAll() {
	document.querySelectorAll( '.dev3fb[data-dev3fb]' ).forEach( initForm );
}
