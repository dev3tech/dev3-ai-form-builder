/**
 * Global settings: email, DEV3 AI automation, integrations and payment credentials, privacy,
 * uploads and spam shield.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Columns, FormGrid, NumberField, PageHeader, Stack, Tabs, TextField, Textarea, Toggle, useToast } from '../core3/ui';

const SECTIONS = [
	{ id: 'general', label: __( 'General', 'dev3-ai-form-builder' ) },
	{ id: 'integrations', label: __( 'Integrations', 'dev3-ai-form-builder' ) },
	{ id: 'payments', label: __( 'Payments', 'dev3-ai-form-builder' ) },
	{ id: 'privacy', label: __( 'Entries & privacy', 'dev3-ai-form-builder' ) },
	{ id: 'spam', label: __( 'Spam shield', 'dev3-ai-form-builder' ) },
];

function Secret( { name, label, help, status, value, onChange } ) {
	const s = status && status[ name ];
	return (
		<TextField
			type="password"
			autoComplete="off"
			label={ label }
			value={ value === undefined ? '' : value }
			placeholder={ s && s.set ? s.hint : '' }
			onChange={ onChange }
			help={ s && s.constant ? __( 'Set in wp-config.php.', 'dev3-ai-form-builder' ) : help }
			disabled={ s && s.constant }
			suffix={ s && s.set ? <Badge tone="success">{ __( 'Saved', 'dev3-ai-form-builder' ) }</Badge> : undefined }
		/>
	);
}

export default function SettingsTab( { settings, save, section: initial, onCreds } ) {
	const toast = useToast();
	const [ section, setSection ] = useState( SECTIONS.some( ( s ) => s.id === initial ) ? initial : 'general' );
	const [ draft, setDraft ] = useState( settings );
	const [ creds, setCreds ] = useState( null );
	const [ secrets, setSecrets ] = useState( {} );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( '' );

	useEffect( () => setDraft( settings ), [ settings ] );
	useEffect( () => {
		request( 'credentials' ).then( setCreds ).catch( () => {} );
	}, [] );
	useEffect( () => {
		if ( initial && SECTIONS.some( ( s ) => s.id === initial ) ) {
			setSection( initial );
		}
	}, [ initial ] );

	const set = ( patch ) => setDraft( ( d ) => ( { ...d, ...patch } ) );
	const setSecret = ( key ) => ( v ) => setSecrets( ( s ) => ( { ...s, [ key ]: v } ) );
	const submit = () => {
		setSaving( true );
		const keys = [ 'email_from_name', 'email_from_address', 'reply_signature', 'auto_analyze', 'analytics', 'store_ip', 'retention_days', 'upload_max_mb', 'classify_rate_limit', 'daily_ai_cap', 'fail_open', 'skip_logged_in', 'paypal_sandbox', 'paypal_client_id', 'block_message', 'delete_on_uninstall' ];
		const patch = Object.fromEntries( keys.map( ( k ) => [ k, draft[ k ] ] ) );
		const jobs = [ save( patch ) ];
		if ( Object.keys( secrets ).length ) {
			jobs.push(
				request( 'credentials/save', { method: 'POST', data: secrets } ).then( ( res ) => {
					setCreds( res );
					setSecrets( {} );
					if ( onCreds ) {
						onCreds( res );
					}
				} )
			);
		}
		Promise.all( jobs )
			.then( () => toast.success( __( 'Settings saved.', 'dev3-ai-form-builder' ) ) )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setSaving( false ) );
	};
	const test = ( service ) => {
		setTesting( service );
		request( 'integrations/test', { method: 'POST', data: { service } } )
			.then( ( res ) => toast.success( res.message ) )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setTesting( '' ) );
	};
	const status = creds && creds.secrets;
	const footer = (
		<Button variant="primary" onClick={ submit } loading={ saving }>
			{ __( 'Save settings', 'dev3-ai-form-builder' ) }
		</Button>
	);

	return (
		<>
			<PageHeader title={ __( 'Settings', 'dev3-ai-form-builder' ) } description={ __( 'Defaults for every form. Each form has its own settings in the builder.', 'dev3-ai-form-builder' ) } />
			<Tabs tabs={ SECTIONS } value={ section } onChange={ setSection } label={ __( 'Settings sections', 'dev3-ai-form-builder' ) } />
			{ section === 'general' && (
				<Columns min={ 360 }>
					<Card title={ __( 'Emails', 'dev3-ai-form-builder' ) } footer={ footer }>
						<Stack gap={ 14 }>
							<FormGrid>
								<TextField label={ __( 'From name', 'dev3-ai-form-builder' ) } value={ draft.email_from_name } onChange={ ( v ) => set( { email_from_name: v } ) } placeholder={ __( 'Your site name', 'dev3-ai-form-builder' ) } />
								<TextField type="email" label={ __( 'From address', 'dev3-ai-form-builder' ) } value={ draft.email_from_address } onChange={ ( v ) => set( { email_from_address: v } ) } placeholder="hello@example.com" help={ __( 'Use an address on your own domain for better delivery.', 'dev3-ai-form-builder' ) } />
							</FormGrid>
							<Textarea label={ __( 'Signature for AI replies', 'dev3-ai-form-builder' ) } rows={ 2 } value={ draft.reply_signature } onChange={ ( v ) => set( { reply_signature: v } ) } placeholder={ __( 'Anna from the Example Studio team', 'dev3-ai-form-builder' ) } />
						</Stack>
					</Card>
					<Card tone="ai" title={ __( 'DEV3 AI automation', 'dev3-ai-form-builder' ) } footer={ footer }>
						<Stack gap={ 14 }>
							<Toggle label={ __( 'Analyze every new entry automatically', 'dev3-ai-form-builder' ) } help={ __( 'Adds a summary, sentiment, intent and lead score in the background. You can always analyze entries by hand.', 'dev3-ai-form-builder' ) } checked={ draft.auto_analyze } onChange={ ( v ) => set( { auto_analyze: v } ) } />
							<p className="dev3fb-muted">{ __( 'Models, the monthly AI budget and the storefront AI switch are in API Config.', 'dev3-ai-form-builder' ) }</p>
						</Stack>
					</Card>
				</Columns>
			) }
			{ section === 'integrations' && (
				<Columns min={ 340 }>
					<Card title="Mailchimp" footer={ <>{ footer }<Button onClick={ () => test( 'mailchimp' ) } loading={ testing === 'mailchimp' } disabled={ ! ( status && status.mailchimp_key.set ) }>{ __( 'Test', 'dev3-ai-form-builder' ) }</Button></> }>
						<Secret name="mailchimp_key" label={ __( 'API key', 'dev3-ai-form-builder' ) } help={ __( 'Mailchimp → Profile → Extras → API keys (ends in -usXX).', 'dev3-ai-form-builder' ) } status={ status } value={ secrets.mailchimp_key } onChange={ setSecret( 'mailchimp_key' ) } />
					</Card>
					<Card title="Brevo" footer={ <>{ footer }<Button onClick={ () => test( 'brevo' ) } loading={ testing === 'brevo' } disabled={ ! ( status && status.brevo_key.set ) }>{ __( 'Test', 'dev3-ai-form-builder' ) }</Button></> }>
						<Secret name="brevo_key" label={ __( 'API key (v3)', 'dev3-ai-form-builder' ) } help={ __( 'Brevo → SMTP & API → API keys.', 'dev3-ai-form-builder' ) } status={ status } value={ secrets.brevo_key } onChange={ setSecret( 'brevo_key' ) } />
					</Card>
					<Card title="HubSpot" footer={ <>{ footer }<Button onClick={ () => test( 'hubspot' ) } loading={ testing === 'hubspot' } disabled={ ! ( status && status.hubspot_token.set ) }>{ __( 'Test', 'dev3-ai-form-builder' ) }</Button></> }>
						<Secret name="hubspot_token" label={ __( 'Private app access token', 'dev3-ai-form-builder' ) } help={ __( 'HubSpot → Settings → Integrations → Private apps (scope: crm.objects.contacts.write).', 'dev3-ai-form-builder' ) } status={ status } value={ secrets.hubspot_token } onChange={ setSecret( 'hubspot_token' ) } />
					</Card>
					<Card title={ __( 'Google Sheets & Zapier', 'dev3-ai-form-builder' ) }>
						<p className="dev3fb-muted">{ __( 'No keys needed: paste the Apps Script web app URL or the Zapier catch hook URL in each form\'s Integrations tab.', 'dev3-ai-form-builder' ) }</p>
					</Card>
				</Columns>
			) }
			{ section === 'payments' && (
				<Columns min={ 360 }>
					<Card title="Stripe" footer={ footer } actions={ creds && creds.stripe.configured ? <Badge tone="success" dot>{ creds.stripe.test_mode ? __( 'Test mode', 'dev3-ai-form-builder' ) : __( 'Live', 'dev3-ai-form-builder' ) }</Badge> : null }>
						<Stack gap={ 14 }>
							<Secret name="stripe_secret" label={ __( 'Secret key', 'dev3-ai-form-builder' ) } help={ __( 'sk_test_… for testing, sk_live_… for real payments. A restricted key with Checkout Sessions write access also works.', 'dev3-ai-form-builder' ) } status={ status } value={ secrets.stripe_secret } onChange={ setSecret( 'stripe_secret' ) } />
							<Secret name="stripe_webhook_secret" label={ __( 'Webhook signing secret (optional)', 'dev3-ai-form-builder' ) } help={ __( 'whsec_… Confirms payments even if the visitor closes the tab before returning.', 'dev3-ai-form-builder' ) } status={ status } value={ secrets.stripe_webhook_secret } onChange={ setSecret( 'stripe_webhook_secret' ) } />
							{ creds && (
								<p className="dev3fb-muted">
									{ __( 'Webhook endpoint (event: checkout.session.completed):', 'dev3-ai-form-builder' ) } <code>{ creds.stripe.webhook }</code>
								</p>
							) }
						</Stack>
					</Card>
					<Card title="PayPal" footer={ footer } actions={ creds && creds.paypal.configured ? <Badge tone="success" dot>{ draft.paypal_sandbox ? __( 'Sandbox', 'dev3-ai-form-builder' ) : __( 'Live', 'dev3-ai-form-builder' ) }</Badge> : null }>
						<Stack gap={ 14 }>
							<TextField label={ __( 'Client ID', 'dev3-ai-form-builder' ) } value={ draft.paypal_client_id } onChange={ ( v ) => set( { paypal_client_id: v } ) } />
							<Secret name="paypal_secret" label={ __( 'Secret', 'dev3-ai-form-builder' ) } help={ __( 'PayPal Developer → Apps & Credentials.', 'dev3-ai-form-builder' ) } status={ status } value={ secrets.paypal_secret } onChange={ setSecret( 'paypal_secret' ) } />
							<Toggle label={ __( 'Sandbox (test) mode', 'dev3-ai-form-builder' ) } checked={ draft.paypal_sandbox } onChange={ ( v ) => set( { paypal_sandbox: v } ) } />
						</Stack>
					</Card>
				</Columns>
			) }
			{ section === 'privacy' && (
				<Columns min={ 360 }>
					<Card title={ __( 'Entries', 'dev3-ai-form-builder' ) } footer={ footer }>
						<Stack gap={ 14 }>
							<NumberField label={ __( 'Delete entries after (days, 0 = keep)', 'dev3-ai-form-builder' ) } value={ draft.retention_days } min={ 0 } onChange={ ( v ) => set( { retention_days: v === '' ? 0 : v } ) } />
							<Toggle label={ __( 'Store the visitor\'s IP address', 'dev3-ai-form-builder' ) } checked={ draft.store_ip } onChange={ ( v ) => set( { store_ip: v } ) } help={ __( 'Off by default for privacy.', 'dev3-ai-form-builder' ) } />
							<Toggle label={ __( 'Conversion analytics', 'dev3-ai-form-builder' ) } checked={ draft.analytics } onChange={ ( v ) => set( { analytics: v } ) } help={ __( 'Counts anonymous views, starts and drop-off per field. No cookies, no IPs, no answers.', 'dev3-ai-form-builder' ) } />
							<NumberField label={ __( 'Maximum upload size (MB, all forms)', 'dev3-ai-form-builder' ) } value={ draft.upload_max_mb } min={ 1 } max={ 512 } onChange={ ( v ) => set( { upload_max_mb: v === '' ? 10 : v } ) } />
							<Toggle label={ __( 'Delete all data when the plugin is uninstalled', 'dev3-ai-form-builder' ) } checked={ draft.delete_on_uninstall } onChange={ ( v ) => set( { delete_on_uninstall: v } ) } />
						</Stack>
					</Card>
					<Card title={ __( 'GDPR', 'dev3-ai-form-builder' ) }>
						<p className="dev3fb-muted">{ __( 'Entries are included in WordPress\'s "Export personal data" and "Erase personal data" tools (matched by email). A privacy policy snippet naming Google Gemini as processor is added under Settings → Privacy.', 'dev3-ai-form-builder' ) }</p>
					</Card>
				</Columns>
			) }
			{ section === 'spam' && (
				<Card title={ __( 'DEV3 AI Spam Shield', 'dev3-ai-form-builder' ) } description={ __( 'Turn the shield on per form (Spam & autofill tab). These limits apply to all forms.', 'dev3-ai-form-builder' ) } footer={ footer }>
					<Stack gap={ 14 }>
						<FormGrid>
							<NumberField label={ __( 'AI checks per minute per visitor', 'dev3-ai-form-builder' ) } value={ draft.classify_rate_limit } min={ 1 } onChange={ ( v ) => set( { classify_rate_limit: v === '' ? 12 : v } ) } />
							<NumberField label={ __( 'AI checks per day (whole site, 0 = no limit)', 'dev3-ai-form-builder' ) } value={ draft.daily_ai_cap } min={ 0 } onChange={ ( v ) => set( { daily_ai_cap: v === '' ? 0 : v } ) } />
						</FormGrid>
						<Toggle label={ __( 'Deliver submissions when AI is unavailable (fail-open)', 'dev3-ai-form-builder' ) } checked={ draft.fail_open } onChange={ ( v ) => set( { fail_open: v } ) } />
						<Toggle label={ __( 'Skip the AI check for logged-in users', 'dev3-ai-form-builder' ) } checked={ draft.skip_logged_in } onChange={ ( v ) => set( { skip_logged_in: v } ) } />
						<TextField label={ __( 'Message for blocked submissions', 'dev3-ai-form-builder' ) } value={ draft.block_message } onChange={ ( v ) => set( { block_message: v } ) } maxLength={ 300 } />
						<p className="dev3fb-muted">{ sprintf( /* translators: %s: link */ __( 'Review caught submissions in %s.', 'dev3-ai-form-builder' ), __( 'Spam shield', 'dev3-ai-form-builder' ) ) } <a href="#/spam">{ __( 'Open the log', 'dev3-ai-form-builder' ) }</a></p>
					</Stack>
				</Card>
			) }
		</>
	);
}
