/**
 * Front-end controller against markup shaped like includes/Frontend/Renderer.php output.
 */
import { initForm, readValues, validateField, endpoint } from '../../src/frontend/form';

const config = {
	id: 7,
	uid: 'dev3fb-7-1',
	analytics: false,
	symbol: '$',
	ttl: 604800,
	messages: { required: 'Required.', email: 'Bad email.', number: 'Number.', min: 'Too small.', max: 'Too big.', consent: 'Agree.', fix: 'Fix fields.' },
	steps: [ { id: 'step_1', logic: null }, { id: 'step_2', logic: null } ],
	visible: { service: true, other: false, email: true, ticket: true, qty: true, total: true },
	fields: [
		{ id: 'service', type: 'radio', step: 0, required: true, options: [ { value: 'Web' }, { value: 'Other' } ] },
		{ id: 'other', type: 'text', step: 0, required: true, logic: { enabled: true, action: 'show', match: 'all', rules: [ { field: 'service', op: 'is', value: 'Other' } ] } },
		{ id: 'email', type: 'email', step: 1, required: true },
		{ id: 'ticket', type: 'product', step: 1, required: false, options: [ { value: 'A', price: 10 }, { value: 'B', price: 25 } ] },
		{ id: 'qty', type: 'quantity', step: 1, required: false, product: 'ticket', min: 1 },
		{ id: 'total', type: 'total', step: 1, required: false },
	],
};

function mount( cfg = config ) {
	document.body.innerHTML = `
	<div class="dev3fb" id="dev3fb-7-1" data-dev3fb='${ JSON.stringify( cfg ) }'>
		<form class="dev3fb-form" novalidate>
			<input type="hidden" name="dev3fb_form_id" value="7">
			<input type="hidden" name="dev3fb_token" value="${ Math.floor( Date.now() / 1000 ) }.abc">
			<input type="hidden" name="dev3fb_session" value="">
			<div class="dev3fb-progress"><div class="dev3fb-progress-text">Step 1 of 2</div><div class="dev3fb-progress-track"><span></span></div></div>
			<div class="dev3fb-alert" hidden></div>
			<fieldset class="dev3fb-step" data-step="0" data-step-id="step_1">
				<fieldset class="dev3fb-field" data-field="service"><legend>Service</legend>
					<label><input type="radio" name="dev3fb[service]" value="Web">Web</label>
					<label><input type="radio" name="dev3fb[service]" value="Other">Other</label>
					<p class="dev3fb-error" hidden></p></fieldset>
				<div class="dev3fb-field" data-field="other" hidden><label for="o">Other</label><input id="o" name="dev3fb[other]"><p class="dev3fb-error" hidden></p></div>
			</fieldset>
			<fieldset class="dev3fb-step dev3fb-step--later" data-step="1" data-step-id="step_2">
				<div class="dev3fb-field" data-field="email"><input type="email" name="dev3fb[email]"><p class="dev3fb-error" hidden></p></div>
				<fieldset class="dev3fb-field" data-field="ticket">
					<label><input type="radio" name="dev3fb[ticket]" value="A">A</label>
					<label><input type="radio" name="dev3fb[ticket]" value="B">B</label></fieldset>
				<div class="dev3fb-field" data-field="qty"><input type="number" name="dev3fb[qty]" value="1"><p class="dev3fb-error" hidden></p></div>
				<div class="dev3fb-field" data-field="total"><output>$0.00</output></div>
			</fieldset>
			<div class="dev3fb-nav">
				<button type="button" class="dev3fb-prev" hidden>Back</button>
				<button type="button" class="dev3fb-next" hidden>Next</button>
				<button type="submit" class="dev3fb-submit">Submit</button>
			</div>
		</form>
		<div class="dev3fb-confirmation" hidden tabindex="-1"></div>
	</div>`;
	return initForm( document.querySelector( '.dev3fb' ) );
}

const $ = ( s ) => document.querySelector( s );
const choose = ( name, value ) => {
	const el = $( `input[name="${ name }"][value="${ value }"]` );
	el.checked = true;
	el.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
};
const type = ( name, value ) => {
	const el = $( `[name="${ name }"]` );
	el.value = value;
	el.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
};

beforeEach( () => {
	window.dev3fbConfig = { restUrl: '/wp-json/dev3fb/v1/', nonce: '' };
	window.scrollTo = jest.fn();
	window.HTMLElement.prototype.scrollIntoView = jest.fn();
} );
afterEach( () => {
	delete window.fetch;
} );

test( 'endpoint handles plain permalinks', () => {
	window.dev3fbConfig = { restUrl: '/index.php?rest_route=/dev3fb/v1/' };
	expect( endpoint( 'submit?form=7' ) ).toBe( '/index.php?rest_route=/dev3fb/v1/submit&form=7' );
} );

test( 'shows the first step only and the right buttons', () => {
	const ctl = mount();
	expect( ctl.current ).toBe( 0 );
	expect( $( '[data-step="1"]' ).hidden ).toBe( true );
	expect( $( '.dev3fb-next' ).hidden ).toBe( false );
	expect( $( '.dev3fb-submit' ).hidden ).toBe( true );
} );

test( 'conditional logic shows a field and validation blocks the step', () => {
	const ctl = mount();
	$( '.dev3fb-next' ).click();
	expect( ctl.current ).toBe( 0 );
	expect( $( '[data-field="service"] .dev3fb-error' ).textContent ).toBe( 'Required.' );
	choose( 'dev3fb[service]', 'Other' );
	expect( $( '[data-field="other"]' ).hidden ).toBe( false );
	$( '.dev3fb-next' ).click();
	expect( ctl.current ).toBe( 0 );
	type( 'dev3fb[other]', 'Logo design' );
	$( '.dev3fb-next' ).click();
	expect( ctl.current ).toBe( 1 );
	expect( $( '.dev3fb-submit' ).hidden ).toBe( false );
	expect( $( '.dev3fb-prev' ).hidden ).toBe( false );
	expect( $( '.dev3fb-progress-track' ).getAttribute( 'aria-valuenow' ) ).toBe( '100' );
} );

test( 'live total from product × quantity', () => {
	mount();
	choose( 'dev3fb[ticket]', 'B' );
	type( 'dev3fb[qty]', '3' );
	expect( $( '[data-field="total"] output' ).textContent ).toMatch( /\$75\.00/ );
} );

test( 'submit posts FormData and shows the confirmation', async () => {
	window.fetch = jest.fn( () => Promise.resolve( { status: 200, json: () => Promise.resolve( { ok: true, confirmation: { type: 'message', message: '<p>Thanks!</p>' } } ) } ) );
	mount();
	choose( 'dev3fb[service]', 'Web' );
	$( '.dev3fb-next' ).click();
	type( 'dev3fb[email]', 'ann@example.com' );
	$( 'form' ).dispatchEvent( new window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	expect( window.fetch ).toHaveBeenCalledTimes( 1 );
	expect( window.fetch.mock.calls[ 0 ][ 0 ] ).toBe( '/wp-json/dev3fb/v1/submit?form=7' );
	expect( window.fetch.mock.calls[ 0 ][ 1 ].body ).toBeInstanceOf( window.FormData );
	expect( $( 'form' ).hidden ).toBe( true );
	expect( $( '.dev3fb-confirmation' ).innerHTML ).toBe( '<p>Thanks!</p>' );
} );

test( 'server errors are shown on their fields and step', async () => {
	window.fetch = jest.fn( () => Promise.resolve( { status: 422, json: () => Promise.resolve( { ok: false, message: 'Fix fields.', errors: { email: 'Bad email.' }, step: 1 } ) } ) );
	const ctl = mount();
	choose( 'dev3fb[service]', 'Web' );
	$( '.dev3fb-next' ).click();
	type( 'dev3fb[email]', 'ann@example.com' );
	$( 'form' ).dispatchEvent( new window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	expect( ctl.current ).toBe( 1 );
	expect( $( '[data-field="email"] .dev3fb-error' ).textContent ).toBe( 'Bad email.' );
	expect( $( '.dev3fb-alert' ).hidden ).toBe( false );
} );

test( 'readValues and validateField', () => {
	mount();
	choose( 'dev3fb[service]', 'Web' );
	const values = readValues( $( 'form' ), config.fields );
	expect( values.service ).toBe( 'Web' );
	expect( values.qty ).toBe( '1' );
	const m = config.messages;
	expect( validateField( { type: 'email', required: true }, 'nope', m ) ).toBe( 'Bad email.' );
	expect( validateField( { type: 'email', required: false }, '', m ) ).toBe( '' );
	expect( validateField( { type: 'number', min: 2 }, '1', m ) ).toBe( 'Too small.' );
	expect( validateField( { type: 'name', required: true }, { first: 'A', last: '' }, m ) ).toBe( 'Required.' );
	expect( validateField( { type: 'consent', required: true }, '', m ) ).toBe( 'Agree.' );
} );
