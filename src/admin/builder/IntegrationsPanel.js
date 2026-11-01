/**
 * Integrations (Mailchimp, Brevo, HubSpot, Google Sheets, Zapier) and webhooks for one form.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../../core3/api';
import { Badge, Button, Card, Columns, FormGrid, IconButton, Notice, Select, Stack, TextField, Toggle, useToast } from '../../core3/ui';
import LogicEditor from './LogicEditor';
import { allFields, earlierFields } from './model';

const MAPS = {
	mailchimp: [ 'email', 'first_name', 'last_name', 'phone' ],
	brevo: [ 'email', 'first_name', 'last_name', 'phone' ],
	hubspot: [ 'email', 'first_name', 'last_name', 'phone', 'company', 'website', 'message' ],
};
const MAP_LABELS = {
	email: __( 'Email', 'dev3-ai-form-builder' ),
	first_name: __( 'First name', 'dev3-ai-form-builder' ),
	last_name: __( 'Last name', 'dev3-ai-form-builder' ),
	phone: __( 'Phone', 'dev3-ai-form-builder' ),
	company: __( 'Company', 'dev3-ai-form-builder' ),
	website: __( 'Website', 'dev3-ai-form-builder' ),
	message: __( 'Message', 'dev3-ai-form-builder' ),
};
const CRED = { mailchimp: 'mailchimp_key', brevo: 'brevo_key', hubspot: 'hubspot_token' };

function guess( fields, key ) {
	const by = ( re, types ) => fields.find( ( f ) => ( ! types || types.includes( f.type ) ) && re.test( `${ f.id } ${ f.label }`.toLowerCase() ) );
	const map = {
		email: () => fields.find( ( f ) => f.type === 'email' ),
		first_name: () => fields.find( ( f ) => f.type === 'name' ) || by( /first|given/, [ 'text' ] ),
		last_name: () => fields.find( ( f ) => f.type === 'name' ) || by( /last|sur|family/, [ 'text' ] ),
		phone: () => fields.find( ( f ) => f.type === 'phone' ),
		company: () => by( /company|organi/, [ 'text' ] ),
		website: () => fields.find( ( f ) => f.type === 'url' ),
		message: () => fields.find( ( f ) => f.type === 'textarea' ),
	};
	const hit = map[ key ] && map[ key ]();
	return hit ? hit.id : '';
}

function CrmCard( { service, title, description, config, fields, creds, onChange, listLabel } ) {
	const toast = useToast();
	const [ lists, setLists ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const connected = creds && creds[ CRED[ service ] ] && creds[ CRED[ service ] ].set;
	const set = ( patch ) => onChange( { ...config, ...patch } );
	const options = fields.map( ( f ) => ( { value: f.id, label: f.label || f.id } ) );
	const load = () => {
		setBusy( true );
		request( 'integrations/test', { method: 'POST', data: { service } } )
			.then( ( res ) => {
				setLists( res.lists || [] );
				toast.success( res.message );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};
	const enable = ( v ) => {
		const map = { ...config.map };
		MAPS[ service ].forEach( ( key ) => {
			if ( ! map[ key ] ) {
				map[ key ] = guess( fields, key );
			}
		} );
		set( { enabled: v, map } );
	};
	return (
		<Card title={ title } description={ description } actions={ connected ? <Badge tone="success" dot>{ __( 'Connected', 'dev3-ai-form-builder' ) }</Badge> : <Badge>{ __( 'Not connected', 'dev3-ai-form-builder' ) }</Badge> }>
			<Stack gap={ 14 }>
				{ ! connected && (
					<Notice tone="info" actions={ <Button size="sm" href="#/settings/integrations">{ __( 'Add API key', 'dev3-ai-form-builder' ) }</Button> }>
						{ __( 'Add the API key once in Settings → Integrations, then turn it on for any form.', 'dev3-ai-form-builder' ) }
					</Notice>
				) }
				<Toggle label={ sprintf( /* translators: %s: service */ __( 'Send entries to %s', 'dev3-ai-form-builder' ), title ) } checked={ config.enabled } onChange={ enable } disabled={ ! connected && ! config.enabled } />
				{ config.enabled && (
					<>
						{ service !== 'hubspot' && (
							<div className="dev3fb-inline is-end">
								{ lists && lists.length ? (
									<Select label={ listLabel } value={ config.list_id } onChange={ ( v ) => set( { list_id: v } ) } options={ lists } placeholder={ __( 'Choose…', 'dev3-ai-form-builder' ) } />
								) : (
									<TextField label={ listLabel } value={ config.list_id } onChange={ ( v ) => set( { list_id: v } ) } />
								) }
								<Button size="sm" onClick={ load } loading={ busy } disabled={ ! connected }>
									{ __( 'Load lists', 'dev3-ai-form-builder' ) }
								</Button>
							</div>
						) }
						<FormGrid>
							{ MAPS[ service ].map( ( key ) => (
								<Select key={ key } label={ MAP_LABELS[ key ] } value={ config.map[ key ] || '' } onChange={ ( v ) => set( { map: { ...config.map, [ key ]: v } } ) } options={ options } placeholder={ __( '— not sent —', 'dev3-ai-form-builder' ) } />
							) ) }
						</FormGrid>
						{ service === 'mailchimp' && (
							<>
								<TextField label={ __( 'Tags (comma-separated)', 'dev3-ai-form-builder' ) } value={ config.tags } onChange={ ( v ) => set( { tags: v } ) } />
								<Toggle label={ __( 'Double opt-in (Mailchimp sends a confirmation email)', 'dev3-ai-form-builder' ) } checked={ config.double } onChange={ ( v ) => set( { double: v } ) } />
							</>
						) }
					</>
				) }
			</Stack>
		</Card>
	);
}

function UrlCard( { title, description, help, config, onChange } ) {
	return (
		<Card title={ title } description={ description }>
			<Stack gap={ 14 }>
				<TextField type="url" label={ __( 'Web app / webhook URL (https)', 'dev3-ai-form-builder' ) } value={ config.url } onChange={ ( v ) => onChange( { ...config, url: v, enabled: !! v && config.enabled } ) } help={ help } />
				<Toggle label={ __( 'Send every entry', 'dev3-ai-form-builder' ) } checked={ config.enabled } disabled={ ! config.url } onChange={ ( v ) => onChange( { ...config, enabled: v } ) } />
			</Stack>
		</Card>
	);
}

/**
 * @param {Object}   props            Props.
 * @param {Object}   props.definition Definition.
 * @param {Object}   props.creds      Credentials status.
 * @param {Function} props.onChange   ( patch ) => void (top-level keys).
 * @return {Element} Panel.
 */
export default function IntegrationsPanel( { definition, creds, onChange } ) {
	const integrations = definition.integrations;
	const webhooks = definition.webhooks || [];
	const fields = allFields( definition ).filter( ( f ) => ! [ 'heading', 'paragraph', 'divider', 'file', 'signature', 'password' ].includes( f.type ) );
	const setService = ( service, config ) => onChange( { integrations: { ...integrations, [ service ]: config } } );
	const consentOptions = allFields( definition ).filter( ( f ) => f.type === 'consent' || f.type === 'toggle' || f.type === 'checkbox' ).map( ( f ) => ( { value: f.id, label: f.label || f.id } ) );
	return (
		<Stack gap={ 20 }>
			<Card title={ __( 'Marketing consent', 'dev3-ai-form-builder' ) }>
				<Select
					label={ __( 'Only send to Mailchimp / Brevo / HubSpot when this is checked', 'dev3-ai-form-builder' ) }
					value={ integrations.consent_field }
					onChange={ ( v ) => onChange( { integrations: { ...integrations, consent_field: v } } ) }
					options={ consentOptions }
					placeholder={ __( 'Always send', 'dev3-ai-form-builder' ) }
					help={ __( 'Google Sheets, Zapier and webhooks always receive entries.', 'dev3-ai-form-builder' ) }
				/>
			</Card>
			<Columns min={ 360 }>
				<CrmCard service="mailchimp" title="Mailchimp" description={ __( 'Add or update audience members.', 'dev3-ai-form-builder' ) } listLabel={ __( 'Audience ID', 'dev3-ai-form-builder' ) } config={ integrations.mailchimp } fields={ fields } creds={ creds } onChange={ ( c ) => setService( 'mailchimp', c ) } />
				<CrmCard service="brevo" title="Brevo" description={ __( 'Create or update contacts and add them to a list.', 'dev3-ai-form-builder' ) } listLabel={ __( 'List ID', 'dev3-ai-form-builder' ) } config={ integrations.brevo } fields={ fields } creds={ creds } onChange={ ( c ) => setService( 'brevo', c ) } />
				<CrmCard service="hubspot" title="HubSpot" description={ __( 'Create or update CRM contacts.', 'dev3-ai-form-builder' ) } config={ integrations.hubspot } fields={ fields } creds={ creds } onChange={ ( c ) => setService( 'hubspot', c ) } />
				<UrlCard title="Google Sheets" description={ __( 'One row per entry through a small Apps Script web app (no Google login needed on your site).', 'dev3-ai-form-builder' ) } help={ __( 'See the documentation for the 10-line script to paste into your sheet.', 'dev3-ai-form-builder' ) } config={ integrations.sheets } onChange={ ( c ) => setService( 'sheets', c ) } />
				<UrlCard title="Zapier" description={ __( 'Send entries to a "Webhooks by Zapier" catch hook and connect 6,000+ apps.', 'dev3-ai-form-builder' ) } help={ __( 'Paste the catch hook URL from your Zap.', 'dev3-ai-form-builder' ) } config={ integrations.zapier } onChange={ ( c ) => setService( 'zapier', c ) } />
			</Columns>
			<section>
				<div className="dev3fb-section-head">
					<div>
						<h3>{ __( 'Webhooks', 'dev3-ai-form-builder' ) }</h3>
						<p className="dev3fb-muted">{ __( 'POST every entry as JSON or form data, signed with an HMAC header when you set a secret.', 'dev3-ai-form-builder' ) }</p>
					</div>
					<Button size="sm" icon="plus" onClick={ () => onChange( { webhooks: [ ...webhooks, { id: `webhook_${ webhooks.length + 1 }`, name: __( 'Webhook', 'dev3-ai-form-builder' ), enabled: false, url: '', format: 'json', secret: '', logic: null } ] } ) }>
						{ __( 'Add webhook', 'dev3-ai-form-builder' ) }
					</Button>
				</div>
				<Stack gap={ 12 }>
					{ webhooks.length === 0 && <p className="dev3fb-muted">{ __( 'No webhooks yet.', 'dev3-ai-form-builder' ) }</p> }
					{ webhooks.map( ( hook, i ) => {
						const set = ( patch ) => onChange( { webhooks: webhooks.map( ( h, n ) => ( n === i ? { ...h, ...patch } : h ) ) } );
						return (
							<Card key={ hook.id } title={ hook.name || __( 'Webhook', 'dev3-ai-form-builder' ) } actions={ <IconButton icon="trash" size="sm" label={ __( 'Delete webhook', 'dev3-ai-form-builder' ) } onClick={ () => onChange( { webhooks: webhooks.filter( ( h, n ) => n !== i ) } ) } /> }>
								<Stack gap={ 14 }>
									<FormGrid>
										<TextField label={ __( 'Name', 'dev3-ai-form-builder' ) } value={ hook.name } onChange={ ( v ) => set( { name: v } ) } />
										<TextField type="url" label={ __( 'URL', 'dev3-ai-form-builder' ) } value={ hook.url } onChange={ ( v ) => set( { url: v } ) } />
										<Select label={ __( 'Format', 'dev3-ai-form-builder' ) } value={ hook.format } onChange={ ( v ) => set( { format: v } ) } options={ [ { value: 'json', label: 'JSON' }, { value: 'form', label: __( 'Form data', 'dev3-ai-form-builder' ) } ] } />
										<TextField label={ __( 'Secret (optional)', 'dev3-ai-form-builder' ) } value={ hook.secret } onChange={ ( v ) => set( { secret: v } ) } help={ __( 'Adds X-DEV3FB-Signature: sha256=HMAC(body).', 'dev3-ai-form-builder' ) } />
									</FormGrid>
									<Toggle label={ __( 'Active', 'dev3-ai-form-builder' ) } checked={ hook.enabled } disabled={ ! hook.url } onChange={ ( v ) => set( { enabled: v } ) } />
									<LogicEditor logic={ hook.logic } fields={ earlierFields( definition ) } subject={ __( 'send this webhook', 'dev3-ai-form-builder' ) } onChange={ ( logic ) => set( { logic } ) } />
								</Stack>
							</Card>
						);
					} ) }
				</Stack>
			</section>
		</Stack>
	);
}
