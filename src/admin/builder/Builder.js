/**
 * Form editor: drag-and-drop builder plus per-form settings tabs.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../../core3/api';
import { Badge, Button, Drawer, ErrorNotice, Modal, Select, SkeletonCard, Tabs, TextField, useToast } from '../../core3/ui';
import Canvas from './Canvas';
import Inspector from './Inspector';
import Palette from './Palette';
import SettingsPanel from './SettingsPanel';
import MessagesPanel from './MessagesPanel';
import IntegrationsPanel from './IntegrationsPanel';
import PaymentsPanel from './PaymentsPanel';
import ProtectionPanel from './ProtectionPanel';
import TranslatePanel from './TranslatePanel';
import InsightsPanel from './InsightsPanel';
import * as M from './model';
import { download } from '../download';

const TABS = [
	{ id: 'build', label: __( 'Build', 'dev3-ai-form-builder' ) },
	{ id: 'settings', label: __( 'Settings', 'dev3-ai-form-builder' ) },
	{ id: 'messages', label: __( 'Emails & confirmations', 'dev3-ai-form-builder' ) },
	{ id: 'integrations', label: __( 'Integrations', 'dev3-ai-form-builder' ) },
	{ id: 'payments', label: __( 'Payments', 'dev3-ai-form-builder' ) },
	{ id: 'protection', label: __( 'Spam & autofill', 'dev3-ai-form-builder' ) },
	{ id: 'translate', label: __( 'Translate', 'dev3-ai-form-builder' ) },
	{ id: 'insights', label: __( 'Insights', 'dev3-ai-form-builder' ) },
];

function useWidth( ref, ready ) {
	const [ width, setWidth ] = useState( 1200 );
	useEffect( () => {
		if ( ! ready || ! ref.current || ! window.ResizeObserver ) {
			return undefined;
		}
		setWidth( ref.current.getBoundingClientRect().width );
		const ro = new window.ResizeObserver( ( entries ) => setWidth( entries[ 0 ].contentRect.width ) );
		ro.observe( ref.current );
		return () => ro.disconnect();
	}, [ ref, ready ] );
	return width;
}

const SYMBOLS = { USD: '$', EUR: '€', GBP: '£', JPY: '¥', INR: '₹' };

/**
 * @param {Object}   props         Props.
 * @param {number}   props.id      Form id.
 * @param {string}   props.tab     Initial tab.
 * @param {Object}   props.meta    { pages, languages }.
 * @param {Object}   props.creds   Credentials status.
 * @param {boolean}  props.aiReady AI ready.
 * @param {Function} props.onUsage Usage refresh.
 * @return {Element} Builder.
 */
export default function Builder( { id, tab: initialTab = 'build', meta, creds, aiReady, onUsage } ) {
	const toast = useToast();
	const [ form, setForm ] = useState( null );
	const [ definition, setDefinition ] = useState( null );
	const [ title, setTitle ] = useState( '' );
	const [ status, setStatus ] = useState( 'active' );
	const [ dirty, setDirty ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ selection, setSelection ] = useState( null );
	const [ tab, setTab ] = useState( TABS.some( ( t ) => t.id === initialTab ) ? initialTab : 'build' );
	const [ paletteOpen, setPaletteOpen ] = useState( false );
	const [ addStep, setAddStep ] = useState( 0 );
	const [ embedOpen, setEmbedOpen ] = useState( false );
	const rootRef = useRef( null );
	const width = useWidth( rootRef, !! definition );
	const wide = width >= 1080;
	const medium = width >= 760;

	const load = useCallback( () => {
		setError( null );
		request( `forms/${ id }` )
			.then( ( res ) => {
				setForm( res );
				setDefinition( res.definition );
				setTitle( res.title );
				setStatus( res.status );
				setDirty( false );
			} )
			.catch( setError );
	}, [ id ] );
	useEffect( load, [ load ] );

	const change = ( next ) => {
		setDefinition( ( prev ) => ( typeof next === 'function' ? next( prev ) : next ) );
		setDirty( true );
	};

	const save = useCallback( () => {
		if ( ! definition ) {
			return Promise.resolve();
		}
		setSaving( true );
		return request( `forms/${ id }`, { method: 'PUT', data: { title, status, definition } } )
			.then( ( res ) => {
				setForm( res );
				setDefinition( res.definition );
				setDirty( false );
				toast.success( __( 'Form saved.', 'dev3-ai-form-builder' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setSaving( false ) );
	}, [ id, title, status, definition, toast ] );

	useEffect( () => {
		const onKey = ( e ) => {
			if ( ( e.metaKey || e.ctrlKey ) && e.key === 's' ) {
				e.preventDefault();
				save();
			}
		};
		const onUnload = ( e ) => {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		};
		window.addEventListener( 'keydown', onKey );
		window.addEventListener( 'beforeunload', onUnload );
		return () => {
			window.removeEventListener( 'keydown', onKey );
			window.removeEventListener( 'beforeunload', onUnload );
		};
	}, [ save, dirty ] );

	if ( error ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! definition ) {
		return <SkeletonCard lines={ 8 } />;
	}

	const symbol = SYMBOLS[ definition.payments.currency ] || `${ definition.payments.currency } `;
	const currentStep = selection ? ( selection.kind === 'field' ? ( M.locate( definition, selection.id ) || { step: 0 } ).step : selection.step ) : definition.steps.length - 1;

	const add = ( type, step = currentStep, index = -1 ) => {
		let at = index;
		if ( at === -1 && selection && selection.kind === 'field' ) {
			const pos = M.locate( definition, selection.id );
			if ( pos && pos.step === step ) {
				at = pos.index + 1;
			}
		}
		const res = M.addField( definition, type, step, at );
		change( res.def );
		setSelection( { kind: 'field', id: res.id, step } );
		setPaletteOpen( false );
	};

	const inspector = (
		<Inspector
			definition={ definition }
			selection={ selection }
			onField={ ( fid, patch ) => change( ( d ) => M.updateField( d, fid, patch ) ) }
			onRename={ ( fid, next ) => {
				const renamed = M.renameField( definition, fid, next );
				if ( renamed === definition ) {
					toast.warning( __( 'That ID is empty or already used.', 'dev3-ai-form-builder' ) );
					return;
				}
				change( renamed );
				setSelection( { kind: 'field', id: M.slug( next ), step: currentStep } );
			} }
			onStep={ ( index, patch ) => change( ( d ) => M.updateStep( d, index, patch ) ) }
			onClose={ wide ? undefined : () => setSelection( null ) }
		/>
	);

	const build = (
		<div className={ `dev3fb-builder${ wide ? ' is-wide' : '' }${ medium ? ' is-medium' : '' }` }>
			{ wide && (
				<aside className="dev3fb-builder__palette" aria-label={ __( 'Fields', 'dev3-ai-form-builder' ) }>
					<Palette onAdd={ ( type ) => add( type ) } />
				</aside>
			) }
			<div className="dev3fb-builder__canvas">
				{ ! wide && (
					<div className="dev3fb-builder__bar">
						<Button variant="primary" icon="plus" onClick={ () => {
							setAddStep( currentStep );
							setPaletteOpen( true );
						} }>
							{ __( 'Add field', 'dev3-ai-form-builder' ) }
						</Button>
						<span className="dev3fb-muted">{ sprintf( /* translators: 1: fields, 2: steps */ __( '%1$d fields · %2$d step(s)', 'dev3-ai-form-builder' ), M.allFields( definition ).length, definition.steps.length ) }</span>
					</div>
				) }
				<Canvas
					definition={ definition }
					selection={ selection }
					symbol={ symbol }
					onSelect={ setSelection }
					onDropType={ ( type, step, index ) => add( type, step, index ) }
					onMove={ ( fid, step, index ) => change( ( d ) => M.moveField( d, fid, step, index ) ) }
					onNudge={ ( fid, dir ) => change( ( d ) => M.nudgeField( d, fid, dir ) ) }
					onDuplicate={ ( fid ) => {
						const res = M.duplicateField( definition, fid );
						change( res.def );
						setSelection( { kind: 'field', id: res.id, step: currentStep } );
					} }
					onRemove={ ( fid ) => {
						change( ( d ) => M.removeField( d, fid ) );
						if ( selection && selection.id === fid ) {
							setSelection( null );
						}
					} }
					onAddStep={ () => {
						const next = M.addStep( definition, sprintf( /* translators: %d: step */ __( 'Step %d', 'dev3-ai-form-builder' ), definition.steps.length + 1 ) );
						if ( definition.steps.length === 1 && ! definition.steps[ 0 ].title ) {
							next.steps[ 0 ] = { ...next.steps[ 0 ], title: __( 'Step 1', 'dev3-ai-form-builder' ) };
						}
						change( next );
						setSelection( { kind: 'step', step: next.steps.length - 1 } );
					} }
					onStep={ ( action, index ) => {
						if ( action === 'remove' ) {
							change( ( d ) => M.removeStep( d, index ) );
							setSelection( null );
						} else {
							change( ( d ) => M.moveStep( d, index, action === 'up' ? -1 : 1 ) );
						}
					} }
					onAddField={ ( step ) => {
						setAddStep( step );
						if ( wide ) {
							setSelection( { kind: 'step', step } );
							const el = document.getElementById( 'dev3fb-palette-search' );
							if ( el ) {
								el.focus();
							}
						} else {
							setPaletteOpen( true );
						}
					} }
				/>
			</div>
			{ medium && <aside className="dev3fb-builder__inspector" aria-label={ __( 'Field settings', 'dev3-ai-form-builder' ) }>{ inspector }</aside> }
			{ ! medium && (
				<Drawer open={ !! selection } onClose={ () => setSelection( null ) } title={ __( 'Edit', 'dev3-ai-form-builder' ) } size="md">
					{ inspector }
				</Drawer>
			) }
			<Modal open={ paletteOpen } onClose={ () => setPaletteOpen( false ) } title={ __( 'Add a field', 'dev3-ai-form-builder' ) } size="lg">
				<Palette onAdd={ ( type ) => add( type, addStep ) } />
			</Modal>
		</div>
	);

	const top = ( patch ) => change( ( d ) => ( { ...d, ...patch } ) );
	const panels = {
		build: () => build,
		settings: () => <SettingsPanel definition={ definition } onChange={ ( patch ) => top( { settings: { ...definition.settings, ...patch } } ) } />,
		messages: () => <MessagesPanel definition={ definition } pages={ ( meta && meta.pages ) || [] } onChange={ top } />,
		integrations: () => <IntegrationsPanel definition={ definition } creds={ creds && creds.secrets } onChange={ top } />,
		payments: () => (
			<PaymentsPanel
				definition={ definition }
				payments={ creds }
				onChange={ ( patch ) => top( { payments: { ...definition.payments, ...patch } } ) }
				onAddTotal={ () => {
					let next = M.addField( definition, 'product', definition.steps.length - 1 ).def;
					next = M.addField( next, 'total', next.steps.length - 1 ).def;
					change( next );
					setTab( 'build' );
				} }
			/>
		),
		protection: () => <ProtectionPanel definition={ definition } aiReady={ aiReady } onChange={ ( patch ) => top( { protection: { ...definition.protection, ...patch } } ) } />,
		translate: () => <TranslatePanel form={ form } definition={ definition } dirty={ dirty } languages={ ( meta && meta.languages ) || [] } aiReady={ aiReady } onChange={ ( translations ) => top( { translations } ) } onUsage={ onUsage } />,
		insights: () => (
			<InsightsPanel
				form={ form }
				definition={ definition }
				dirty={ dirty }
				aiReady={ aiReady }
				onUsage={ onUsage }
				onApply={ ( next ) => {
					change( next );
					toast.info( __( 'Fix applied. Review it in the builder and save.', 'dev3-ai-form-builder' ) );
				} }
			/>
		),
	};

	return (
		<div className="dev3fb-editor" ref={ rootRef }>
			<div className="dev3fb-editor__head">
				<a className="dev3fb-editor__back" href="#/forms">
					← { __( 'All forms', 'dev3-ai-form-builder' ) }
				</a>
				<div className="dev3fb-editor__row">
					<div className="dev3fb-editor__title">
						<TextField
							label={ __( 'Form title', 'dev3-ai-form-builder' ) }
							hideLabel
							value={ title }
							onChange={ ( v ) => {
								setTitle( v );
								setDirty( true );
							} }
							maxLength={ 190 }
						/>
					</div>
					<div className="dev3fb-editor__actions">
						{ dirty ? <Badge tone="warning" dot>{ __( 'Unsaved', 'dev3-ai-form-builder' ) }</Badge> : <Badge tone="success" dot>{ __( 'Saved', 'dev3-ai-form-builder' ) }</Badge> }
						<Select
							label={ __( 'Status', 'dev3-ai-form-builder' ) }
							hideLabel
							value={ status }
							onChange={ ( v ) => {
								setStatus( v );
								setDirty( true );
							} }
							options={ [ { value: 'active', label: __( 'Active', 'dev3-ai-form-builder' ) }, { value: 'draft', label: __( 'Draft (hidden)', 'dev3-ai-form-builder' ) } ] }
						/>
						<Button icon="external" href={ form.preview } target="_blank" rel="noreferrer">
							{ __( 'Preview', 'dev3-ai-form-builder' ) }
						</Button>
						<Button icon="copy" onClick={ () => setEmbedOpen( true ) }>
							{ __( 'Embed', 'dev3-ai-form-builder' ) }
						</Button>
						<Button variant="primary" icon="check" onClick={ save } loading={ saving } disabled={ ! dirty && ! saving }>
							{ __( 'Save', 'dev3-ai-form-builder' ) }
						</Button>
					</div>
				</div>
			</div>
			<Tabs tabs={ TABS } value={ tab } onChange={ setTab } label={ __( 'Form sections', 'dev3-ai-form-builder' ) } />
			<div className="dev3fb-editor__panel">{ panels[ tab ]() }</div>
			<Modal
				open={ embedOpen }
				onClose={ () => setEmbedOpen( false ) }
				title={ __( 'Embed this form', 'dev3-ai-form-builder' ) }
				description={ __( 'Use the "DEV3 AI Form" block in the block editor, or paste the shortcode into any page, widget, Elementor or WPBakery text element.', 'dev3-ai-form-builder' ) }
				footer={ <Button variant="primary" onClick={ () => setEmbedOpen( false ) }>{ __( 'Done', 'dev3-ai-form-builder' ) }</Button> }
			>
				<div className="dev3fb-embed">
					<code>{ form.shortcode }</code>
					<Button
						icon="copy"
						onClick={ () => {
							if ( navigator.clipboard ) {
								navigator.clipboard.writeText( form.shortcode ).then( () => toast.success( __( 'Shortcode copied.', 'dev3-ai-form-builder' ) ) ).catch( () => {} );
							}
						} }
					>
						{ __( 'Copy', 'dev3-ai-form-builder' ) }
					</Button>
				</div>
				<p className="dev3fb-muted">{ __( 'PHP templates:', 'dev3-ai-form-builder' ) } <code>{ `<?php echo do_shortcode( '${ form.shortcode.replace( /'/g, "\\'" ) }' ); ?>` }</code></p>
				<p>
					<Button icon="file" size="sm" onClick={ () => download( `${ M.slug( title, 'form' ) }.json`, JSON.stringify( { title, definition }, null, 2 ), 'application/json' ) }>
						{ __( 'Download JSON (import on another site)', 'dev3-ai-form-builder' ) }
					</Button>
				</p>
			</Modal>
		</div>
	);
}
