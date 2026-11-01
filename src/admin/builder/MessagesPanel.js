/**
 * Confirmations (what visitors see after submitting) and email notifications.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Badge, Button, Card, FormGrid, IconButton, Select, Stack, TextField, Textarea, Toggle } from '../../core3/ui';
import LogicEditor from './LogicEditor';
import { allFields, earlierFields } from './model';

const uid = ( prefix, list ) => {
	let n = list.length + 1;
	while ( list.some( ( x ) => x.id === `${ prefix }_${ n }` ) ) {
		n++;
	}
	return `${ prefix }_${ n }`;
};

export function MergeTags( { definition, onInsert } ) {
	const fields = allFields( definition ).filter( ( f ) => ! [ 'heading', 'paragraph', 'divider' ].includes( f.type ) );
	const tags = [ ...fields.map( ( f ) => ( { tag: `{field:${ f.id }}`, label: f.label || f.id } ) ), { tag: '{all_fields}', label: __( 'All fields', 'dev3-ai-form-builder' ) }, { tag: '{form_title}', label: __( 'Form title', 'dev3-ai-form-builder' ) }, { tag: '{entry_url}', label: __( 'Entry link', 'dev3-ai-form-builder' ) }, { tag: '{payment_total}', label: __( 'Payment total', 'dev3-ai-form-builder' ) }, { tag: '{site_name}', label: __( 'Site name', 'dev3-ai-form-builder' ) } ];
	return (
		<div className="dev3fb-tokens" role="group" aria-label={ __( 'Insert a merge tag', 'dev3-ai-form-builder' ) }>
			{ tags.map( ( t ) => (
				<button key={ t.tag } type="button" className="dev3fb-token" onClick={ () => onInsert( t.tag ) } title={ t.tag }>
					{ t.label }
				</button>
			) ) }
		</div>
	);
}

function Confirmation( { item, index, definition, pages, onChange, onRemove, canRemove } ) {
	const [ open, setOpen ] = useState( index === 0 );
	const set = ( patch ) => onChange( { ...item, ...patch } );
	return (
		<Card
			title={ item.name || sprintf( /* translators: %d: number */ __( 'Confirmation %d', 'dev3-ai-form-builder' ), index + 1 ) }
			actions={
				<>
					{ item.logic && <Badge tone="info">{ __( 'Conditional', 'dev3-ai-form-builder' ) }</Badge> }
					<Button size="sm" variant="ghost" onClick={ () => setOpen( ! open ) } aria-expanded={ open }>
						{ open ? __( 'Close', 'dev3-ai-form-builder' ) : __( 'Edit', 'dev3-ai-form-builder' ) }
					</Button>
					{ canRemove && <IconButton icon="trash" size="sm" label={ __( 'Delete confirmation', 'dev3-ai-form-builder' ) } onClick={ onRemove } /> }
				</>
			}
		>
			{ open && (
				<Stack gap={ 14 }>
					<FormGrid>
						<TextField label={ __( 'Name (admin only)', 'dev3-ai-form-builder' ) } value={ item.name } onChange={ ( v ) => set( { name: v } ) } />
						<Select
							label={ __( 'Type', 'dev3-ai-form-builder' ) }
							value={ item.type }
							onChange={ ( v ) => set( { type: v } ) }
							options={ [
								{ value: 'message', label: __( 'Show a message', 'dev3-ai-form-builder' ) },
								{ value: 'page', label: __( 'Go to a page', 'dev3-ai-form-builder' ) },
								{ value: 'redirect', label: __( 'Go to a URL', 'dev3-ai-form-builder' ) },
							] }
						/>
					</FormGrid>
					{ item.type === 'message' && (
						<>
							<Textarea label={ __( 'Message', 'dev3-ai-form-builder' ) } rows={ 4 } value={ item.message } onChange={ ( v ) => set( { message: v } ) } help={ __( 'Basic HTML and merge tags allowed.', 'dev3-ai-form-builder' ) } />
							<MergeTags definition={ definition } onInsert={ ( tag ) => set( { message: `${ item.message || '' }${ tag }` } ) } />
						</>
					) }
					{ item.type === 'page' && <Select label={ __( 'Page', 'dev3-ai-form-builder' ) } value={ String( item.page_id || '' ) } onChange={ ( v ) => set( { page_id: Number( v ) } ) } options={ pages } placeholder={ __( 'Choose a page…', 'dev3-ai-form-builder' ) } /> }
					{ item.type === 'redirect' && <TextField type="url" label={ __( 'URL', 'dev3-ai-form-builder' ) } value={ item.url } onChange={ ( v ) => set( { url: v } ) } help={ __( 'Merge tags work here too, e.g. ?email={field:email}.', 'dev3-ai-form-builder' ) } /> }
					<LogicEditor logic={ item.logic } fields={ earlierFields( definition ) } subject={ __( 'use this confirmation', 'dev3-ai-form-builder' ) } onChange={ ( logic ) => set( { logic } ) } />
				</Stack>
			) }
		</Card>
	);
}

function Notification( { item, index, definition, onChange, onRemove } ) {
	const [ open, setOpen ] = useState( index === 0 );
	const set = ( patch ) => onChange( { ...item, ...patch } );
	const email = allFields( definition ).find( ( f ) => f.type === 'email' );
	return (
		<Card
			title={ item.name || sprintf( /* translators: %d: number */ __( 'Notification %d', 'dev3-ai-form-builder' ), index + 1 ) }
			description={ item.to }
			actions={
				<>
					{ ! item.enabled && <Badge>{ __( 'Off', 'dev3-ai-form-builder' ) }</Badge> }
					{ item.logic && <Badge tone="info">{ __( 'Conditional', 'dev3-ai-form-builder' ) }</Badge> }
					<Button size="sm" variant="ghost" onClick={ () => setOpen( ! open ) } aria-expanded={ open }>
						{ open ? __( 'Close', 'dev3-ai-form-builder' ) : __( 'Edit', 'dev3-ai-form-builder' ) }
					</Button>
					<IconButton icon="trash" size="sm" label={ __( 'Delete notification', 'dev3-ai-form-builder' ) } onClick={ onRemove } />
				</>
			}
		>
			{ open && (
				<Stack gap={ 14 }>
					<Toggle label={ __( 'Send this email', 'dev3-ai-form-builder' ) } checked={ item.enabled } onChange={ ( v ) => set( { enabled: v } ) } />
					<FormGrid>
						<TextField label={ __( 'Name (admin only)', 'dev3-ai-form-builder' ) } value={ item.name } onChange={ ( v ) => set( { name: v } ) } />
						<TextField label={ __( 'Send to', 'dev3-ai-form-builder' ) } value={ item.to } onChange={ ( v ) => set( { to: v } ) } help={ email ? sprintf( /* translators: %s: merge tag */ __( 'Comma-separated. {admin_email} or %s for the visitor.', 'dev3-ai-form-builder' ), `{field:${ email.id }}` ) : __( 'Comma-separated. {admin_email} for the site admin.', 'dev3-ai-form-builder' ) } />
						<TextField label={ __( 'Reply-to', 'dev3-ai-form-builder' ) } value={ item.reply_to } onChange={ ( v ) => set( { reply_to: v } ) } />
						<TextField label={ __( 'From name', 'dev3-ai-form-builder' ) } value={ item.from_name } onChange={ ( v ) => set( { from_name: v } ) } />
						<TextField label={ __( 'Cc', 'dev3-ai-form-builder' ) } value={ item.cc } onChange={ ( v ) => set( { cc: v } ) } />
						<TextField label={ __( 'Bcc', 'dev3-ai-form-builder' ) } value={ item.bcc } onChange={ ( v ) => set( { bcc: v } ) } />
					</FormGrid>
					<TextField label={ __( 'Subject', 'dev3-ai-form-builder' ) } value={ item.subject } onChange={ ( v ) => set( { subject: v } ) } />
					<Textarea label={ __( 'Message', 'dev3-ai-form-builder' ) } rows={ 6 } value={ item.message } onChange={ ( v ) => set( { message: v } ) } />
					<MergeTags definition={ definition } onInsert={ ( tag ) => set( { message: `${ item.message || '' }${ tag }` } ) } />
					<Toggle label={ __( 'Attach uploaded files', 'dev3-ai-form-builder' ) } checked={ item.attach } onChange={ ( v ) => set( { attach: v } ) } />
					<LogicEditor logic={ item.logic } fields={ earlierFields( definition ) } subject={ __( 'send this email', 'dev3-ai-form-builder' ) } onChange={ ( logic ) => set( { logic } ) } />
				</Stack>
			) }
		</Card>
	);
}

/**
 * @param {Object}   props            Props.
 * @param {Object}   props.definition Definition.
 * @param {Array}    props.pages      Pages [ { value, label } ].
 * @param {Function} props.onChange   ( patch ) => void (top-level keys).
 * @return {Element} Panel.
 */
export default function MessagesPanel( { definition, pages, onChange } ) {
	const confirmations = definition.confirmations || [];
	const notifications = definition.notifications || [];
	const email = allFields( definition ).find( ( f ) => f.type === 'email' );
	return (
		<Stack gap={ 24 }>
			<section>
				<div className="dev3fb-section-head">
					<div>
						<h3>{ __( 'Confirmations', 'dev3-ai-form-builder' ) }</h3>
						<p className="dev3fb-muted">{ __( 'The first confirmation whose conditions match is used.', 'dev3-ai-form-builder' ) }</p>
					</div>
					<Button size="sm" icon="plus" onClick={ () => onChange( { confirmations: [ ...confirmations, { id: uid( 'confirmation', confirmations ), name: __( 'Conditional confirmation', 'dev3-ai-form-builder' ), type: 'message', message: '', url: '', page_id: 0, logic: null } ] } ) }>
						{ __( 'Add confirmation', 'dev3-ai-form-builder' ) }
					</Button>
				</div>
				<Stack gap={ 12 }>
					{ confirmations.map( ( c, i ) => (
						<Confirmation
							key={ c.id }
							item={ c }
							index={ i }
							definition={ definition }
							pages={ pages }
							canRemove={ confirmations.length > 1 }
							onChange={ ( next ) => onChange( { confirmations: confirmations.map( ( x, n ) => ( n === i ? next : x ) ) } ) }
							onRemove={ () => onChange( { confirmations: confirmations.filter( ( x, n ) => n !== i ) } ) }
						/>
					) ) }
				</Stack>
			</section>
			<section>
				<div className="dev3fb-section-head">
					<div>
						<h3>{ __( 'Email notifications', 'dev3-ai-form-builder' ) }</h3>
						<p className="dev3fb-muted">{ __( 'Sent after each entry (for payment forms, after the payment completes).', 'dev3-ai-form-builder' ) }</p>
					</div>
					<Button
						size="sm"
						icon="plus"
						onClick={ () =>
							onChange( {
								notifications: [
									...notifications,
									{
										id: uid( 'notification', notifications ),
										name: email ? __( 'Visitor confirmation', 'dev3-ai-form-builder' ) : __( 'Notification', 'dev3-ai-form-builder' ),
										enabled: true,
										to: email ? `{field:${ email.id }}` : '{admin_email}',
										cc: '',
										bcc: '',
										from_name: '',
										reply_to: '',
										subject: email ? __( 'We received your message', 'dev3-ai-form-builder' ) : '{form_title}',
										message: email ? __( 'Thanks for getting in touch! Here is a copy of what you sent:', 'dev3-ai-form-builder' ) + '\n\n{all_fields}' : '{all_fields}',
										attach: false,
										logic: null,
									},
								],
							} )
						}
					>
						{ __( 'Add notification', 'dev3-ai-form-builder' ) }
					</Button>
				</div>
				<Stack gap={ 12 }>
					{ notifications.length === 0 && <p className="dev3fb-muted">{ __( 'No emails are sent for this form.', 'dev3-ai-form-builder' ) }</p> }
					{ notifications.map( ( n, i ) => (
						<Notification
							key={ n.id }
							item={ n }
							index={ i }
							definition={ definition }
							onChange={ ( next ) => onChange( { notifications: notifications.map( ( x, k ) => ( k === i ? next : x ) ) } ) }
							onRemove={ () => onChange( { notifications: notifications.filter( ( x, k ) => k !== i ) } ) }
						/>
					) ) }
				</Stack>
			</section>
		</Stack>
	);
}
