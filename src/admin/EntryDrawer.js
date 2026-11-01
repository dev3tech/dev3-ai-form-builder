/**
 * Entry detail drawer: answers, files, payment, DEV3 AI entry intelligence, AI reply draft,
 * notes, activity log and actions.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Drawer, ErrorNotice, Inline, Notice, Select, SkeletonCard, Stack, Tabs, Textarea, useToast } from '../core3/ui';

export const SENTIMENT_TONE = { positive: 'success', neutral: 'neutral', negative: 'danger' };
export const scoreTone = ( s ) => ( s === null || s === undefined ? 'neutral' : s >= 70 ? 'success' : s >= 40 ? 'warning' : 'neutral' );

const TONES = [
	{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-form-builder' ) },
	{ value: 'professional', label: __( 'Professional', 'dev3-ai-form-builder' ) },
	{ value: 'concise', label: __( 'Short & to the point', 'dev3-ai-form-builder' ) },
	{ value: 'apologetic', label: __( 'Apologetic', 'dev3-ai-form-builder' ) },
	{ value: 'enthusiastic', label: __( 'Enthusiastic', 'dev3-ai-form-builder' ) },
];

const CATEGORY = {
	sales_lead: __( 'Sales lead', 'dev3-ai-form-builder' ),
	quote_request: __( 'Quote request', 'dev3-ai-form-builder' ),
	support: __( 'Support', 'dev3-ai-form-builder' ),
	feedback: __( 'Feedback', 'dev3-ai-form-builder' ),
	complaint: __( 'Complaint', 'dev3-ai-form-builder' ),
	booking: __( 'Booking', 'dev3-ai-form-builder' ),
	job_application: __( 'Job application', 'dev3-ai-form-builder' ),
	partnership: __( 'Partnership', 'dev3-ai-form-builder' ),
	newsletter: __( 'Newsletter', 'dev3-ai-form-builder' ),
	spam: __( 'Spam', 'dev3-ai-form-builder' ),
	other: __( 'Other', 'dev3-ai-form-builder' ),
};

function Insights( { detail, onAnalyze, busy } ) {
	const ai = detail.entry.ai;
	if ( ! ai ) {
		return (
			<div className="dev3fb-insight is-empty">
				<p className="dev3fb-muted">{ __( 'No insights yet for this entry.', 'dev3-ai-form-builder' ) }</p>
				<Inline gap={ 8 }>
					<AiButton onClick={ () => onAnalyze( false ) } loading={ busy } disabled={ ! detail.ai_ready || busy }>
						{ __( 'Analyze with DEV3 AI', 'dev3-ai-form-builder' ) }
					</AiButton>
					<Button size="md" onClick={ () => onAnalyze( true ) } disabled={ busy }>
						{ __( 'Quick score (no AI)', 'dev3-ai-form-builder' ) }
					</Button>
				</Inline>
			</div>
		);
	}
	return (
		<div className="dev3fb-insight">
			<div className="dev3fb-insight__score">
				<span className={ `dev3fb-score is-${ scoreTone( ai.lead_score ) }` }>{ ai.lead_score }</span>
				<span>
					<strong>{ __( 'Lead score', 'dev3-ai-form-builder' ) }</strong>
					<span className="dev3fb-muted">{ ai.lead_reason }</span>
				</span>
			</div>
			{ ai.summary && <p className="dev3fb-insight__summary">{ ai.summary }</p> }
			<Inline gap={ 6 }>
				<Badge tone={ SENTIMENT_TONE[ ai.sentiment ] || 'neutral' } dot>{ ai.sentiment }</Badge>
				{ ai.category && <Badge tone="brand">{ CATEGORY[ ai.category ] || ai.category }</Badge> }
				{ ai.urgency && ai.urgency !== 'low' && <Badge tone={ ai.urgency === 'high' ? 'danger' : 'warning' }>{ sprintf( /* translators: %s: urgency */ __( '%s urgency', 'dev3-ai-form-builder' ), ai.urgency ) }</Badge> }
				{ ai.language && <Badge>{ ai.language.toUpperCase() }</Badge> }
				{ ( ai.tags || [] ).map( ( t ) => (
					<Badge key={ t }>#{ t }</Badge>
				) ) }
			</Inline>
			{ ai.intent && (
				<p className="dev3fb-muted">
					{ __( 'Intent:', 'dev3-ai-form-builder' ) } { ai.intent }
				</p>
			) }
			<div className="dev3fb-insight__foot">
				<span className="dev3fb-muted">{ ai.source === 'rules' ? __( 'Rule-based score', 'dev3-ai-form-builder' ) : `DEV3 AI · ${ ai.model || '' }` }</span>
				<Button size="sm" variant="ghost" icon="refresh" onClick={ () => onAnalyze( false ) } loading={ busy } disabled={ ! detail.ai_ready }>
					{ __( 'Re-analyze', 'dev3-ai-form-builder' ) }
				</Button>
			</div>
		</div>
	);
}

function Reply( { detail, onSent, onUsage } ) {
	const toast = useToast();
	const [ tone, setTone ] = useState( 'friendly' );
	const [ notes, setNotes ] = useState( '' );
	const [ draft, setDraft ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ sending, setSending ] = useState( false );
	const [ error, setError ] = useState( null );
	if ( ! detail.entry.email ) {
		return <Notice tone="info">{ __( 'This entry has no email address, so there is no one to reply to.', 'dev3-ai-form-builder' ) }</Notice>;
	}
	const generate = () => {
		setBusy( true );
		setError( null );
		request( `entries/${ detail.entry.id }/reply-draft`, { method: 'POST', data: { tone, notes } } )
			.then( setDraft )
			.catch( setError )
			.finally( () => {
				setBusy( false );
				onUsage();
			} );
	};
	const send = ( values ) => {
		setSending( true );
		request( `entries/${ detail.entry.id }/reply`, { method: 'POST', data: values } )
			.then( ( next ) => {
				toast.success( sprintf( /* translators: %s: email */ __( 'Reply sent to %s.', 'dev3-ai-form-builder' ), detail.entry.email ) );
				setDraft( null );
				onSent( next );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setSending( false ) );
	};
	return (
		<Stack gap={ 12 }>
			<p className="dev3fb-muted">{ sprintf( /* translators: %s: email */ __( 'DEV3 AI drafts a reply to %s in their language. You edit and approve it; nothing is sent without your click.', 'dev3-ai-form-builder' ), detail.entry.email ) }</p>
			<Select label={ __( 'Tone', 'dev3-ai-form-builder' ) } value={ tone } onChange={ setTone } options={ TONES } />
			<Textarea label={ __( 'Facts to include (optional)', 'dev3-ai-form-builder' ) } rows={ 3 } value={ notes } onChange={ setNotes } placeholder={ __( 'e.g. We can start on 12 May; our starter package is $1,900.', 'dev3-ai-form-builder' ) } help={ __( 'DEV3 AI never invents prices or promises; add them here.', 'dev3-ai-form-builder' ) } />
			<div>
				<AiButton onClick={ generate } loading={ busy && ! draft } disabled={ busy || ! detail.ai_ready }>
					{ __( 'Draft a reply with DEV3 AI', 'dev3-ai-form-builder' ) }
				</AiButton>
			</div>
			<ErrorNotice error={ error } onRetry={ generate } onDismiss={ () => setError( null ) } />
			{ draft && (
				<AiDraftReview
					title={ __( 'DEV3 AI · reply draft', 'dev3-ai-form-builder' ) }
					fields={ [
						{ key: 'subject', label: __( 'Subject', 'dev3-ai-form-builder' ), maxLength: 150 },
						{ key: 'body', label: __( 'Message', 'dev3-ai-form-builder' ), multiline: true, maxLength: 3000 },
					] }
					draft={ { subject: draft.subject, body: draft.body } }
					meta={ { model: draft.model, tokens: draft.tokens } }
					onAccept={ send }
					onReject={ () => setDraft( null ) }
					onRegenerate={ generate }
					regenerating={ busy }
					accepting={ sending }
					acceptLabel={ __( 'Send reply', 'dev3-ai-form-builder' ) }
				/>
			) }
		</Stack>
	);
}

const LOG_TONE = { ok: 'success', error: 'danger', info: 'neutral' };

/**
 * @param {Object}   props          Props.
 * @param {number}   props.id       Entry id (null = closed).
 * @param {Function} props.onClose  Close.
 * @param {Function} props.onChange Entry changed (refresh list).
 * @param {Function} props.onUsage  Usage refresh.
 * @return {Element} Drawer.
 */
export default function EntryDrawer( { id, onClose, onChange, onUsage } ) {
	const toast = useToast();
	const [ detail, setDetail ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ tab, setTab ] = useState( 'answers' );
	const [ busy, setBusy ] = useState( false );
	const [ note, setNote ] = useState( '' );

	useEffect( () => {
		if ( ! id ) {
			return;
		}
		setDetail( null );
		setError( null );
		setTab( 'answers' );
		request( `entries/${ id }` )
			.then( ( d ) => {
				setDetail( d );
				onChange();
			} )
			.catch( setError );
	}, [ id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const patch = ( data, message ) =>
		request( `entries/${ id }`, { method: 'PATCH', data } )
			.then( ( d ) => {
				setDetail( d );
				onChange();
				if ( message ) {
					toast.success( message );
				}
			} )
			.catch( ( err ) => toast.error( err ) );

	const analyze = ( rules ) => {
		setBusy( true );
		request( `entries/${ id }/analyze`, { method: 'POST', data: { rules } } )
			.then( ( res ) => {
				setDetail( ( d ) => ( { ...d, entry: { ...d.entry, ai: res.ai, lead_score: res.ai.lead_score, sentiment: res.ai.sentiment } } ) );
				onChange();
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => {
				setBusy( false );
				onUsage();
			} );
	};
	const resend = ( what ) =>
		request( `entries/${ id }/resend`, { method: 'POST', data: { what: [ what ] } } )
			.then( ( d ) => {
				setDetail( d );
				toast.success( __( 'Sent again. See the activity log for details.', 'dev3-ai-form-builder' ) );
			} )
			.catch( ( err ) => toast.error( err ) );

	const e = detail && detail.entry;
	return (
		<Drawer
			open={ !! id }
			onClose={ onClose }
			size="lg"
			title={ e ? detail.name || e.email || sprintf( /* translators: %d: entry id */ __( 'Entry #%d', 'dev3-ai-form-builder' ), e.id ) : __( 'Entry', 'dev3-ai-form-builder' ) }
			description={ e ? `${ detail.form_title } · #${ e.id } · ${ new Date( e.created_at ).toLocaleString() }` : '' }
			footer={
				e && (
					<>
						<Button variant="ghost" icon={ e.starred ? 'check' : 'plus' } onClick={ () => patch( { starred: ! e.starred } ) }>
							{ e.starred ? __( 'Starred', 'dev3-ai-form-builder' ) : __( 'Star', 'dev3-ai-form-builder' ) }
						</Button>
						{ e.status === 'spam' ? (
							<Button onClick={ () => patch( { status: 'read' }, __( 'Moved to inbox.', 'dev3-ai-form-builder' ) ) }>{ __( 'Not spam', 'dev3-ai-form-builder' ) }</Button>
						) : (
							<Button variant="ghost" onClick={ () => patch( { status: 'spam' }, __( 'Marked as spam.', 'dev3-ai-form-builder' ) ) }>{ __( 'Spam', 'dev3-ai-form-builder' ) }</Button>
						) }
						{ e.status === 'trash' ? (
							<Button onClick={ () => patch( { status: 'read' }, __( 'Restored.', 'dev3-ai-form-builder' ) ) }>{ __( 'Restore', 'dev3-ai-form-builder' ) }</Button>
						) : (
							<Button variant="danger" icon="trash" onClick={ () => patch( { status: 'trash' }, __( 'Moved to trash.', 'dev3-ai-form-builder' ) ) }>{ __( 'Trash', 'dev3-ai-form-builder' ) }</Button>
						) }
					</>
				)
			}
		>
			<ErrorNotice error={ error } />
			{ ! detail && ! error && <SkeletonCard lines={ 8 } /> }
			{ detail && (
				<Stack gap={ 16 }>
					<Inline gap={ 6 }>
						<Badge tone={ e.status === 'unread' ? 'brand' : e.status === 'spam' ? 'danger' : 'neutral' }>{ e.status }</Badge>
						{ e.payment_status && <Badge tone={ e.payment_status === 'paid' ? 'success' : 'warning' } dot>{ `${ e.payment_status } · ${ e.payment_currency } ${ Number( e.payment_total ).toFixed( 2 ) }` }</Badge> }
						{ e.meta && e.meta.replied_at && <Badge tone="success">{ __( 'Replied', 'dev3-ai-form-builder' ) }</Badge> }
						{ e.meta && e.meta.spam && <Badge tone="danger">{ sprintf( /* translators: %s: reason */ __( 'Spam shield: %s', 'dev3-ai-form-builder' ), e.meta.spam.trigger ) }</Badge> }
						{ e.lang && <Badge>{ e.lang.toUpperCase() }</Badge> }
					</Inline>
					<div className="dev3fb-drawer-section">
						<h3 className="dev3fb-h4">{ __( 'DEV3 AI insights', 'dev3-ai-form-builder' ) }</h3>
						<Insights detail={ detail } onAnalyze={ analyze } busy={ busy } />
					</div>
					<Tabs
						tabs={ [
							{ id: 'answers', label: __( 'Answers', 'dev3-ai-form-builder' ) },
							{ id: 'reply', label: __( 'Reply', 'dev3-ai-form-builder' ) },
							{ id: 'activity', label: __( 'Activity', 'dev3-ai-form-builder' ), badge: detail.log.length ? String( detail.log.length ) : undefined },
						] }
						value={ tab }
						onChange={ setTab }
						label={ __( 'Entry sections', 'dev3-ai-form-builder' ) }
					/>
					{ tab === 'answers' && (
						<>
							<dl className="dev3fb-answers">
								{ detail.fields.map( ( f ) => (
									<div key={ f.id } className={ f.value === '' ? 'is-empty' : '' }>
										<dt>{ f.label }</dt>
										<dd>{ f.value === '' ? '—' : f.value }</dd>
									</div>
								) ) }
							</dl>
							{ detail.files.length > 0 && (
								<div className="dev3fb-files">
									<h3 className="dev3fb-h4">{ __( 'Files', 'dev3-ai-form-builder' ) }</h3>
									<ul>
										{ detail.files.map( ( f ) => (
											<li key={ `${ f.field }-${ f.index }` }>
												{ f.image && <img src={ f.url } alt="" loading="lazy" /> }
												<a href={ f.url } target="_blank" rel="noreferrer">
													{ f.name }
												</a>
												<span className="dev3fb-muted">{ Math.max( 1, Math.round( f.size / 1024 ) ) } KB</span>
											</li>
										) ) }
									</ul>
								</div>
							) }
							{ e.meta && e.meta.page_url && (
								<p className="dev3fb-muted">
									{ __( 'Submitted on', 'dev3-ai-form-builder' ) } <a href={ e.meta.page_url } target="_blank" rel="noreferrer">{ e.meta.page_url }</a>
								</p>
							) }
						</>
					) }
					{ tab === 'reply' && <Reply detail={ detail } onSent={ setDetail } onUsage={ onUsage } /> }
					{ tab === 'activity' && (
						<Stack gap={ 12 }>
							<div className="dev3fb-inline">
								<Button size="sm" icon="refresh" onClick={ () => resend( 'notifications' ) }>{ __( 'Resend emails', 'dev3-ai-form-builder' ) }</Button>
								<Button size="sm" icon="refresh" onClick={ () => resend( 'integrations' ) }>{ __( 'Resend to integrations', 'dev3-ai-form-builder' ) }</Button>
								<Button size="sm" icon="refresh" onClick={ () => resend( 'webhooks' ) }>{ __( 'Resend webhooks', 'dev3-ai-form-builder' ) }</Button>
							</div>
							<Textarea label={ __( 'Add a private note', 'dev3-ai-form-builder' ) } rows={ 2 } value={ note } onChange={ setNote } />
							<div>
								<Button size="sm" disabled={ ! note.trim() } onClick={ () => patch( { note }, __( 'Note added.', 'dev3-ai-form-builder' ) ).then( () => setNote( '' ) ) }>
									{ __( 'Add note', 'dev3-ai-form-builder' ) }
								</Button>
							</div>
							<ol className="dev3fb-log">
								{ detail.log.map( ( l ) => (
									<li key={ l.id }>
										<Badge tone={ LOG_TONE[ l.status ] }>{ l.type }</Badge>
										<span className="dev3fb-log__msg">{ l.message }</span>
										<span className="dev3fb-muted">
											{ new Date( l.created_at ).toLocaleString() }
											{ l.user ? ` · ${ l.user }` : '' }
										</span>
									</li>
								) ) }
								{ detail.log.length === 0 && <li className="dev3fb-muted">{ __( 'Nothing logged yet.', 'dev3-ai-form-builder' ) }</li> }
							</ol>
						</Stack>
					) }
				</Stack>
			) }
		</Drawer>
	);
}
