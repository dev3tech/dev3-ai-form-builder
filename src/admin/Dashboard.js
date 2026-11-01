/**
 * Dashboard: KPIs, 30-day entries chart, top forms, latest entries and quick "Describe a form".
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Columns, EmptyState, ErrorNotice, Notice, PageHeader, ResponsiveTable, SkeletonCard, Stat, StatGrid } from '../core3/ui';
import { SENTIMENT_TONE, scoreTone } from './EntryDrawer';

const pct = ( v ) => ( v === null || v === undefined ? '—' : `${ Math.round( v * 100 ) }%` );

function Bars( { daily } ) {
	const max = Math.max( 1, ...daily.map( ( d ) => d.count ) );
	const total = daily.reduce( ( n, d ) => n + d.count, 0 );
	return (
		<figure className="dev3fb-chart">
			<div className="dev3fb-chart__plot" role="img" aria-label={ sprintf( /* translators: %d: entries */ __( '%d entries in the last 30 days', 'dev3-ai-form-builder' ), total ) }>
				{ daily.map( ( d ) => (
					<span key={ d.date } className="dev3fb-chart__bar" style={ { height: `${ Math.max( 2, ( 100 * d.count ) / max ) }%` } } title={ `${ d.date }: ${ d.count }` } />
				) ) }
			</div>
			<figcaption className="dev3fb-chart__axis">
				<span>{ new Date( daily[ 0 ].date ).toLocaleDateString( undefined, { month: 'short', day: 'numeric' } ) }</span>
				<span>{ __( 'Today', 'dev3-ai-form-builder' ) }</span>
			</figcaption>
		</figure>
	);
}

export default function Dashboard( { settings } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const load = () => request( 'dashboard' ).then( setData ).catch( setError );
	useEffect( () => {
		load();
	}, [] );

	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', 'dev3-ai-form-builder' ) }
				description={ __( 'How your forms are doing.', 'dev3-ai-form-builder' ) }
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
			{ ! settings.api_key_set && (
				<Notice tone="ai" title={ __( 'Connect DEV3 AI', 'dev3-ai-form-builder' ) } actions={ <Button size="sm" variant="primary" href="#/api">{ __( 'Add your API key', 'dev3-ai-form-builder' ) }</Button> }>
					{ __( 'Add a Google Gemini API key to describe forms in plain words, score leads, draft replies, translate forms and stop spam. The form builder works without it.', 'dev3-ai-form-builder' ) }
				</Notice>
			) }
			<ErrorNotice error={ error } onRetry={ load } />
			{ ! data && ! error && <SkeletonCard lines={ 6 } /> }
			{ data && (
				<>
					<StatGrid>
						<Stat label={ __( 'Entries (30 days)', 'dev3-ai-form-builder' ) } value={ String( data.entries_30 ) } icon="inbox" tone="brand" hint={ sprintf( /* translators: %d: unread */ __( '%d unread', 'dev3-ai-form-builder' ), data.unread ) } />
						<Stat label={ __( 'Conversion', 'dev3-ai-form-builder' ) } value={ pct( data.conversion ) } icon="chart" tone="success" hint={ sprintf( /* translators: %d: views */ __( '%d form views', 'dev3-ai-form-builder' ), data.views_30 ) } />
						<Stat label={ __( 'Active forms', 'dev3-ai-form-builder' ) } value={ String( data.active ) } icon="file" hint={ sprintf( /* translators: %d: forms */ __( '%d in total', 'dev3-ai-form-builder' ), data.forms ) } />
						<Stat label={ __( 'Spam stopped', 'dev3-ai-form-builder' ) } value={ String( data.spam_30 ) } icon="bolt" tone="warning" hint={ __( 'Last 30 days', 'dev3-ai-form-builder' ) } />
					</StatGrid>
					<Columns min={ 360 }>
						<Card title={ __( 'Entries per day', 'dev3-ai-form-builder' ) } actions={ <Button size="sm" variant="ghost" href="#/entries">{ __( 'All entries', 'dev3-ai-form-builder' ) }</Button> }>
							<Bars daily={ data.daily } />
						</Card>
						<Card title={ __( 'Top forms', 'dev3-ai-form-builder' ) } flush actions={ <Button size="sm" variant="ghost" href="#/forms">{ __( 'All forms', 'dev3-ai-form-builder' ) }</Button> }>
							<ResponsiveTable
								caption={ __( 'Top forms', 'dev3-ai-form-builder' ) }
								rows={ data.top }
								onRowClick={ ( r ) => ( window.location.hash = `#/forms/${ r.id }` ) }
								empty={ { icon: 'file', title: __( 'No forms yet', 'dev3-ai-form-builder' ), action: <Button variant="primary" href="#/forms/new">{ __( 'Create a form', 'dev3-ai-form-builder' ) }</Button> } }
								columns={ [
									{ key: 'title', label: __( 'Form', 'dev3-ai-form-builder' ), primary: true },
									{ key: 'entries', label: __( 'Entries', 'dev3-ai-form-builder' ), align: 'end', render: ( r ) => ( <>{ r.entries }{ r.unread > 0 && <> <Badge tone="brand">{ r.unread }</Badge></> }</> ) },
									{ key: 'conversion', label: __( 'Conv.', 'dev3-ai-form-builder' ), align: 'end', render: ( r ) => pct( r.conversion ) },
								] }
							/>
						</Card>
					</Columns>
					<Card title={ __( 'Latest entries', 'dev3-ai-form-builder' ) } flush actions={ <Button size="sm" variant="ghost" href="#/entries">{ __( 'Open inbox', 'dev3-ai-form-builder' ) }</Button> }>
						{ data.recent.length === 0 ? (
							<EmptyState icon="inbox" title={ __( 'No entries yet', 'dev3-ai-form-builder' ) } description={ __( 'Embed a form with the "DEV3 AI Form" block or its shortcode to start collecting.', 'dev3-ai-form-builder' ) } />
						) : (
							<ResponsiveTable
								caption={ __( 'Latest entries', 'dev3-ai-form-builder' ) }
								rows={ data.recent }
								onRowClick={ ( r ) => ( window.location.hash = `#/entries/${ r.id }` ) }
								columns={ [
									{
										key: 'name',
										label: __( 'From', 'dev3-ai-form-builder' ),
										primary: true,
										render: ( r ) => (
											<span className={ `dev3fb-cell-title${ r.status === 'unread' ? ' is-unread' : '' }` }>
												<span>{ r.name || r.email || `#${ r.id }` }</span>
												<span className="dev3fb-muted">{ r.intent || r.form_title }</span>
											</span>
										),
									},
									{ key: 'form_title', label: __( 'Form', 'dev3-ai-form-builder' ), hideOnMobile: true },
									{ key: 'lead_score', label: __( 'Lead', 'dev3-ai-form-builder' ), align: 'end', render: ( r ) => ( r.lead_score === null ? '—' : <span className={ `dev3fb-score is-sm is-${ scoreTone( r.lead_score ) }` }>{ r.lead_score }</span> ) },
									{ key: 'sentiment', label: __( 'Sentiment', 'dev3-ai-form-builder' ), hideOnMobile: true, render: ( r ) => ( r.sentiment ? <Badge tone={ SENTIMENT_TONE[ r.sentiment ] } dot>{ r.sentiment }</Badge> : '—' ) },
									{ key: 'created_at', label: __( 'Received', 'dev3-ai-form-builder' ), align: 'end', nowrap: true, render: ( r ) => new Date( r.created_at ).toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } ) },
								] }
							/>
						) }
					</Card>
				</>
			) }
		</>
	);
}
