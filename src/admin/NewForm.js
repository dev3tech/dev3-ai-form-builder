/**
 * New form: describe it to DEV3 AI, start from a template, a blank form or an import.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Columns, ErrorNotice, Icon, Notice, PageHeader, Select, SkeletonCard, Stack, Textarea, useToast } from '../core3/ui';
import FieldPreview from './builder/FieldPreview';

const EXAMPLES = [
	__( 'A 3-step wedding photography inquiry with date, venue, package choice with prices and a budget question', 'dev3-ai-form-builder' ),
	__( 'Job application for a barista: availability, experience, CV upload and a consent checkbox', 'dev3-ai-form-builder' ),
	__( 'Website quote request that asks for pages, features and shows an e-commerce question only for shops', 'dev3-ai-form-builder' ),
	__( 'Customer satisfaction survey with NPS and a follow-up question for low scores', 'dev3-ai-form-builder' ),
];

/**
 * Visual preview of an AI-generated form (draft; nothing saved).
 *
 * @param {Object} props Props.
 * @return {Element} Preview.
 */
function DraftPreview( { draft, onAccept, onReject, onRegenerate, regenerating, accepting } ) {
	const def = draft.definition;
	const count = def.steps.reduce( ( n, s ) => n + s.fields.length, 0 );
	const logic = def.steps.reduce( ( n, s ) => n + ( s.logic ? 1 : 0 ) + s.fields.filter( ( f ) => f.logic ).length, 0 );
	return (
		<section className={ `dev3fb-ui-draft${ regenerating ? ' is-busy' : '' }` } aria-label={ __( 'DEV3 AI form draft', 'dev3-ai-form-builder' ) }>
			<header className="dev3fb-ui-draft__head">
				<div className="dev3fb-ui-draft__title">
					<span className="dev3fb-ui-draft__spark" aria-hidden="true">
						<Icon name="sparkles" size={ 16 } />
					</span>
					<h3>{ draft.title }</h3>
					<Badge tone="warning">{ __( 'Not saved yet', 'dev3-ai-form-builder' ) }</Badge>
				</div>
				<span className="dev3fb-muted">
					{ sprintf( /* translators: 1: fields, 2: steps, 3: logic rules */ __( '%1$d fields · %2$d steps · %3$d with logic', 'dev3-ai-form-builder' ), count, def.steps.length, logic ) }
				</span>
			</header>
			<div className="dev3fb-ui-draft__body">
				{ draft.fixes && draft.fixes.length > 0 && (
					<Notice tone="info" title={ sprintf( /* translators: %d: count */ __( '%d automatic fixes', 'dev3-ai-form-builder' ), draft.fixes.length ) }>
						<ul className="dev3fb-list">
							{ draft.fixes.map( ( f, i ) => (
								<li key={ i }>{ f }</li>
							) ) }
						</ul>
					</Notice>
				) }
				<div className="dev3fb-draftform">
					{ def.steps.map( ( step, i ) => (
						<div key={ step.id } className="dev3fb-draftform__step">
							{ def.steps.length > 1 && (
								<h4>
									<span className="dev3fb-canvas__stepnum">{ i + 1 }</span> { step.title || sprintf( /* translators: %d: step */ __( 'Step %d', 'dev3-ai-form-builder' ), i + 1 ) }
								</h4>
							) }
							<div className="dev3fb-draftform__fields">
								{ step.fields.map( ( f ) => (
									<div key={ f.id } className={ `dev3fb-draftform__field is-${ f.width }` }>
										<FieldPreview field={ f } />
										{ f.logic && (
											<span className="dev3fb-draftform__logic">
												<Badge tone="info">{ __( 'Logic', 'dev3-ai-form-builder' ) }</Badge>{ ' ' }
												{ f.logic.action === 'hide' ? __( 'Hidden when', 'dev3-ai-form-builder' ) : __( 'Shown when', 'dev3-ai-form-builder' ) }{ ' ' }
												{ f.logic.rules.map( ( r ) => `${ r.field } ${ r.op.replace( '_', ' ' ) } ${ r.value || '' }` ).join( f.logic.match === 'any' ? ' or ' : ' and ' ) }
											</span>
										) }
									</div>
								) ) }
							</div>
						</div>
					) ) }
				</div>
			</div>
			<footer className="dev3fb-ui-draft__foot">
				<div className="dev3fb-ui-draft__meta">
					{ draft.model && <span>{ draft.model }</span> }
					{ draft.tokens > 0 && <span>{ sprintf( /* translators: %s: tokens */ __( '%s tokens', 'dev3-ai-form-builder' ), draft.tokens.toLocaleString() ) }</span> }
				</div>
				<div className="dev3fb-ui-draft__actions">
					<Button variant="ghost" icon="x" onClick={ onReject } disabled={ regenerating || accepting }>
						{ __( 'Reject', 'dev3-ai-form-builder' ) }
					</Button>
					<Button icon="refresh" onClick={ onRegenerate } loading={ regenerating } disabled={ accepting }>
						{ __( 'Regenerate', 'dev3-ai-form-builder' ) }
					</Button>
					<Button variant="primary" icon="check" onClick={ onAccept } loading={ accepting } disabled={ regenerating }>
						{ __( 'Use this form', 'dev3-ai-form-builder' ) }
					</Button>
				</div>
			</footer>
		</section>
	);
}

export function DescribeForm( { languages, siteLang, aiReady, onUsage, autoFocus } ) {
	const toast = useToast();
	const [ prompt, setPrompt ] = useState( '' );
	const [ steps, setSteps ] = useState( 'auto' );
	const [ language, setLanguage ] = useState( siteLang || 'en' );
	const [ draft, setDraft ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ accepting, setAccepting ] = useState( false );
	const [ error, setError ] = useState( null );
	const ref = useRef( null );
	useEffect( () => {
		if ( autoFocus && ref.current ) {
			const el = ref.current.querySelector( 'textarea' );
			if ( el ) {
				el.focus();
			}
		}
	}, [ autoFocus ] );

	const generate = () => {
		setBusy( true );
		setError( null );
		request( 'ai/form', { method: 'POST', data: { prompt, steps, language } } )
			.then( setDraft )
			.catch( setError )
			.finally( () => {
				setBusy( false );
				onUsage();
			} );
	};
	const accept = () => {
		setAccepting( true );
		request( 'forms', { method: 'POST', data: { title: draft.title, status: 'draft', definition: draft.definition } } )
			.then( ( form ) => {
				toast.success( __( 'Form created as a draft. Review it, then set it to Active.', 'dev3-ai-form-builder' ) );
				window.location.hash = `#/forms/${ form.id }`;
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setAccepting( false ) );
	};

	return (
		<Card tone="ai" title={ __( 'Describe your form', 'dev3-ai-form-builder' ) } description={ __( 'One sentence is enough. DEV3 AI builds the fields, steps, options, conditional logic and calculations. You review it before anything is saved.', 'dev3-ai-form-builder' ) }>
			<Stack gap={ 14 }>
				<div ref={ ref }>
					<Textarea label={ __( 'What should the form do?', 'dev3-ai-form-builder' ) } rows={ 3 } maxLength={ 2000 } value={ prompt } onChange={ setPrompt } placeholder={ EXAMPLES[ 0 ] } />
				</div>
				<div className="dev3fb-tokens" role="group" aria-label={ __( 'Examples', 'dev3-ai-form-builder' ) }>
					{ EXAMPLES.map( ( ex ) => (
						<button key={ ex } type="button" className="dev3fb-token" onClick={ () => setPrompt( ex ) }>
							{ ex.length > 60 ? ex.slice( 0, 58 ) + '…' : ex }
						</button>
					) ) }
				</div>
				<div className="dev3fb-inline is-end">
					<Select label={ __( 'Steps', 'dev3-ai-form-builder' ) } value={ steps } onChange={ setSteps } options={ [ { value: 'auto', label: __( 'Let DEV3 AI decide', 'dev3-ai-form-builder' ) }, { value: 'single', label: __( 'One page', 'dev3-ai-form-builder' ) }, { value: 'multi', label: __( 'Multi-step', 'dev3-ai-form-builder' ) } ] } />
					<Select label={ __( 'Language', 'dev3-ai-form-builder' ) } value={ language } onChange={ setLanguage } options={ languages } />
					<AiButton onClick={ generate } loading={ busy && ! draft } disabled={ prompt.trim().length < 8 || busy || ! aiReady } />
				</div>
				{ ! aiReady && (
					<Notice tone="ai" actions={ <Button size="sm" variant="primary" href="#/api">{ __( 'Add your API key', 'dev3-ai-form-builder' ) }</Button> }>
						{ __( 'Connect a Google Gemini API key to generate forms. Templates and the builder work without it.', 'dev3-ai-form-builder' ) }
					</Notice>
				) }
				<ErrorNotice error={ error } onRetry={ generate } onDismiss={ () => setError( null ) } />
				{ busy && ! draft && <SkeletonCard lines={ 6 } /> }
				{ draft && <DraftPreview draft={ draft } onAccept={ accept } onReject={ () => setDraft( null ) } onRegenerate={ generate } regenerating={ busy } accepting={ accepting } /> }
			</Stack>
		</Card>
	);
}

export default function NewForm( { meta, aiReady, onUsage, ai } ) {
	const toast = useToast();
	const [ templates, setTemplates ] = useState( null );
	const [ creating, setCreating ] = useState( '' );
	const [ error, setError ] = useState( null );
	const fileRef = useRef( null );
	useEffect( () => {
		request( 'templates' ).then( setTemplates ).catch( setError );
	}, [] );

	const create = ( data, key ) => {
		setCreating( key );
		request( 'forms', { method: 'POST', data } )
			.then( ( form ) => {
				window.location.hash = `#/forms/${ form.id }`;
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setCreating( '' ) );
	};
	const importFile = ( file ) => {
		const reader = new window.FileReader();
		reader.onload = () => {
			try {
				const json = JSON.parse( String( reader.result ) );
				const definition = json.definition || json;
				create( { title: json.title || file.name.replace( /\.json$/, '' ), status: 'draft', definition }, 'import' );
			} catch ( e ) {
				toast.error( __( 'That file is not a valid form export (JSON).', 'dev3-ai-form-builder' ) );
			}
		};
		reader.readAsText( file );
	};

	return (
		<>
			<PageHeader back={ { href: '#/forms', label: __( 'All forms', 'dev3-ai-form-builder' ) } } title={ __( 'New form', 'dev3-ai-form-builder' ) } description={ __( 'Pick how you want to start. Everything can be changed in the builder.', 'dev3-ai-form-builder' ) } />
			<DescribeForm languages={ ( meta && meta.languages ) || [] } siteLang={ meta && meta.site_lang } aiReady={ aiReady } onUsage={ onUsage } autoFocus={ ai } />
			<Card title={ __( 'Start from a template', 'dev3-ai-form-builder' ) }>
				<ErrorNotice error={ error } />
				{ ! templates && ! error && <SkeletonCard lines={ 3 } /> }
				<Columns min={ 230 } gap={ 12 }>
					<button type="button" className="dev3fb-template is-blank" onClick={ () => create( { title: __( 'Untitled form', 'dev3-ai-form-builder' ) }, 'blank' ) } disabled={ !! creating }>
						<span className="dev3fb-template__icon"><Icon name="plus" size={ 20 } /></span>
						<strong>{ __( 'Blank form', 'dev3-ai-form-builder' ) }</strong>
						<span className="dev3fb-muted">{ __( 'Start with an empty canvas.', 'dev3-ai-form-builder' ) }</span>
					</button>
					{ ( templates || [] ).map( ( t ) => (
						<button key={ t.id } type="button" className="dev3fb-template" onClick={ () => create( { template: t.id }, t.id ) } disabled={ !! creating } aria-busy={ creating === t.id }>
							<span className="dev3fb-template__icon"><Icon name={ t.icon } size={ 20 } /></span>
							<strong>{ t.title }</strong>
							<span className="dev3fb-muted">{ t.description }</span>
							<span className="dev3fb-template__meta">
								{ sprintf( /* translators: %d: fields */ _n( '%d field', '%d fields', t.fields, 'dev3-ai-form-builder' ), t.fields ) } · { sprintf( /* translators: %d: steps */ _n( '%d step', '%d steps', t.steps, 'dev3-ai-form-builder' ), t.steps ) }
							</span>
						</button>
					) ) }
					<button type="button" className="dev3fb-template is-blank" onClick={ () => fileRef.current && fileRef.current.click() } disabled={ !! creating }>
						<span className="dev3fb-template__icon"><Icon name="file" size={ 20 } /></span>
						<strong>{ __( 'Import JSON', 'dev3-ai-form-builder' ) }</strong>
						<span className="dev3fb-muted">{ __( 'A form exported from another site.', 'dev3-ai-form-builder' ) }</span>
					</button>
				</Columns>
				<input ref={ fileRef } type="file" accept="application/json,.json" hidden onChange={ ( e ) => e.target.files[ 0 ] && importFile( e.target.files[ 0 ] ) } aria-label={ __( 'Import JSON', 'dev3-ai-form-builder' ) } />
			</Card>
		</>
	);
}
