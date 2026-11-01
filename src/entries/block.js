/**
 * "DEV3 AI Form" block: pick a form; rendered on the server (same markup as the shortcode).
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder, SelectControl, Spinner, ExternalLink } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { restUrl } from '../core3/config';
import config from '../core3/config';

const icon = (
	<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
		<path d="M5 3h10l4 4v14H5z" fill="none" stroke="currentColor" strokeWidth="1.6" />
		<path d="M8 9h8M8 12.5h8M8 16h5" stroke="currentColor" strokeWidth="1.6" />
	</svg>
);

function useForms() {
	const [ forms, setForms ] = useState( null );
	useEffect( () => {
		apiFetch( { url: restUrl( 'forms/options' ) } )
			.then( setForms )
			.catch( () => setForms( [] ) );
	}, [] );
	return forms;
}

function Edit( { attributes, setAttributes } ) {
	const props = useBlockProps();
	const forms = useForms();
	const { formId, lang } = attributes;
	const current = forms && forms.find( ( f ) => f.value === formId );
	const options = [ { value: 0, label: __( 'Choose a form…', 'dev3-ai-form-builder' ) } ].concat( ( forms || [] ).map( ( f ) => ( { value: f.value, label: f.status === 'draft' ? `${ f.label } (${ __( 'draft', 'dev3-ai-form-builder' ) })` : f.label } ) ) );
	const langs = [ { value: '', label: __( 'Automatic (site language)', 'dev3-ai-form-builder' ) } ].concat( ( ( current && current.languages ) || [] ).map( ( l ) => ( { value: l, label: l.toUpperCase() } ) ) );
	const adminLink = `${ config.adminUrl || '/wp-admin/' }admin.php?page=dev3-ai-form-builder#/forms${ formId ? '/' + formId : '/new' }`;
	const picker = (
		<SelectControl
			label={ __( 'Form', 'dev3-ai-form-builder' ) }
			value={ formId }
			options={ options }
			onChange={ ( v ) => setAttributes( { formId: Number( v ) } ) }
			__nextHasNoMarginBottom
		/>
	);
	return (
		<div { ...props }>
			<InspectorControls>
				<PanelBody title={ __( 'Form settings', 'dev3-ai-form-builder' ) }>
					{ forms ? picker : <Spinner /> }
					{ current && current.languages && current.languages.length > 0 && (
						<SelectControl label={ __( 'Language', 'dev3-ai-form-builder' ) } value={ lang } options={ langs } onChange={ ( v ) => setAttributes( { lang: v } ) } __nextHasNoMarginBottom />
					) }
					<p>
						<ExternalLink href={ adminLink }>{ formId ? __( 'Edit this form in DEV3 AI Forms', 'dev3-ai-form-builder' ) : __( 'Create a form with DEV3 AI', 'dev3-ai-form-builder' ) }</ExternalLink>
					</p>
				</PanelBody>
			</InspectorControls>
			{ ! formId ? (
				<Placeholder icon={ icon } label={ __( 'DEV3 AI Form', 'dev3-ai-form-builder' ) } instructions={ __( 'Choose a form to show here.', 'dev3-ai-form-builder' ) }>
					{ forms ? picker : <Spinner /> }
					<ExternalLink href={ adminLink }>{ __( 'Create a new form', 'dev3-ai-form-builder' ) }</ExternalLink>
				</Placeholder>
			) : (
				<div style={ { pointerEvents: 'none' } }>
					<ServerSideRender block="dev3fb/form" attributes={ { formId, lang } } />
				</div>
			) }
		</div>
	);
}

registerBlockType( 'dev3fb/form', {
	apiVersion: 2,
	title: __( 'DEV3 AI Form', 'dev3-ai-form-builder' ),
	description: __( 'Show a form built with DEV3 AI Form Builder.', 'dev3-ai-form-builder' ),
	category: 'widgets',
	icon,
	keywords: [ __( 'form', 'dev3-ai-form-builder' ), __( 'contact', 'dev3-ai-form-builder' ), 'dev3' ],
	attributes: {
		formId: { type: 'number', default: 0 },
		lang: { type: 'string', default: '' },
	},
	supports: { html: false, align: [ 'wide', 'full' ] },
	edit: Edit,
	save: () => null,
} );
