import { applyFill, undoFill, isEmpty, controlsFor, FILLED_CLASS } from '../../src/frontend/fill';

function makeForm() {
	document.body.innerHTML = `
		<form id="f">
			<input name="your-name" value="">
			<input name="your-email" type="email" value="kept@example.com">
			<input name="phone" type="tel" value="">
			<select name="topic">
				<option value="">— choose —</option>
				<option value="Sales">Sales</option>
				<option value="Support">Support</option>
			</select>
			<input type="radio" name="size" value="S"><input type="radio" name="size" value="L">
			<input type="checkbox" name="interests[]" value="Design"><input type="checkbox" name="interests[]" value="SEO">
			<input type="checkbox" name="input_7.1" value="Red"><input type="checkbox" name="input_7.2" value="Blue">
			<input name="readonly" readonly value="">
			<textarea name="msg">Hello there</textarea>
		</form>`;
	return document.getElementById( 'f' );
}

describe( 'applyFill', () => {
	test( 'fills only empty fields and highlights them', () => {
		const form = makeForm();
		const record = applyFill( form, [
			{ name: 'your-name', value: 'Jane Doe' },
			{ name: 'your-email', value: 'other@example.com' },
			{ name: 'phone', value: '+15550199123' },
			{ name: 'msg', value: 'overwritten?' },
		] );
		expect( form.elements[ 'your-name' ].value ).toBe( 'Jane Doe' );
		expect( form.elements[ 'your-email' ].value ).toBe( 'kept@example.com' );
		expect( form.elements.msg.value ).toBe( 'Hello there' );
		expect( form.elements.phone.classList.contains( FILLED_CLASS ) ).toBe( true );
		expect( form.elements[ 'your-email' ].classList.contains( FILLED_CLASS ) ).toBe( false );
		expect( record ).toHaveLength( 2 );
	} );

	test( 'selects, radios and checkbox groups (incl. Gravity-style names) match by value or label', () => {
		const form = makeForm();
		const record = applyFill( form, [
			{ name: 'topic', value: 'support' },
			{ name: 'size', value: 'L' },
			{ name: 'interests[]', value: [ 'SEO', 'Unknown' ] },
			{ name: 'input_7', value: [ 'Blue' ] },
		] );
		expect( form.elements.topic.value ).toBe( 'Support' );
		expect( form.querySelector( 'input[name="size"][value="L"]' ).checked ).toBe( true );
		expect( form.querySelector( 'input[value="SEO"]' ).checked ).toBe( true );
		expect( form.querySelector( 'input[value="Design"]' ).checked ).toBe( false );
		expect( form.querySelector( 'input[name="input_7.2"]' ).checked ).toBe( true );
		expect( record ).toHaveLength( 4 );
	} );

	test( 'skips unknown options, unknown fields and read-only controls', () => {
		const form = makeForm();
		const record = applyFill( form, [
			{ name: 'topic', value: 'Billing' },
			{ name: 'does-not-exist', value: 'x' },
			{ name: 'readonly', value: 'x' },
		] );
		expect( record ).toHaveLength( 0 );
		expect( form.elements.topic.value ).toBe( '' );
		expect( form.elements.readonly.value ).toBe( '' );
	} );

	test( 'fires input/change events so builders notice the values', () => {
		const form = makeForm();
		const seen = [];
		form.addEventListener( 'change', ( e ) => seen.push( e.target.name ) );
		applyFill( form, [ { name: 'your-name', value: 'Jane' } ] );
		expect( seen ).toEqual( [ 'your-name' ] );
	} );

	test( 'never submits the form', () => {
		const form = makeForm();
		const submit = jest.fn( ( e ) => e.preventDefault() );
		form.addEventListener( 'submit', submit );
		applyFill( form, [ { name: 'your-name', value: 'Jane' } ] );
		expect( submit ).not.toHaveBeenCalled();
	} );
} );

describe( 'undoFill', () => {
	test( 'restores every filled field and removes highlights', () => {
		const form = makeForm();
		const record = applyFill( form, [
			{ name: 'your-name', value: 'Jane' },
			{ name: 'topic', value: 'Sales' },
			{ name: 'interests[]', value: [ 'Design' ] },
		] );
		expect( undoFill( record ) ).toBe( 3 );
		expect( form.elements[ 'your-name' ].value ).toBe( '' );
		expect( form.elements.topic.value ).toBe( '' );
		expect( form.querySelector( 'input[value="Design"]' ).checked ).toBe( false );
		expect( form.querySelectorAll( `.${ FILLED_CLASS }` ) ).toHaveLength( 0 );
	} );

	test( 'leaves fields the visitor edited after the fill', () => {
		const form = makeForm();
		const record = applyFill( form, [
			{ name: 'your-name', value: 'Jane' },
			{ name: 'phone', value: '+15550199123' },
		] );
		form.elements[ 'your-name' ].value = 'Janet';
		form.elements[ 'your-name' ].classList.remove( FILLED_CLASS ); // What the runtime does on a trusted input event.
		expect( undoFill( record ) ).toBe( 1 );
		expect( form.elements[ 'your-name' ].value ).toBe( 'Janet' );
		expect( form.elements.phone.value ).toBe( '' );
	} );
} );

describe( 'helpers', () => {
	test( 'isEmpty and controlsFor', () => {
		const form = makeForm();
		expect( isEmpty( controlsFor( form, 'your-name' ) ) ).toBe( true );
		expect( isEmpty( controlsFor( form, 'msg' ) ) ).toBe( false );
		expect( isEmpty( controlsFor( form, 'topic' ) ) ).toBe( true );
		expect( controlsFor( form, 'input_7' ) ).toHaveLength( 2 );
		expect( controlsFor( form, 'readonly' ) ).toHaveLength( 0 );
	} );
} );
