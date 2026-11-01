/**
 * Conditional logic editor: show/hide this when all/any of the rules match.
 */
import { __ } from '@wordpress/i18n';
import { Button, IconButton, Select, TextField, Toggle } from '../../core3/ui';

export const OPERATORS = [
	{ value: 'is', label: __( 'is', 'dev3-ai-form-builder' ) },
	{ value: 'is_not', label: __( 'is not', 'dev3-ai-form-builder' ) },
	{ value: 'contains', label: __( 'contains', 'dev3-ai-form-builder' ) },
	{ value: 'not_contains', label: __( 'does not contain', 'dev3-ai-form-builder' ) },
	{ value: 'starts_with', label: __( 'starts with', 'dev3-ai-form-builder' ) },
	{ value: 'ends_with', label: __( 'ends with', 'dev3-ai-form-builder' ) },
	{ value: 'gt', label: __( 'is greater than', 'dev3-ai-form-builder' ) },
	{ value: 'lt', label: __( 'is less than', 'dev3-ai-form-builder' ) },
	{ value: 'gte', label: __( 'is at least', 'dev3-ai-form-builder' ) },
	{ value: 'lte', label: __( 'is at most', 'dev3-ai-form-builder' ) },
	{ value: 'empty', label: __( 'is empty', 'dev3-ai-form-builder' ) },
	{ value: 'not_empty', label: __( 'is filled in', 'dev3-ai-form-builder' ) },
];

const NO_VALUE = [ 'empty', 'not_empty' ];

/**
 * @param {Object}   props          Props.
 * @param {Object}   props.logic    Logic or null.
 * @param {Array}    props.fields   Fields that may be referenced.
 * @param {Function} props.onChange ( logic|null ) => void.
 * @param {string}   props.subject  What is shown/hidden ("this field", "this step", "this email").
 * @return {Element} Editor.
 */
export default function LogicEditor( { logic, fields, onChange, subject = __( 'this field', 'dev3-ai-form-builder' ) } ) {
	const on = !! ( logic && logic.enabled !== false && logic.rules && logic.rules.length );
	const first = fields[ 0 ];
	const enable = ( value ) => {
		if ( ! value ) {
			onChange( null );
			return;
		}
		onChange( { enabled: true, action: 'show', match: 'all', rules: [ { field: first ? first.id : '', op: 'is', value: '' } ] } );
	};
	if ( ! fields.length ) {
		return <p className="dev3fb-muted">{ __( 'Add fields above this one to use conditional logic.', 'dev3-ai-form-builder' ) }</p>;
	}
	const set = ( patch ) => onChange( { ...logic, ...patch } );
	const setRule = ( i, patch ) => set( { rules: logic.rules.map( ( r, n ) => ( n === i ? { ...r, ...patch } : r ) ) } );
	const optionsFor = ( id ) => {
		const f = fields.find( ( x ) => x.id === id );
		if ( ! f ) {
			return null;
		}
		if ( f.options && f.options.length ) {
			return f.options.map( ( o ) => ( { value: o.value, label: o.label } ) );
		}
		if ( f.type === 'toggle' || f.type === 'consent' ) {
			return [
				{ value: '1', label: __( 'Yes / checked', 'dev3-ai-form-builder' ) },
				{ value: '', label: __( 'No / unchecked', 'dev3-ai-form-builder' ) },
			];
		}
		return null;
	};
	return (
		<div className="dev3fb-logic">
			<Toggle label={ __( 'Use conditional logic', 'dev3-ai-form-builder' ) } checked={ on } onChange={ enable } />
			{ on && (
				<>
					<div className="dev3fb-logic__head">
						<Select label={ __( 'Action', 'dev3-ai-form-builder' ) } hideLabel value={ logic.action } onChange={ ( v ) => set( { action: v } ) } options={ [ { value: 'show', label: __( 'Show', 'dev3-ai-form-builder' ) }, { value: 'hide', label: __( 'Hide', 'dev3-ai-form-builder' ) } ] } />
						<span>{ subject } { __( 'when', 'dev3-ai-form-builder' ) }</span>
						<Select label={ __( 'Match', 'dev3-ai-form-builder' ) } hideLabel value={ logic.match } onChange={ ( v ) => set( { match: v } ) } options={ [ { value: 'all', label: __( 'all', 'dev3-ai-form-builder' ) }, { value: 'any', label: __( 'any', 'dev3-ai-form-builder' ) } ] } />
						<span>{ __( 'of these match:', 'dev3-ai-form-builder' ) }</span>
					</div>
					<ol className="dev3fb-logic__rules">
						{ logic.rules.map( ( rule, i ) => {
							const opts = optionsFor( rule.field );
							return (
								<li key={ i } className="dev3fb-logic__rule">
									<Select label={ __( 'Field', 'dev3-ai-form-builder' ) } hideLabel value={ rule.field } onChange={ ( v ) => setRule( i, { field: v, value: '' } ) } options={ fields.map( ( f ) => ( { value: f.id, label: f.label || f.id } ) ) } />
									<Select label={ __( 'Operator', 'dev3-ai-form-builder' ) } hideLabel value={ rule.op } onChange={ ( v ) => setRule( i, { op: v } ) } options={ OPERATORS } />
									{ ! NO_VALUE.includes( rule.op ) &&
										( opts && [ 'is', 'is_not' ].includes( rule.op ) ? (
											<Select label={ __( 'Value', 'dev3-ai-form-builder' ) } hideLabel value={ rule.value } onChange={ ( v ) => setRule( i, { value: v } ) } options={ opts } placeholder={ __( 'Choose…', 'dev3-ai-form-builder' ) } />
										) : (
											<TextField label={ __( 'Value', 'dev3-ai-form-builder' ) } hideLabel value={ rule.value } onChange={ ( v ) => setRule( i, { value: v } ) } placeholder={ __( 'Value', 'dev3-ai-form-builder' ) } />
										) ) }
									<IconButton icon="x" size="sm" label={ __( 'Remove rule', 'dev3-ai-form-builder' ) } onClick={ () => ( logic.rules.length === 1 ? onChange( null ) : set( { rules: logic.rules.filter( ( r, n ) => n !== i ) } ) ) } />
								</li>
							);
						} ) }
					</ol>
					<Button size="sm" variant="ghost" icon="plus" onClick={ () => set( { rules: [ ...logic.rules, { field: first.id, op: 'is', value: '' } ] } ) }>
						{ __( 'Add rule', 'dev3-ai-form-builder' ) }
					</Button>
				</>
			) }
		</div>
	);
}
