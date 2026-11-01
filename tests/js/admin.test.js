import apiFetch from '@wordpress/api-fetch';
import { ToastProvider } from '../../src/core3/ui';
import Builder from '../../src/admin/builder/Builder';
import { DescribeForm } from '../../src/admin/NewForm';
import EntryDrawer from '../../src/admin/EntryDrawer';
import { change, click, render } from './render';

const definition = {
	version: 1,
	steps: [ { id: 'step_1', title: '', description: '', logic: null, fields: [ { id: 'email', type: 'email', label: 'Email', placeholder: '', help: '', required: true, width: 'full', hide_label: false, default: '', css: '', role: '', logic: null } ] } ],
	settings: { submit_label: 'Submit', next_label: 'Next', prev_label: 'Back', save_label: 'Save', progress: 'bar', save_continue: false, limit_entries: 0, open_at: '', close_at: '', closed_message: '', require_login: false, login_message: '', store_entries: true, accent: '', css_class: '', show_title: false, description: '' },
	confirmations: [ { id: 'confirmation_1', name: 'Default', type: 'message', message: 'Thanks', url: '', page_id: 0, logic: null } ],
	notifications: [],
	webhooks: [],
	integrations: { consent_field: '', mailchimp: { enabled: false, list_id: '', tags: '', double: false, map: {} }, brevo: { enabled: false, list_id: '', tags: '', double: false, map: {} }, hubspot: { enabled: false, list_id: '', tags: '', double: false, map: {} }, sheets: { enabled: false, url: '' }, zapier: { enabled: false, url: '' } },
	payments: { enabled: false, providers: [ 'stripe' ], currency: 'USD', description: '', amount_field: '' },
	protection: { honeypot: true, min_seconds: 2, rate_limit: 6, ai_spam: false, spam_threshold: 80, spam_action: 'spam', block_abusive: true, paste_fill: false, paste_source: '' },
	translations: {},
};
const form = { id: 5, title: 'Contact', status: 'active', definition, shortcode: '[dev3fb_form id="5"]', preview: '/?dev3fb_preview=5' };

afterEach( () => {
	document.body.innerHTML = '';
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
} );

const wrap = ( el ) => <ToastProvider>{ el }</ToastProvider>;

test( 'builder: add a field from the palette, edit it in the inspector and save', async () => {
	apiFetch.mockImplementation( ( { url, method } ) => {
		if ( method === 'PUT' ) {
			return Promise.resolve( { ...form, definition: JSON.parse( JSON.stringify( apiFetch.mock.calls.at( -1 )[ 0 ].data.definition ) ) } );
		}
		return Promise.resolve( form );
	} );
	const view = await render( wrap( <Builder id={ 5 } meta={ { pages: [], languages: [] } } creds={ null } aiReady={ false } onUsage={ () => {} } /> ) );
	await view.flush();
	expect( view.$$( '.dev3fb-canvas__card' ) ).toHaveLength( 1 );
	const phone = view.$$( '.dev3fb-palette__item' ).find( ( b ) => b.textContent.includes( 'Phone' ) );
	await click( phone );
	expect( view.$$( '.dev3fb-canvas__card' ) ).toHaveLength( 2 );
	expect( view.getByText( 'Unsaved' ) ).toBeTruthy();
	const label = view.$( '.dev3fb-inspector input.dev3fb-ui-input' );
	await change( label, 'Mobile number' );
	expect( view.$( '.dev3fb-canvas' ).textContent ).toContain( 'Mobile number' );
	await click( view.getByText( 'Save' ).closest( 'button' ) );
	await view.flush();
	const put = apiFetch.mock.calls.find( ( c ) => c[ 0 ].method === 'PUT' );
	expect( put[ 0 ].url ).toContain( 'forms/5' );
	expect( put[ 0 ].data.definition.steps[ 0 ].fields.map( ( f ) => f.type ) ).toEqual( [ 'email', 'phone' ] );
	expect( put[ 0 ].data.definition.steps[ 0 ].fields[ 1 ].label ).toBe( 'Mobile number' );
	view.unmount();
} );

test( 'builder: multi-step and tabs render', async () => {
	apiFetch.mockImplementation( () => Promise.resolve( form ) );
	const view = await render( wrap( <Builder id={ 5 } meta={ { pages: [], languages: [] } } aiReady={ false } onUsage={ () => {} } /> ) );
	await view.flush();
	await click( view.getByText( 'Make it multi-step' ).closest( 'button' ) );
	expect( view.$$( '.dev3fb-canvas__step' ) ).toHaveLength( 2 );
	await click( view.getByText( 'Emails & confirmations' ).closest( 'button' ) );
	expect( view.getByText( 'Confirmations' ) ).toBeTruthy();
	await click( view.getByText( 'Spam & autofill' ).closest( 'button' ) );
	expect( view.getByText( 'Bot protection (no AI)' ) ).toBeTruthy();
	view.unmount();
} );

test( 'describe your form: AI draft is previewed, then saved as a draft form on accept', async () => {
	const draft = { title: 'Wedding inquiry', definition: { ...definition, steps: [ { id: 'step_1', title: 'Date', fields: [ { ...definition.steps[ 0 ].fields[ 0 ] } ] } ] }, fixes: [ 'Removed 1 logic rule' ], model: 'gemini-2.5-flash', tokens: 900 };
	apiFetch.mockImplementation( ( { url } ) => {
		if ( url.includes( 'ai/form' ) ) {
			return Promise.resolve( draft );
		}
		return Promise.resolve( { id: 44 } );
	} );
	const view = await render( wrap( <DescribeForm languages={ [ { value: 'en', label: 'English' } ] } siteLang="en" aiReady onUsage={ () => {} } /> ) );
	await change( view.$( 'textarea' ), 'A wedding photography inquiry form' );
	await click( view.getByText( 'Generate with DEV3 AI' ).closest( 'button' ) );
	await view.flush();
	expect( view.getByText( 'Wedding inquiry' ) ).toBeTruthy();
	expect( view.getByText( 'Not saved yet' ) ).toBeTruthy();
	expect( view.getByText( 'Removed 1 logic rule' ) ).toBeTruthy();
	expect( apiFetch.mock.calls.filter( ( c ) => c[ 0 ].url.includes( '/forms' ) ) ).toHaveLength( 0 );
	await click( view.getByText( 'Use this form' ).closest( 'button' ) );
	await view.flush();
	const post = apiFetch.mock.calls.find( ( c ) => /\/forms$/.test( c[ 0 ].url ) );
	expect( post[ 0 ].data.status ).toBe( 'draft' );
	expect( window.location.hash ).toBe( '#/forms/44' );
	view.unmount();
} );

test( 'entry drawer shows insights and drafts a reply that is only sent on approval', async () => {
	const detail = {
		entry: { id: 9, status: 'read', starred: false, email: 'ann@example.com', lang: 'en', payment_status: '', meta: {}, created_at: '2026-10-20T10:00:00+00:00', ai: { summary: 'Wants a quote.', sentiment: 'positive', intent: 'Quote for site', category: 'quote_request', lead_score: 82, lead_reason: 'Budget given.', urgency: 'high', language: 'en', tags: [ 'web' ], source: 'ai', model: 'gemini-2.5-flash' } },
		form_title: 'Quote',
		fields: [ { id: 'email', label: 'Email', type: 'email', value: 'ann@example.com' } ],
		files: [],
		log: [],
		name: 'Ann Lee',
		ai_ready: true,
	};
	apiFetch.mockImplementation( ( { url, method } ) => {
		if ( url.includes( 'reply-draft' ) ) {
			return Promise.resolve( { subject: 'Your quote', body: 'Hi Ann…', to: 'ann@example.com', model: 'm', tokens: 10 } );
		}
		if ( url.endsWith( '/reply' ) && method === 'POST' ) {
			return Promise.resolve( detail );
		}
		return Promise.resolve( detail );
	} );
	const view = await render( wrap( <EntryDrawer id={ 9 } onClose={ () => {} } onChange={ () => {} } onUsage={ () => {} } /> ) );
	await view.flush();
	expect( view.getByText( '82' ) ).toBeTruthy();
	expect( view.getByText( 'Wants a quote.' ) ).toBeTruthy();
	await click( view.getByText( 'Reply' ).closest( 'button' ) );
	await click( view.getByText( 'Draft a reply with DEV3 AI' ).closest( 'button' ) );
	await view.flush();
	expect( view.getByText( 'Your quote' ) ).toBeTruthy();
	expect( apiFetch.mock.calls.some( ( c ) => /\/reply$/.test( c[ 0 ].url ) ) ).toBe( false );
	await click( view.getByText( 'Send reply' ).closest( 'button' ) );
	await view.flush();
	const sent = apiFetch.mock.calls.find( ( c ) => /\/reply$/.test( c[ 0 ].url ) );
	expect( sent[ 0 ].data ).toEqual( { subject: 'Your quote', body: 'Hi Ann…' } );
	view.unmount();
} );
