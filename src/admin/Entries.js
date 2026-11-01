/**
 * Entries manager: filters, status tabs, bulk actions, CSV export, bulk DEV3 AI analysis.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Checkbox, ErrorNotice, Inline, JobProgress, PageHeader, ResponsiveTable, Select, Tabs, TextField, useToast } from '../core3/ui';
import EntryDrawer, { SENTIMENT_TONE, scoreTone } from './EntryDrawer';
import { download } from './download';

const STATUS_TABS = [
	{ id: 'inbox', label: __( 'Inbox', 'dev3-ai-form-builder' ), count: 'all' },
	{ id: 'unread', label: __( 'Unread', 'dev3-ai-form-builder' ), count: 'unread' },
	{ id: 'starred', label: __( 'Starred', 'dev3-ai-form-builder' ), count: 'starred' },
	{ id: 'pending', label: __( 'Awaiting payment', 'dev3-ai-form-builder' ), count: 'pending' },
	{ id: 'spam', label: __( 'Spam', 'dev3-ai-form-builder' ), count: 'spam' },
	{ id: 'trash', label: __( 'Trash', 'dev3-ai-form-builder' ), count: 'trash' },
];

/**
 * @param {Object}   props         Props.
 * @param {Array}    props.params  Route params ([ 'form', id ] or [ entryId ]).
 * @param {boolean}  props.aiReady AI ready.
 * @param {Function} props.onUsage Usage refresh.
 * @return {Element} Screen.
 */
export default function Entries( { params, aiReady, onUsage } ) {
	const toast = useToast();
	const initialForm = params[ 0 ] === 'form' ? params[ 1 ] || '' : '';
	const openId = params[ 0 ] && params[ 0 ] !== 'form' ? Number( params[ 0 ] ) : null;
	const [ forms, setForms ] = useState( [] );
	const [ form, setForm ] = useState( initialForm );
	const [ status, setStatus ] = useState( 'inbox' );
	const apiStatus = status === 'inbox' ? '' : status;
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ sort, setSort ] = useState( { key: 'created_at', dir: 'desc' } );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	const [ job, setJob ] = useState( null );
	const [ drawer, setDrawer ] = useState( openId );

	useEffect( () => setDrawer( openId ), [ openId ] );
	useEffect( () => setForm( initialForm ), [ initialForm ] );
	useEffect( () => {
		request( 'forms' ).then( setForms ).catch( () => {} );
	}, [] );
	useEffect( () => {
		const t = setTimeout( () => setQuery( search ), 350 );
		return () => clearTimeout( t );
	}, [ search ] );

	const load = useCallback( () => {
		setError( null );
		const orderby = sort.key === 'lead_score' ? 'lead_score' : sort.key === 'payment_total' ? 'payment_total' : 'created_at';
		request( `entries?form=${ form || 0 }&status=${ apiStatus }&search=${ encodeURIComponent( query ) }&page=${ page }&per_page=20&orderby=${ orderby }&order=${ sort.dir }` )
			.then( ( d ) => {
				setData( d );
				setSelected( ( sel ) => sel.filter( ( id ) => d.rows.some( ( r ) => r.id === id ) ) );
			} )
			.catch( setError );
	}, [ form, apiStatus, query, page, sort ] );
	useEffect( load, [ load ] );
	useEffect( () => setPage( 1 ), [ form, status, query ] );

	const bulk = ( action ) => {
		if ( ! selected.length ) {
			return;
		}
		request( 'entries/bulk', { method: 'POST', data: { ids: selected, action } } )
			.then( ( res ) => {
				if ( res.job ) {
					setJob( res.job );
					return;
				}
				toast.success( sprintf( /* translators: %d: count */ __( '%d entries updated.', 'dev3-ai-form-builder' ), res.updated ) );
				setSelected( [] );
				load();
			} )
			.catch( ( err ) => toast.error( err ) );
	};
	const exportCsv = () => {
		if ( ! form ) {
			toast.warning( __( 'Choose a form to export its entries.', 'dev3-ai-form-builder' ) );
			return;
		}
		request( `entries/export?form=${ form }&status=${ apiStatus }&search=${ encodeURIComponent( query ) }` )
			.then( ( res ) => {
				download( res.filename, res.csv );
				toast.success( sprintf( /* translators: %d: count */ __( '%d entries exported.', 'dev3-ai-form-builder' ), res.count ) );
			} )
			.catch( ( err ) => toast.error( err ) );
	};
	const analyzeAll = () =>
		request( 'entries/analyze', { method: 'POST', data: { form: form || 0, only_missing: true } } )
			.then( ( res ) => {
				if ( ! res.job ) {
					toast.info( __( 'Every entry already has DEV3 AI insights.', 'dev3-ai-form-builder' ) );
					return;
				}
				setJob( res.job );
			} )
			.catch( ( err ) => toast.error( err ) );

	const counts = ( data && data.counts ) || {};
	const rows = ( data && data.rows ) || [];
	const allChecked = rows.length > 0 && rows.every( ( r ) => selected.includes( r.id ) );
	const toggle = ( id ) => setSelected( ( sel ) => ( sel.includes( id ) ? sel.filter( ( x ) => x !== id ) : [ ...sel, id ] ) );
	const open = ( id ) => {
		window.location.hash = `#/entries/${ id }`;
	};
	const close = () => {
		setDrawer( null );
		window.location.hash = form ? `#/entries/form/${ form }` : '#/entries';
	};

	return (
		<>
			<PageHeader
				title={ __( 'Entries', 'dev3-ai-form-builder' ) }
				description={ __( 'Every submission with DEV3 AI summaries, sentiment and lead scores.', 'dev3-ai-form-builder' ) }
				actions={
					<>
						<Button icon="sparkles" variant="ai" onClick={ analyzeAll } disabled={ ! aiReady }>
							{ __( 'Analyze new entries', 'dev3-ai-form-builder' ) }
						</Button>
						<Button icon="file" onClick={ exportCsv }>
							{ __( 'Export CSV', 'dev3-ai-form-builder' ) }
						</Button>
					</>
				}
			/>
			{ job && (
				<Card>
					<JobProgress
						jobId={ job.id }
						job={ job }
						title={ __( 'DEV3 AI is analyzing entries', 'dev3-ai-form-builder' ) }
						onDone={ () => {
							toast.success( __( 'Analysis finished.', 'dev3-ai-form-builder' ) );
							load();
							onUsage();
						} }
					/>
				</Card>
			) }
			<Card flush>
				<div className="dev3fb-filters">
					<Select label={ __( 'Form', 'dev3-ai-form-builder' ) } value={ form } onChange={ setForm } options={ [ { value: '', label: __( 'All forms', 'dev3-ai-form-builder' ) }, ...forms.map( ( f ) => ( { value: String( f.id ), label: f.title } ) ) ] } />
					<TextField label={ __( 'Search', 'dev3-ai-form-builder' ) } type="search" value={ search } onChange={ setSearch } placeholder={ __( 'Name, email, any answer…', 'dev3-ai-form-builder' ) } />
				</div>
				<div className="dev3fb-statustabs">
					<Checkbox label={ __( 'Select all on this page', 'dev3-ai-form-builder' ) } checked={ allChecked } onChange={ ( v ) => setSelected( v ? rows.map( ( r ) => r.id ) : [] ) } className="dev3fb-selectall" />
					<Tabs
						tabs={ STATUS_TABS.map( ( t ) => ( { id: t.id, label: t.label, badge: counts[ t.count ] ? String( counts[ t.count ] ) : undefined } ) ) }
						value={ status }
						onChange={ setStatus }
						variant="pills"
						label={ __( 'Entry status', 'dev3-ai-form-builder' ) }
					/>
				</div>
				{ selected.length > 0 && (
					<div className="dev3fb-bulkbar" role="region" aria-label={ __( 'Bulk actions', 'dev3-ai-form-builder' ) }>
						<strong>{ sprintf( /* translators: %d: count */ __( '%d selected', 'dev3-ai-form-builder' ), selected.length ) }</strong>
						<Inline gap={ 6 }>
							<Button size="sm" onClick={ () => bulk( 'read' ) }>{ __( 'Mark read', 'dev3-ai-form-builder' ) }</Button>
							<Button size="sm" onClick={ () => bulk( 'star' ) }>{ __( 'Star', 'dev3-ai-form-builder' ) }</Button>
							<Button size="sm" variant="ai" icon="sparkles" onClick={ () => bulk( 'analyze' ) } disabled={ ! aiReady }>{ __( 'Analyze', 'dev3-ai-form-builder' ) }</Button>
							{ status === 'spam' ? <Button size="sm" onClick={ () => bulk( 'not_spam' ) }>{ __( 'Not spam', 'dev3-ai-form-builder' ) }</Button> : <Button size="sm" onClick={ () => bulk( 'spam' ) }>{ __( 'Spam', 'dev3-ai-form-builder' ) }</Button> }
							{ status === 'trash' ? (
								<>
									<Button size="sm" onClick={ () => bulk( 'restore' ) }>{ __( 'Restore', 'dev3-ai-form-builder' ) }</Button>
									<Button size="sm" variant="danger" onClick={ () => bulk( 'delete' ) }>{ __( 'Delete permanently', 'dev3-ai-form-builder' ) }</Button>
								</>
							) : (
								<Button size="sm" variant="danger" onClick={ () => bulk( 'trash' ) }>{ __( 'Trash', 'dev3-ai-form-builder' ) }</Button>
							) }
						</Inline>
					</div>
				) }
				<ErrorNotice error={ error } onRetry={ load } />
				<ResponsiveTable
					caption={ __( 'Entries', 'dev3-ai-form-builder' ) }
					loading={ ! data && ! error }
					rows={ rows }
					sort={ sort }
					onSortChange={ setSort }
					pagination={ { page, perPage: 20, total: ( data && data.total ) || 0, onPageChange: setPage } }
					onRowClick={ ( r ) => open( r.id ) }
					empty={ { icon: 'inbox', title: __( 'No entries here', 'dev3-ai-form-builder' ), description: query ? __( 'Try another search.', 'dev3-ai-form-builder' ) : __( 'Submissions appear here as soon as visitors send a form.', 'dev3-ai-form-builder' ) } }
					columns={ [
						{
							key: 'select',
							label: __( 'Select', 'dev3-ai-form-builder' ),
							hideOnMobile: true,
							width: '44px',
							render: ( r ) => (
								<span onClick={ ( e ) => e.stopPropagation() } role="presentation">
									<Checkbox label={ sprintf( /* translators: %d: entry */ __( 'Select entry %d', 'dev3-ai-form-builder' ), r.id ) } checked={ selected.includes( r.id ) } onChange={ () => toggle( r.id ) } className="dev3fb-check-sr" />
								</span>
							),
						},
						{
							key: 'name',
							label: __( 'From', 'dev3-ai-form-builder' ),
							primary: true,
							render: ( r ) => (
								<span className={ `dev3fb-cell-title${ r.status === 'unread' ? ' is-unread' : '' }` }>
									<span>
										<span className="dev3fb-mobile-check" onClick={ ( e ) => e.stopPropagation() } role="presentation">
											<Checkbox label={ sprintf( /* translators: %d: entry */ __( 'Select entry %d', 'dev3-ai-form-builder' ), r.id ) } checked={ selected.includes( r.id ) } onChange={ () => toggle( r.id ) } className="dev3fb-check-sr" />
										</span>
										{ r.starred && <span className="dev3fb-star" aria-label={ __( 'Starred', 'dev3-ai-form-builder' ) }>★ </span> }
										{ r.name || r.email || sprintf( /* translators: %d: id */ __( 'Entry #%d', 'dev3-ai-form-builder' ), r.id ) }
									</span>
									<span className="dev3fb-muted">{ r.intent || ( r.preview[ 0 ] ? `${ r.preview[ 0 ].label }: ${ r.preview[ 0 ].value }` : '' ) }</span>
								</span>
							),
						},
						{ key: 'form_title', label: __( 'Form', 'dev3-ai-form-builder' ), hideOnMobile: true },
						{
							key: 'lead_score',
							label: __( 'Lead', 'dev3-ai-form-builder' ),
							sortable: true,
							align: 'end',
							render: ( r ) => ( r.lead_score === null ? <span className="dev3fb-muted">—</span> : <span className={ `dev3fb-score is-sm is-${ scoreTone( r.lead_score ) }` }>{ r.lead_score }</span> ),
						},
						{ key: 'sentiment', label: __( 'Sentiment', 'dev3-ai-form-builder' ), hideOnMobile: true, render: ( r ) => ( r.sentiment ? <Badge tone={ SENTIMENT_TONE[ r.sentiment ] } dot>{ r.sentiment }</Badge> : <span className="dev3fb-muted">—</span> ) },
						{
							key: 'payment_total',
							label: __( 'Payment', 'dev3-ai-form-builder' ),
							sortable: true,
							align: 'end',
							hideOnMobile: true,
							render: ( r ) => ( r.payment_status ? <Badge tone={ r.payment_status === 'paid' ? 'success' : 'warning' }>{ `${ r.currency } ${ Number( r.payment_total ).toFixed( 2 ) } · ${ r.payment_status }` }</Badge> : <span className="dev3fb-muted">—</span> ),
						},
						{ key: 'created_at', label: __( 'Received', 'dev3-ai-form-builder' ), sortable: true, align: 'end', nowrap: true, render: ( r ) => new Date( r.created_at ).toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } ) },
					] }
				/>
			</Card>
			<EntryDrawer id={ drawer } onClose={ close } onChange={ load } onUsage={ onUsage } />
		</>
	);
}
