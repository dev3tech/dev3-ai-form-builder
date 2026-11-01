/**
 * All forms: table with entries, conversion and actions.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, Inline, Modal, PageHeader, ResponsiveTable, TextField, Checkbox, useToast } from '../core3/ui';

const pct = ( v ) => ( v === null || v === undefined ? '—' : `${ Math.round( v * 100 ) }%` );

export default function FormsList() {
	const toast = useToast();
	const [ forms, setForms ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ removing, setRemoving ] = useState( null );
	const [ withEntries, setWithEntries ] = useState( false );

	const load = () => {
		setError( null );
		request( 'forms' ).then( setForms ).catch( setError );
	};
	useEffect( load, [] );

	const copy = ( text ) => {
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( text ).then( () => toast.success( __( 'Shortcode copied.', 'dev3-ai-form-builder' ) ) ).catch( () => {} );
		}
	};
	const duplicate = ( form ) =>
		request( `forms/${ form.id }/duplicate`, { method: 'POST' } )
			.then( () => {
				toast.success( __( 'Form duplicated as a draft.', 'dev3-ai-form-builder' ) );
				load();
			} )
			.catch( ( err ) => toast.error( err ) );
	const remove = () =>
		request( `forms/${ removing.id }?entries=${ withEntries ? 1 : 0 }`, { method: 'DELETE' } )
			.then( () => {
				toast.success( __( 'Form deleted.', 'dev3-ai-form-builder' ) );
				setRemoving( null );
				load();
			} )
			.catch( ( err ) => toast.error( err ) );

	const rows = ( forms || [] ).filter( ( f ) => ! search || f.title.toLowerCase().includes( search.toLowerCase() ) );

	return (
		<>
			<PageHeader
				title={ __( 'Forms', 'dev3-ai-form-builder' ) }
				description={ __( 'Build, embed and analyse your forms.', 'dev3-ai-form-builder' ) }
				actions={
					<>
						<Button variant="ai" icon="sparkles" href="#/forms/new/ai">
							{ __( 'Describe a form', 'dev3-ai-form-builder' ) }
						</Button>
						<Button variant="primary" icon="plus" href="#/forms/new">
							{ __( 'New form', 'dev3-ai-form-builder' ) }
						</Button>
					</>
				}
			/>
			<ErrorNotice error={ error } onRetry={ load } />
			<Card
				flush
				title={ __( 'All forms', 'dev3-ai-form-builder' ) }
				actions={ <TextField label={ __( 'Search forms', 'dev3-ai-form-builder' ) } hideLabel type="search" placeholder={ __( 'Search forms…', 'dev3-ai-form-builder' ) } value={ search } onChange={ setSearch } /> }
			>
				<ResponsiveTable
					caption={ __( 'Forms', 'dev3-ai-form-builder' ) }
					loading={ ! forms && ! error }
					rows={ rows }
					pageSize={ 20 }
					defaultSort={ { key: 'updated_at', dir: 'desc' } }
					onRowClick={ ( row ) => ( window.location.hash = `#/forms/${ row.id }` ) }
					empty={ {
						icon: 'file',
						title: __( 'No forms yet', 'dev3-ai-form-builder' ),
						description: __( 'Start from a template, a blank form, or describe it and let DEV3 AI build it.', 'dev3-ai-form-builder' ),
						action: <Button variant="primary" icon="plus" href="#/forms/new">{ __( 'Create your first form', 'dev3-ai-form-builder' ) }</Button>,
					} }
					columns={ [
						{
							key: 'title',
							label: __( 'Form', 'dev3-ai-form-builder' ),
							primary: true,
							sortable: true,
							render: ( r ) => (
								<span className="dev3fb-cell-title">
									<a href={ `#/forms/${ r.id }` } onClick={ ( e ) => e.stopPropagation() }>{ r.title }</a>
									<span className="dev3fb-muted">
										{ sprintf( /* translators: 1: fields, 2: steps */ __( '%1$d fields · %2$d step(s)', 'dev3-ai-form-builder' ), r.fields, r.steps ) }
										{ r.payments ? ` · ${ __( 'payments', 'dev3-ai-form-builder' ) }` : '' }
										{ r.languages.length ? ` · ${ r.languages.join( ', ' ).toUpperCase() }` : '' }
									</span>
								</span>
							),
						},
						{ key: 'status', label: __( 'Status', 'dev3-ai-form-builder' ), sortable: true, render: ( r ) => <Badge tone={ r.status === 'active' ? 'success' : 'neutral' } dot>{ r.status === 'active' ? __( 'Active', 'dev3-ai-form-builder' ) : __( 'Draft', 'dev3-ai-form-builder' ) }</Badge> },
						{
							key: 'entries',
							label: __( 'Entries', 'dev3-ai-form-builder' ),
							sortable: true,
							align: 'end',
							render: ( r ) => (
								<a href={ `#/entries/form/${ r.id }` } onClick={ ( e ) => e.stopPropagation() }>
									{ r.entries }
									{ r.unread > 0 && <Badge tone="brand">{ sprintf( /* translators: %d: unread */ __( '%d new', 'dev3-ai-form-builder' ), r.unread ) }</Badge> }
								</a>
							),
						},
						{ key: 'conversion', label: __( 'Conversion (30d)', 'dev3-ai-form-builder' ), sortable: true, align: 'end', sortValue: ( r ) => ( r.conversion === null ? -1 : r.conversion ), render: ( r ) => pct( r.conversion ), hideOnMobile: true },
						{
							key: 'shortcode',
							label: __( 'Shortcode', 'dev3-ai-form-builder' ),
							hideOnMobile: true,
							render: ( r ) => (
								<button type="button" className="dev3fb-code-btn" onClick={ ( e ) => {
									e.stopPropagation();
									copy( r.shortcode );
								} } title={ __( 'Copy', 'dev3-ai-form-builder' ) }>
									<code>{ r.shortcode }</code>
								</button>
							),
						},
						{ key: 'updated_at', label: __( 'Updated', 'dev3-ai-form-builder' ), sortable: true, align: 'end', nowrap: true, render: ( r ) => new Date( r.updated_at ).toLocaleDateString(), hideOnMobile: true },
					] }
					rowActions={ ( r ) => (
						<Inline gap={ 4 }>
							<Button size="sm" href={ `#/forms/${ r.id }` }>{ __( 'Edit', 'dev3-ai-form-builder' ) }</Button>
							<Button size="sm" variant="ghost" onClick={ ( e ) => {
								e.stopPropagation();
								duplicate( r );
							} }>{ __( 'Duplicate', 'dev3-ai-form-builder' ) }</Button>
							<Button size="sm" variant="ghost" onClick={ ( e ) => {
								e.stopPropagation();
								setWithEntries( false );
								setRemoving( r );
							} }>{ __( 'Delete', 'dev3-ai-form-builder' ) }</Button>
						</Inline>
					) }
				/>
			</Card>
			<Modal
				open={ !! removing }
				onClose={ () => setRemoving( null ) }
				title={ __( 'Delete this form?', 'dev3-ai-form-builder' ) }
				description={ removing ? removing.title : '' }
				size="sm"
				footer={
					<>
						<Button variant="ghost" onClick={ () => setRemoving( null ) }>{ __( 'Cancel', 'dev3-ai-form-builder' ) }</Button>
						<Button variant="danger" onClick={ remove }>{ __( 'Delete form', 'dev3-ai-form-builder' ) }</Button>
					</>
				}
			>
				<Checkbox label={ __( 'Also delete all its entries and uploaded files', 'dev3-ai-form-builder' ) } checked={ withEntries } onChange={ setWithEntries } />
			</Modal>
		</>
	);
}
