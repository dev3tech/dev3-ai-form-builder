/**
 * Conversion insights: funnel, drop-off per field, rule-based hints and DEV3 AI advice with
 * one-click fixes (previewed as a draft first).
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Card, Columns, EmptyState, ErrorNotice, Notice, ProgressBar, Select, SkeletonCard, Stack, Stat, StatGrid, useToast } from '../../core3/ui';
import { findField } from './model';

const pct = ( v ) => ( v === null || v === undefined ? '—' : `${ Math.round( v * 100 ) }%` );
const IMPACT = { high: 'danger', medium: 'warning', low: 'neutral' };
const FIX_LABEL = {
	make_optional: __( 'Make optional', 'dev3-ai-form-builder' ),
	change_label: __( 'Change label', 'dev3-ai-form-builder' ),
	change_placeholder: __( 'Change placeholder', 'dev3-ai-form-builder' ),
	add_help: __( 'Add help text', 'dev3-ai-form-builder' ),
	change_type: __( 'Change field type', 'dev3-ai-form-builder' ),
	remove_field: __( 'Remove field', 'dev3-ai-form-builder' ),
	move_to_new_step: __( 'Move to a later step', 'dev3-ai-form-builder' ),
};

function Advice( { item, form, definition, dirty, onApply } ) {
	const toast = useToast();
	const [ preview, setPreview ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const field = item.field ? findField( definition, item.field ) : null;
	const canFix = item.fix && item.fix !== 'none' && field;
	const open = () => {
		setBusy( true );
		request( `forms/${ form.id }/apply-fix`, { method: 'POST', data: { item } } )
			.then( ( res ) => setPreview( res.definition ) )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};
	const after = preview ? findField( preview, item.field ) : null;
	return (
		<li className="dev3fb-advice">
			<div className="dev3fb-advice__head">
				<Badge tone={ IMPACT[ item.impact ] || 'neutral' }>{ item.impact }</Badge>
				{ item.source === 'ai' && <Badge tone="brand">DEV3 AI</Badge> }
				{ field && <strong>{ field.label }</strong> }
			</div>
			<p className="dev3fb-advice__problem">{ item.problem }</p>
			<p className="dev3fb-advice__fix">{ item.suggestion }</p>
			{ canFix && ! preview && (
				<Button size="sm" onClick={ open } loading={ busy } disabled={ dirty }>
					{ FIX_LABEL[ item.fix ] || __( 'Preview fix', 'dev3-ai-form-builder' ) }
					{ item.value ? `: ${ item.value }` : '' }
				</Button>
			) }
			{ preview && (
				<AiDraftReview
					title={ __( 'DEV3 AI · suggested fix', 'dev3-ai-form-builder' ) }
					format="json"
					original={ field }
					draft={ after || { removed: true } }
					editable={ false }
					acceptLabel={ __( 'Apply fix', 'dev3-ai-form-builder' ) }
					onAccept={ () => {
						onApply( preview );
						setPreview( null );
					} }
					onReject={ () => setPreview( null ) }
				/>
			) }
		</li>
	);
}

/**
 * @param {Object}   props            Props.
 * @param {Object}   props.form       Saved form.
 * @param {Object}   props.definition Current definition.
 * @param {boolean}  props.dirty      Unsaved changes.
 * @param {boolean}  props.aiReady    AI ready.
 * @param {Function} props.onApply    ( definition ) => void.
 * @param {Function} props.onUsage    Usage refresh.
 * @return {Element} Panel.
 */
export default function InsightsPanel( { form, definition, dirty, aiReady, onApply, onUsage } ) {
	const [ days, setDays ] = useState( '30' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ advice, setAdvice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ aiError, setAiError ] = useState( null );

	useEffect( () => {
		setData( null );
		request( `forms/${ form.id }/insights?days=${ days }` ).then( setData ).catch( setError );
	}, [ form.id, days ] );

	const ask = () => {
		setBusy( true );
		setAiError( null );
		request( `forms/${ form.id }/advice?days=${ days }`, { method: 'POST' } )
			.then( setAdvice )
			.catch( setAiError )
			.finally( () => {
				setBusy( false );
				onUsage();
			} );
	};

	if ( error ) {
		return <ErrorNotice error={ error } />;
	}
	if ( ! data ) {
		return <SkeletonCard lines={ 6 } />;
	}
	const f = data.funnel;
	const maxStep = Math.max( 1, ...f.steps.map( ( s ) => s.sessions ) );
	return (
		<Stack gap={ 20 }>
			<div className="dev3fb-section-head">
				<div>
					<h3>{ __( 'Conversion funnel', 'dev3-ai-form-builder' ) }</h3>
					<p className="dev3fb-muted">{ __( 'Anonymous visits only: no field values or personal data are tracked.', 'dev3-ai-form-builder' ) }</p>
				</div>
				<Select label={ __( 'Period', 'dev3-ai-form-builder' ) } hideLabel value={ days } onChange={ setDays } options={ [ { value: '7', label: __( 'Last 7 days', 'dev3-ai-form-builder' ) }, { value: '30', label: __( 'Last 30 days', 'dev3-ai-form-builder' ) }, { value: '90', label: __( 'Last 90 days', 'dev3-ai-form-builder' ) } ] } />
			</div>
			<StatGrid>
				<Stat label={ __( 'Views', 'dev3-ai-form-builder' ) } value={ String( f.views ) } icon="users" />
				<Stat label={ __( 'Started', 'dev3-ai-form-builder' ) } value={ String( f.starts ) } hint={ sprintf( /* translators: %s: percent */ __( '%s of views', 'dev3-ai-form-builder' ), pct( f.start_rate ) ) } icon="edit" />
				<Stat label={ __( 'Submitted', 'dev3-ai-form-builder' ) } value={ String( f.submits ) } hint={ sprintf( /* translators: %s: percent */ __( '%s of starts', 'dev3-ai-form-builder' ), pct( f.completion_rate ) ) } icon="check" tone="success" />
				<Stat label={ __( 'Conversion', 'dev3-ai-form-builder' ) } value={ pct( f.conversion_rate ) } hint={ __( 'Submitted / viewed', 'dev3-ai-form-builder' ) } icon="chart" tone="brand" />
			</StatGrid>
			<Columns min={ 340 }>
				<Card title={ __( 'Where visitors give up', 'dev3-ai-form-builder' ) } description={ __( 'Last field touched before leaving without submitting.', 'dev3-ai-form-builder' ) }>
					{ f.abandons.length === 0 ? (
						<EmptyState compact icon="chart" title={ __( 'No drop-off data yet', 'dev3-ai-form-builder' ) } description={ __( 'It appears as visitors start and leave the form.', 'dev3-ai-form-builder' ) } />
					) : (
						<ol className="dev3fb-dropoff">
							{ f.abandons.slice( 0, 8 ).map( ( row ) => (
								<li key={ row.field || 'none' }>
									<span className="dev3fb-dropoff__label">
										{ row.label } <span className="dev3fb-muted">· { sprintf( /* translators: 1: count, 2: percent */ __( '%1$d visitors (%2$s)', 'dev3-ai-form-builder' ), row.count, pct( row.share ) ) }</span>
									</span>
									<ProgressBar value={ Math.round( row.share * 100 ) } tone={ row.share >= 0.4 ? 'danger' : row.share >= 0.2 ? 'warning' : 'brand' } label={ sprintf( /* translators: 1: count, 2: percent */ __( '%1$d visitors (%2$s)', 'dev3-ai-form-builder' ), row.count, pct( row.share ) ) } />
								</li>
							) ) }
						</ol>
					) }
				</Card>
				{ definition.steps.length > 1 && (
					<Card title={ __( 'Steps reached', 'dev3-ai-form-builder' ) }>
						<ol className="dev3fb-dropoff">
							{ definition.steps.map( ( step, i ) => {
								const row = f.steps.find( ( s ) => s.step === i );
								const n = row ? row.sessions : 0;
								return (
									<li key={ step.id }>
										<span className="dev3fb-dropoff__label">
											{ step.title || sprintf( /* translators: %d: step */ __( 'Step %d', 'dev3-ai-form-builder' ), i + 1 ) } <span className="dev3fb-muted">· { sprintf( /* translators: %d: sessions */ __( '%d visitors', 'dev3-ai-form-builder' ), n ) }</span>
										</span>
										<ProgressBar value={ Math.round( ( 100 * n ) / maxStep ) } label={ sprintf( /* translators: %d: sessions */ __( '%d visitors', 'dev3-ai-form-builder' ), n ) } />
									</li>
								);
							} ) }
						</ol>
					</Card>
				) }
			</Columns>
			{ data.hints.length > 0 && (
				<Card title={ __( 'Quick wins', 'dev3-ai-form-builder' ) } description={ __( 'Rule-based checks of your funnel (no AI).', 'dev3-ai-form-builder' ) }>
					<ul className="dev3fb-advice-list">
						{ data.hints.map( ( item, i ) => (
							<Advice key={ i } item={ item } form={ form } definition={ definition } dirty={ dirty } onApply={ onApply } />
						) ) }
					</ul>
				</Card>
			) }
			<Card tone="ai" title={ __( 'DEV3 AI conversion advice', 'dev3-ai-form-builder' ) } description={ __( 'DEV3 AI reads the funnel and your fields, explains what causes drop-off and suggests fixes you can preview and apply.', 'dev3-ai-form-builder' ) }>
				<Stack gap={ 12 }>
					{ dirty && <Notice tone="warning">{ __( 'Save your changes first.', 'dev3-ai-form-builder' ) }</Notice> }
					<div>
						<AiButton onClick={ ask } loading={ busy } disabled={ busy || ! aiReady || dirty }>
							{ advice ? __( 'Ask DEV3 AI again', 'dev3-ai-form-builder' ) : __( 'Get advice with DEV3 AI', 'dev3-ai-form-builder' ) }
						</AiButton>
					</div>
					<ErrorNotice error={ aiError } onRetry={ ask } onDismiss={ () => setAiError( null ) } />
					{ advice && (
						<>
							{ advice.summary && <p className="dev3fb-advice__summary">{ advice.summary }</p> }
							<ul className="dev3fb-advice-list">
								{ advice.items.map( ( item, i ) => (
									<Advice key={ i } item={ item } form={ form } definition={ definition } dirty={ dirty } onApply={ onApply } />
								) ) }
							</ul>
						</>
					) }
				</Stack>
			</Card>
		</Stack>
	);
}
