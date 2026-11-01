/**
 * Smart translation (DEV3 AI): translate the form's text and messages into other languages.
 * The draft is reviewed (and edited) before it is stored in the form's translations.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Card, EmptyState, ErrorNotice, Notice, Select, Stack, useToast } from '../../core3/ui';

const label = ( key, source ) => {
	const [ kind, id, prop ] = key.split( '.' );
	const where = kind === 'field' ? `${ id } · ${ String( prop || '' ).replace( /_\d+$/, '' ).replace( 'option', __( 'option', 'dev3-ai-form-builder' ) ) }` : `${ kind } · ${ prop || id }`;
	const text = String( source || '' ).replace( /<[^>]+>/g, '' );
	return `${ text.length > 90 ? text.slice( 0, 90 ) + '…' : text }  (${ where })`;
};

/**
 * @param {Object}   props            Props.
 * @param {Object}   props.form       Saved form (id).
 * @param {Object}   props.definition Current definition.
 * @param {boolean}  props.dirty      Unsaved changes.
 * @param {Array}    props.languages  [ { value, label } ].
 * @param {boolean}  props.aiReady    AI available.
 * @param {Function} props.onChange   ( translations ) => void.
 * @param {Function} props.onUsage    Refresh usage meter.
 * @return {Element} Panel.
 */
export default function TranslatePanel( { form, definition, dirty, languages, aiReady, onChange, onUsage } ) {
	const toast = useToast();
	const [ lang, setLang ] = useState( '' );
	const [ draft, setDraft ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const translations = definition.translations || {};
	const existing = Object.keys( translations );
	const name = ( code ) => ( languages.find( ( l ) => l.value === code ) || { label: code.toUpperCase() } ).label;

	const run = ( code = lang ) => {
		setBusy( true );
		setError( null );
		request( `forms/${ form.id }/translate`, { method: 'POST', data: { lang: code } } )
			.then( ( res ) => setDraft( res ) )
			.catch( setError )
			.finally( () => {
				setBusy( false );
				onUsage();
			} );
	};
	const editExisting = ( code ) => {
		const map = translations[ code ] || {};
		setDraft( {
			lang: code,
			manual: true,
			strings: Object.keys( map ).map( ( key ) => ( { key, source: map[ key ], text: map[ key ] } ) ),
			notes: [],
		} );
	};
	const accept = ( values ) => {
		onChange( { ...translations, [ draft.lang ]: values } );
		toast.success( sprintf( /* translators: %s: language */ __( '%s translation added. Save the form to publish it.', 'dev3-ai-form-builder' ), name( draft.lang ) ) );
		setDraft( null );
	};
	const remove = ( code ) => {
		const next = { ...translations };
		delete next[ code ];
		onChange( next );
	};

	return (
		<Stack gap={ 20 }>
			<Card
				tone="ai"
				title={ __( 'Translate with DEV3 AI', 'dev3-ai-form-builder' ) }
				description={ __( 'Labels, help texts, options, buttons and messages are translated in one go. Visitors see the form in their language automatically (site language, WPML or Polylang), or use [dev3fb_form id="…" lang="de"].', 'dev3-ai-form-builder' ) }
			>
				<Stack gap={ 12 }>
					{ dirty && <Notice tone="warning">{ __( 'Save your changes first: DEV3 AI translates the saved version of the form.', 'dev3-ai-form-builder' ) }</Notice> }
					<div className="dev3fb-inline is-end">
						<Select label={ __( 'Language', 'dev3-ai-form-builder' ) } value={ lang } onChange={ setLang } options={ languages } placeholder={ __( 'Choose a language…', 'dev3-ai-form-builder' ) } />
						<AiButton onClick={ () => run() } loading={ busy && ! draft } disabled={ ! lang || busy || dirty || ! aiReady }>
							{ __( 'Translate with DEV3 AI', 'dev3-ai-form-builder' ) }
						</AiButton>
					</div>
					{ ! aiReady && <p className="dev3fb-muted">{ __( 'Add a Gemini API key in API Config to use AI translation.', 'dev3-ai-form-builder' ) }</p> }
					<ErrorNotice error={ error } onRetry={ () => run() } onDismiss={ () => setError( null ) } />
					{ draft && draft.notes && draft.notes.length > 0 && <Notice tone="info">{ draft.notes.join( ' ' ) }</Notice> }
					{ draft && (
						<AiDraftReview
							title={ sprintf( /* translators: %s: language */ __( 'DEV3 AI · %s translation', 'dev3-ai-form-builder' ), name( draft.lang ) ) }
							fields={ draft.strings.map( ( s ) => ( { key: s.key, label: label( s.key, s.source ), multiline: s.source.length > 80 } ) ) }
							draft={ Object.fromEntries( draft.strings.map( ( s ) => [ s.key, s.text ] ) ) }
							onAccept={ accept }
							onReject={ () => setDraft( null ) }
							onRegenerate={ draft.manual ? undefined : () => run( draft.lang ) }
							regenerating={ busy }
							acceptLabel={ __( 'Use this translation', 'dev3-ai-form-builder' ) }
							meta={ { model: draft.model, tokens: draft.tokens } }
						/>
					) }
				</Stack>
			</Card>
			<Card title={ __( 'Translations', 'dev3-ai-form-builder' ) }>
				{ existing.length === 0 ? (
					<EmptyState compact icon="external" title={ __( 'No translations yet', 'dev3-ai-form-builder' ) } description={ __( 'The form is shown in its original language to everyone.', 'dev3-ai-form-builder' ) } />
				) : (
					<ul className="dev3fb-langs">
						{ existing.map( ( code ) => (
							<li key={ code }>
								<span>
									<strong>{ name( code ) }</strong> <Badge>{ code }</Badge> <span className="dev3fb-muted">{ sprintf( /* translators: %d: count */ __( '%d strings', 'dev3-ai-form-builder' ), Object.keys( translations[ code ] ).length ) }</span>
								</span>
								<span className="dev3fb-inline">
									<Button size="sm" variant="ghost" href={ `${ form.preview }&lang=${ code }` } target="_blank" rel="noreferrer">
										{ __( 'Preview', 'dev3-ai-form-builder' ) }
									</Button>
									<Button size="sm" onClick={ () => editExisting( code ) }>{ __( 'Edit', 'dev3-ai-form-builder' ) }</Button>
									<Button size="sm" variant="ghost" onClick={ () => remove( code ) }>{ __( 'Remove', 'dev3-ai-form-builder' ) }</Button>
								</span>
							</li>
						) ) }
					</ul>
				) }
			</Card>
		</Stack>
	);
}
