/**
 * Spam shield log (ported from DEV3 AI Form Autofill & Spam Shield): what was stopped and why.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, Inline, PageHeader, ResponsiveTable, Select, Stat, StatGrid, useToast } from '../core3/ui';

const TRIGGER = {
	ai: __( 'DEV3 AI: spam', 'dev3-ai-form-builder' ),
	abusive: __( 'DEV3 AI: abusive', 'dev3-ai-form-builder' ),
	honeypot: __( 'Honeypot', 'dev3-ai-form-builder' ),
	too_fast: __( 'Too fast', 'dev3-ai-form-builder' ),
	rate_limit: __( 'Rate limit', 'dev3-ai-form-builder' ),
	ai_error: __( 'AI unavailable', 'dev3-ai-form-builder' ),
};
const ACTION_TONE = { block: 'danger', spam: 'warning', flag: 'info', allow: 'neutral' };

export default function SpamLog() {
	const toast = useToast();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ action, setAction ] = useState( '' );

	const load = useCallback( () => {
		request( `spam?page=${ page }&per_page=20&action=${ action }` ).then( setData ).catch( setError );
	}, [ page, action ] );
	useEffect( load, [ load ] );

	const mark = ( row, status ) =>
		request( `spam/${ row.id }`, { method: 'PATCH', data: { status } } )
			.then( () => {
				toast.success( status === 'not_spam' ? __( 'Marked as not spam. The sender is trusted from now on.', 'dev3-ai-form-builder' ) : __( 'Confirmed as spam.', 'dev3-ai-form-builder' ) );
				load();
			} )
			.catch( ( err ) => toast.error( err ) );

	const c = ( data && data.counts ) || {};
	return (
		<>
			<PageHeader title={ __( 'Spam shield', 'dev3-ai-form-builder' ) } description={ __( 'Submissions stopped in the last 30 days. Only hashes and a redacted excerpt are stored here.', 'dev3-ai-form-builder' ) } />
			<StatGrid>
				<Stat label={ __( 'Blocked', 'dev3-ai-form-builder' ) } value={ String( c.block || 0 ) } icon="x" tone="danger" />
				<Stat label={ __( 'Moved to spam', 'dev3-ai-form-builder' ) } value={ String( c.spam || 0 ) } icon="inbox" tone="warning" />
				<Stat label={ __( 'Flagged', 'dev3-ai-form-builder' ) } value={ String( c.flag || 0 ) } icon="alert" />
				<Stat label={ __( 'False positives', 'dev3-ai-form-builder' ) } value={ String( c.not_spam || 0 ) } icon="check" tone="success" />
			</StatGrid>
			<ErrorNotice error={ error } onRetry={ load } />
			<Card
				flush
				title={ __( 'Log', 'dev3-ai-form-builder' ) }
				actions={
					<Select
						label={ __( 'Action', 'dev3-ai-form-builder' ) }
						hideLabel
						value={ action }
						onChange={ ( v ) => {
							setAction( v );
							setPage( 1 );
						} }
						options={ [
							{ value: '', label: __( 'All actions', 'dev3-ai-form-builder' ) },
							{ value: 'block', label: __( 'Blocked', 'dev3-ai-form-builder' ) },
							{ value: 'spam', label: __( 'Moved to spam', 'dev3-ai-form-builder' ) },
							{ value: 'flag', label: __( 'Flagged', 'dev3-ai-form-builder' ) },
							{ value: 'allow', label: __( 'Allowed (AI unavailable)', 'dev3-ai-form-builder' ) },
						] }
					/>
				}
			>
				<ResponsiveTable
					caption={ __( 'Spam log', 'dev3-ai-form-builder' ) }
					loading={ ! data && ! error }
					rows={ ( data && data.items ) || [] }
					pagination={ { page, perPage: 20, total: ( data && data.total ) || 0, onPageChange: setPage } }
					empty={ { icon: 'check', title: __( 'Nothing caught yet', 'dev3-ai-form-builder' ), description: __( 'Bots and spam stopped by the honeypot, time trap, rate limit or DEV3 AI appear here.', 'dev3-ai-form-builder' ) } }
					columns={ [
						{
							key: 'excerpt',
							label: __( 'Submission', 'dev3-ai-form-builder' ),
							primary: true,
							render: ( r ) => (
								<span className="dev3fb-cell-title">
									<span>{ r.excerpt || <em className="dev3fb-muted">{ __( '(no text)', 'dev3-ai-form-builder' ) }</em> }</span>
									<span className="dev3fb-muted">{ r.form_title } · { r.reason }</span>
								</span>
							),
						},
						{ key: 'trigger', label: __( 'Caught by', 'dev3-ai-form-builder' ), render: ( r ) => <Badge tone={ ACTION_TONE[ r.action ] }>{ TRIGGER[ r.trigger ] || r.trigger }</Badge> },
						{ key: 'score', label: __( 'Score', 'dev3-ai-form-builder' ), align: 'end', hideOnMobile: true, render: ( r ) => ( [ 'ai', 'abusive' ].includes( r.trigger ) ? `${ r.score }%` : '—' ) },
						{
							key: 'status',
							label: __( 'Review', 'dev3-ai-form-builder' ),
							render: ( r ) =>
								r.status === 'open' ? (
									<Inline gap={ 4 }>
										<Button size="sm" onClick={ () => mark( r, 'not_spam' ) }>{ __( 'Not spam', 'dev3-ai-form-builder' ) }</Button>
										<Button size="sm" variant="ghost" onClick={ () => mark( r, 'confirmed' ) }>{ __( 'Confirm', 'dev3-ai-form-builder' ) }</Button>
										{ r.entry_id > 0 && <Button size="sm" variant="ghost" href={ `#/entries/${ r.entry_id }` }>{ sprintf( /* translators: %d: entry */ __( 'Entry #%d', 'dev3-ai-form-builder' ), r.entry_id ) }</Button> }
									</Inline>
								) : (
									<Badge tone={ r.status === 'not_spam' ? 'success' : 'neutral' }>{ r.status === 'not_spam' ? __( 'Not spam', 'dev3-ai-form-builder' ) : __( 'Confirmed', 'dev3-ai-form-builder' ) }</Badge>
								),
						},
						{ key: 'created_at', label: __( 'When', 'dev3-ai-form-builder' ), align: 'end', nowrap: true, render: ( r ) => new Date( r.created_at ).toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } ) },
					] }
				/>
			</Card>
		</>
	);
}
