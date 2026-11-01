/**
 * Settings for the selected field or step.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Badge, Button, Checkbox, NumberField, Select, Stack, Tabs, TextField, Textarea, Toggle } from '../../core3/ui';
import { tryEvaluate, references } from '../../shared/formula';
import LogicEditor from './LogicEditor';
import OptionsEditor from './OptionsEditor';
import { hasOptions, isComputed, isInput, kind, typeLabel } from './fieldTypes';
import { allFields, earlierFields, fieldsBeforeStep } from './model';

const WIDTHS = [
	{ value: 'full', label: __( 'Full width', 'dev3-ai-form-builder' ) },
	{ value: 'half', label: __( 'Half (1/2)', 'dev3-ai-form-builder' ) },
	{ value: 'third', label: __( 'Third (1/3)', 'dev3-ai-form-builder' ) },
];

const ROLES = [
	{ value: '', label: __( 'Automatic', 'dev3-ai-form-builder' ) },
	{ value: 'first_name', label: __( 'First name', 'dev3-ai-form-builder' ) },
	{ value: 'last_name', label: __( 'Last name', 'dev3-ai-form-builder' ) },
	{ value: 'company', label: __( 'Company', 'dev3-ai-form-builder' ) },
	{ value: 'job_title', label: __( 'Job title', 'dev3-ai-form-builder' ) },
	{ value: 'city', label: __( 'City', 'dev3-ai-form-builder' ) },
	{ value: 'zip', label: __( 'Postal code', 'dev3-ai-form-builder' ) },
	{ value: 'subject', label: __( 'Subject', 'dev3-ai-form-builder' ) },
	{ value: 'message', label: __( 'Message', 'dev3-ai-form-builder' ) },
	{ value: 'other', label: __( 'Other', 'dev3-ai-form-builder' ) },
];

const num = ( v ) => ( v === '' || v === undefined || v === null ? '' : Number( v ) );

function IdField( { field, onRename } ) {
	const [ value, setValue ] = useState( field.id );
	useEffect( () => setValue( field.id ), [ field.id ] );
	return (
		<TextField
			label={ __( 'Field ID', 'dev3-ai-form-builder' ) }
			help={ __( 'Used in merge tags {field:id}, formulas {id}, webhooks and exports. Renaming updates references.', 'dev3-ai-form-builder' ) }
			value={ value }
			onChange={ setValue }
			onBlur={ () => value !== field.id && onRename( value ) }
			spellCheck={ false }
		/>
	);
}

function FormulaEditor( { field, definition, onChange } ) {
	const sources = earlierFields( definition, field.id ).filter( ( f ) => isInput( f.type ) );
	const test = tryEvaluate( field.formula || '', Object.fromEntries( sources.map( ( f ) => [ f.id, 1 ] ) ) );
	const unknown = references( field.formula ).filter( ( id ) => id !== '_subtotal' && ! allFields( definition ).some( ( f ) => f.id === id ) );
	const insert = ( token ) => onChange( { formula: `${ field.formula || '' }${ field.formula && ! /\s$/.test( field.formula ) ? ' ' : '' }${ token }` } );
	return (
		<Stack gap={ 8 }>
			<Textarea
				label={ __( 'Formula', 'dev3-ai-form-builder' ) }
				rows={ 3 }
				value={ field.formula || '' }
				onChange={ ( v ) => onChange( { formula: v } ) }
				error={ test.error || ( unknown.length ? sprintf( /* translators: %s: ids */ __( 'Unknown field: %s', 'dev3-ai-form-builder' ), unknown.join( ', ' ) ) : undefined ) }
				help={ __( 'Use {field_id}, + - * / ( ), min, max, round(x, 2), if(condition, a, b). {_subtotal} is the sum of products and amounts.', 'dev3-ai-form-builder' ) }
				spellCheck={ false }
			/>
			<div className="dev3fb-tokens" role="group" aria-label={ __( 'Insert a field', 'dev3-ai-form-builder' ) }>
				{ sources.map( ( f ) => (
					<button key={ f.id } type="button" className="dev3fb-token" onClick={ () => insert( `{${ f.id }}` ) }>
						{ f.label || f.id }
					</button>
				) ) }
				<button type="button" className="dev3fb-token is-alt" onClick={ () => insert( '{_subtotal}' ) }>
					{ __( 'Subtotal', 'dev3-ai-form-builder' ) }
				</button>
			</div>
		</Stack>
	);
}

/**
 * @param {Object}   props            Props.
 * @param {Object}   props.definition Definition.
 * @param {Object}   props.selection  Selection.
 * @param {Function} props.onField    ( id, patch ) => void.
 * @param {Function} props.onRename   ( id, next ) => void.
 * @param {Function} props.onStep     ( index, patch ) => void.
 * @param {Function} props.onClose    Close (narrow screens).
 * @return {Element} Inspector.
 */
export default function Inspector( { definition, selection, onField, onRename, onStep, onClose } ) {
	const [ tab, setTab ] = useState( 'general' );
	useEffect( () => setTab( 'general' ), [ selection && selection.id, selection && selection.step ] );

	if ( ! selection ) {
		return (
			<div className="dev3fb-inspector is-empty">
				<p className="dev3fb-muted">{ __( 'Select a field to edit its settings, or add one from the list.', 'dev3-ai-form-builder' ) }</p>
			</div>
		);
	}

	if ( selection.kind === 'step' ) {
		const step = definition.steps[ selection.step ];
		if ( ! step ) {
			return null;
		}
		return (
			<div className="dev3fb-inspector">
				<div className="dev3fb-inspector__head">
					<h3>{ sprintf( /* translators: %d: step number */ __( 'Step %d', 'dev3-ai-form-builder' ), selection.step + 1 ) }</h3>
					{ onClose && <Button size="sm" variant="ghost" onClick={ onClose }>{ __( 'Done', 'dev3-ai-form-builder' ) }</Button> }
				</div>
				<Stack gap={ 16 }>
					<TextField label={ __( 'Step title', 'dev3-ai-form-builder' ) } value={ step.title } onChange={ ( v ) => onStep( selection.step, { title: v } ) } maxLength={ 120 } />
					<Textarea label={ __( 'Description', 'dev3-ai-form-builder' ) } rows={ 2 } value={ step.description || '' } onChange={ ( v ) => onStep( selection.step, { description: v } ) } maxLength={ 300 } />
					<LogicEditor logic={ step.logic } fields={ fieldsBeforeStep( definition, selection.step ) } subject={ __( 'this step', 'dev3-ai-form-builder' ) } onChange={ ( logic ) => onStep( selection.step, { logic } ) } />
				</Stack>
			</div>
		);
	}

	const field = allFields( definition ).find( ( f ) => f.id === selection.id );
	if ( ! field ) {
		return null;
	}
	const set = ( patch ) => onField( field.id, patch );
	const t = field.type;
	const input = isInput( t ) && t !== 'divider';
	const tabs = [
		{ id: 'general', label: __( 'General', 'dev3-ai-form-builder' ) },
		...( input ? [ { id: 'advanced', label: __( 'Advanced', 'dev3-ai-form-builder' ) } ] : [] ),
		{ id: 'logic', label: __( 'Logic', 'dev3-ai-form-builder' ), badge: field.logic ? '1' : undefined },
	];
	const products = allFields( definition ).filter( ( f ) => f.type === 'product' );

	return (
		<div className="dev3fb-inspector">
			<div className="dev3fb-inspector__head">
				<div>
					<h3>{ field.label || typeLabel( t ) }</h3>
					<Badge tone="brand">{ typeLabel( t ) }</Badge>
				</div>
				{ onClose && <Button size="sm" variant="ghost" onClick={ onClose }>{ __( 'Done', 'dev3-ai-form-builder' ) }</Button> }
			</div>
			<Tabs tabs={ tabs } value={ tab } onChange={ setTab } variant="pills" label={ __( 'Field settings', 'dev3-ai-form-builder' ) } />
			<div className="dev3fb-inspector__body">
				{ tab === 'general' && (
					<Stack gap={ 14 }>
						{ t !== 'divider' && t !== 'paragraph' && (
							<TextField label={ t === 'heading' ? __( 'Heading text', 'dev3-ai-form-builder' ) : __( 'Label', 'dev3-ai-form-builder' ) } value={ field.label } onChange={ ( v ) => set( { label: v } ) } maxLength={ 200 } />
						) }
						{ t === 'heading' && (
							<Select label={ __( 'Size', 'dev3-ai-form-builder' ) } value={ String( field.level || 3 ) } onChange={ ( v ) => set( { level: Number( v ) } ) } options={ [ { value: '2', label: 'H2' }, { value: '3', label: 'H3' }, { value: '4', label: 'H4' } ] } />
						) }
						{ ( t === 'paragraph' || t === 'consent' ) && (
							<Textarea label={ t === 'consent' ? __( 'Consent text (links allowed)', 'dev3-ai-form-builder' ) : __( 'Content (basic HTML allowed)', 'dev3-ai-form-builder' ) } rows={ 4 } value={ field.content || '' } onChange={ ( v ) => set( { content: v } ) } />
						) }
						{ [ 'text', 'textarea', 'email', 'phone', 'url', 'number', 'select', 'country', 'quantity', 'amount', 'password', 'date' ].includes( t ) && (
							<TextField label={ __( 'Placeholder', 'dev3-ai-form-builder' ) } value={ field.placeholder } onChange={ ( v ) => set( { placeholder: v } ) } maxLength={ 200 } />
						) }
						{ t !== 'divider' && t !== 'paragraph' && <Textarea label={ __( 'Help text', 'dev3-ai-form-builder' ) } rows={ 2 } value={ field.help } onChange={ ( v ) => set( { help: v } ) } maxLength={ 500 } /> }
						{ input && ! isComputed( t ) && t !== 'hidden' && <Toggle label={ __( 'Required', 'dev3-ai-form-builder' ) } checked={ !! field.required } onChange={ ( v ) => set( { required: v } ) } /> }
						{ hasOptions( t ) && (
							<>
								<OptionsEditor type={ t } options={ field.options || [] } onChange={ ( options ) => set( { options } ) } />
								{ [ 'radio', 'checkbox', 'product' ].includes( t ) && (
									<Select label={ __( 'Layout', 'dev3-ai-form-builder' ) } value={ field.layout || 'stacked' } onChange={ ( v ) => set( { layout: v } ) } options={ [ { value: 'stacked', label: __( 'One per line', 'dev3-ai-form-builder' ) }, { value: 'inline', label: __( 'Inline', 'dev3-ai-form-builder' ) }, { value: 'grid', label: __( 'Grid', 'dev3-ai-form-builder' ) } ] } />
								) }
							</>
						) }
						{ t === 'product' && ! ( field.options && field.options.length ) && <NumberField label={ __( 'Price', 'dev3-ai-form-builder' ) } value={ num( field.price ) } min={ 0 } step={ 0.01 } onChange={ ( v ) => set( { price: v } ) } /> }
						{ t === 'quantity' && (
							<Select label={ __( 'Quantity of product', 'dev3-ai-form-builder' ) } value={ field.product || '' } onChange={ ( v ) => set( { product: v } ) } placeholder={ __( 'Not linked', 'dev3-ai-form-builder' ) } options={ products.map( ( p ) => ( { value: p.id, label: p.label } ) ) } help={ __( 'The product price is multiplied by this quantity in the total.', 'dev3-ai-form-builder' ) } />
						) }
						{ t === 'calculation' && (
							<>
								<FormulaEditor field={ field } definition={ definition } onChange={ set } />
								<div className="dev3fb-row3">
									<NumberField label={ __( 'Decimals', 'dev3-ai-form-builder' ) } value={ num( field.decimals ) } min={ 0 } max={ 6 } onChange={ ( v ) => set( { decimals: v === '' ? 2 : v } ) } />
									<TextField label={ __( 'Prefix', 'dev3-ai-form-builder' ) } value={ field.prefix || '' } onChange={ ( v ) => set( { prefix: v } ) } maxLength={ 10 } />
									<TextField label={ __( 'Suffix', 'dev3-ai-form-builder' ) } value={ field.suffix || '' } onChange={ ( v ) => set( { suffix: v } ) } maxLength={ 10 } />
								</div>
								<Toggle label={ __( 'Add to the order total', 'dev3-ai-form-builder' ) } checked={ !! field.add_to_total } onChange={ ( v ) => set( { add_to_total: v } ) } />
								<Toggle label={ __( 'Show the result to visitors', 'dev3-ai-form-builder' ) } checked={ field.show !== false } onChange={ ( v ) => set( { show: v } ) } />
							</>
						) }
						{ t === 'total' && <p className="dev3fb-muted">{ __( 'Sum of selected products (times their quantity), custom amounts and calculations marked "add to total". This is the amount charged when payments are on.', 'dev3-ai-form-builder' ) }</p> }
					</Stack>
				) }
				{ tab === 'advanced' && (
					<Stack gap={ 14 }>
						<IdField field={ field } onRename={ ( next ) => onRename( field.id, next ) } />
						{ t !== 'hidden' && <Select label={ __( 'Width', 'dev3-ai-form-builder' ) } value={ field.width || 'full' } onChange={ ( v ) => set( { width: v } ) } options={ WIDTHS } help={ __( 'Half and third widths stack on phones.', 'dev3-ai-form-builder' ) } /> }
						{ ! isComputed( t ) && kind( t ) !== 'file' && kind( t ) !== 'signature' && (
							<TextField label={ __( 'Default value', 'dev3-ai-form-builder' ) } value={ field.default || '' } onChange={ ( v ) => set( { default: v } ) } help={ __( 'Merge tags: {user:email}, {user:display_name}, {query:utm_source}, {date}, {page_title}.', 'dev3-ai-form-builder' ) } />
						) }
						{ [ 'text', 'textarea', 'password' ].includes( t ) && <NumberField label={ __( 'Maximum characters (0 = no limit)', 'dev3-ai-form-builder' ) } value={ num( field.maxlength ) } min={ 0 } onChange={ ( v ) => set( { maxlength: v === '' ? 0 : v } ) } /> }
						{ t === 'textarea' && <NumberField label={ __( 'Rows', 'dev3-ai-form-builder' ) } value={ num( field.rows ) } min={ 2 } max={ 20 } onChange={ ( v ) => set( { rows: v } ) } /> }
						{ [ 'number', 'range', 'quantity', 'amount' ].includes( t ) && (
							<div className="dev3fb-row3">
								<NumberField label={ __( 'Min', 'dev3-ai-form-builder' ) } value={ num( field.min ) } onChange={ ( v ) => set( { min: v } ) } />
								<NumberField label={ __( 'Max', 'dev3-ai-form-builder' ) } value={ num( field.max ) } onChange={ ( v ) => set( { max: v } ) } />
								<NumberField label={ __( 'Step', 'dev3-ai-form-builder' ) } value={ num( field.step ) } step={ 0.01 } onChange={ ( v ) => set( { step: v } ) } />
							</div>
						) }
						{ [ 'number', 'range' ].includes( t ) && (
							<div className="dev3fb-row3">
								<TextField label={ __( 'Prefix', 'dev3-ai-form-builder' ) } value={ field.prefix || '' } onChange={ ( v ) => set( { prefix: v } ) } maxLength={ 10 } />
								<TextField label={ __( 'Suffix', 'dev3-ai-form-builder' ) } value={ field.suffix || '' } onChange={ ( v ) => set( { suffix: v } ) } maxLength={ 10 } />
							</div>
						) }
						{ t === 'rating' && <NumberField label={ __( 'Number of stars', 'dev3-ai-form-builder' ) } value={ num( field.max ) } min={ 3 } max={ 10 } onChange={ ( v ) => set( { max: v } ) } /> }
						{ ( t === 'scale' || t === 'nps' ) && (
							<>
								{ t === 'scale' && (
									<div className="dev3fb-row3">
										<NumberField label={ __( 'From', 'dev3-ai-form-builder' ) } value={ num( field.min ) } min={ 0 } max={ 1 } onChange={ ( v ) => set( { min: v } ) } />
										<NumberField label={ __( 'To', 'dev3-ai-form-builder' ) } value={ num( field.max ) } min={ 3 } max={ 10 } onChange={ ( v ) => set( { max: v } ) } />
									</div>
								) }
								<TextField label={ __( 'Low end label', 'dev3-ai-form-builder' ) } value={ field.min_label || '' } onChange={ ( v ) => set( { min_label: v } ) } maxLength={ 40 } />
								<TextField label={ __( 'High end label', 'dev3-ai-form-builder' ) } value={ field.max_label || '' } onChange={ ( v ) => set( { max_label: v } ) } maxLength={ 40 } />
							</>
						) }
						{ t === 'date' && (
							<div className="dev3fb-row3">
								<TextField type="date" label={ __( 'Earliest date', 'dev3-ai-form-builder' ) } value={ field.min_date || '' } onChange={ ( v ) => set( { min_date: v } ) } />
								<TextField type="date" label={ __( 'Latest date', 'dev3-ai-form-builder' ) } value={ field.max_date || '' } onChange={ ( v ) => set( { max_date: v } ) } />
							</div>
						) }
						{ t === 'file' && (
							<>
								<TextField label={ __( 'Allowed file types', 'dev3-ai-form-builder' ) } value={ field.accept || '' } onChange={ ( v ) => set( { accept: v } ) } help={ __( 'Comma-separated extensions, e.g. pdf,jpg,png. Scripts and HTML are always blocked.', 'dev3-ai-form-builder' ) } />
								<div className="dev3fb-row3">
									<NumberField label={ __( 'Max size (MB)', 'dev3-ai-form-builder' ) } value={ num( field.max_size ) } min={ 0.1 } step={ 0.5 } onChange={ ( v ) => set( { max_size: v } ) } />
									<NumberField label={ __( 'Max files', 'dev3-ai-form-builder' ) } value={ num( field.max_files ) } min={ 1 } max={ 20 } onChange={ ( v ) => set( { max_files: v } ) } />
								</div>
							</>
						) }
						{ t === 'toggle' && (
							<div className="dev3fb-row3">
								<TextField label={ __( 'On label', 'dev3-ai-form-builder' ) } value={ field.on_label || '' } onChange={ ( v ) => set( { on_label: v } ) } />
								<TextField label={ __( 'Off label', 'dev3-ai-form-builder' ) } value={ field.off_label || '' } onChange={ ( v ) => set( { off_label: v } ) } />
							</div>
						) }
						{ [ 'text', 'textarea' ].includes( t ) && <Select label={ __( 'Autofill role', 'dev3-ai-form-builder' ) } value={ field.role || '' } onChange={ ( v ) => set( { role: v } ) } options={ ROLES } help={ __( 'Helps paste-to-fill and browser autofill put the right data here.', 'dev3-ai-form-builder' ) } /> }
						<Checkbox label={ __( 'Hide the label visually (still read by screen readers)', 'dev3-ai-form-builder' ) } checked={ !! field.hide_label } onChange={ ( v ) => set( { hide_label: v } ) } />
						<TextField label={ __( 'CSS class', 'dev3-ai-form-builder' ) } value={ field.css || '' } onChange={ ( v ) => set( { css: v } ) } />
					</Stack>
				) }
				{ tab === 'logic' && <LogicEditor logic={ field.logic } fields={ earlierFields( definition, field.id ) } onChange={ ( logic ) => set( { logic } ) } /> }
			</div>
		</div>
	);
}
