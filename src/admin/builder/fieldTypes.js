/**
 * Field type registry shared with PHP (includes/Forms/field-types.json) plus builder defaults.
 */
import { __ } from '@wordpress/i18n';
import types from '../../../includes/Forms/field-types.json';

export const TYPES = types;

export const GROUPS = [
	{ id: 'basic', label: __( 'Basic', 'dev3-ai-form-builder' ) },
	{ id: 'choice', label: __( 'Choices', 'dev3-ai-form-builder' ) },
	{ id: 'contact', label: __( 'Contact', 'dev3-ai-form-builder' ) },
	{ id: 'advanced', label: __( 'Advanced', 'dev3-ai-form-builder' ) },
	{ id: 'payment', label: __( 'Payments & calculations', 'dev3-ai-form-builder' ) },
	{ id: 'layout', label: __( 'Layout', 'dev3-ai-form-builder' ) },
];

export const LABELS = {
	text: __( 'Single line text', 'dev3-ai-form-builder' ),
	textarea: __( 'Paragraph text', 'dev3-ai-form-builder' ),
	email: __( 'Email', 'dev3-ai-form-builder' ),
	number: __( 'Number', 'dev3-ai-form-builder' ),
	phone: __( 'Phone', 'dev3-ai-form-builder' ),
	url: __( 'Website / URL', 'dev3-ai-form-builder' ),
	select: __( 'Dropdown', 'dev3-ai-form-builder' ),
	multiselect: __( 'Multi-select', 'dev3-ai-form-builder' ),
	radio: __( 'Multiple choice', 'dev3-ai-form-builder' ),
	checkbox: __( 'Checkboxes', 'dev3-ai-form-builder' ),
	image_choice: __( 'Image choice', 'dev3-ai-form-builder' ),
	country: __( 'Country', 'dev3-ai-form-builder' ),
	toggle: __( 'Yes / No toggle', 'dev3-ai-form-builder' ),
	name: __( 'Name', 'dev3-ai-form-builder' ),
	address: __( 'Address', 'dev3-ai-form-builder' ),
	date: __( 'Date', 'dev3-ai-form-builder' ),
	time: __( 'Time', 'dev3-ai-form-builder' ),
	file: __( 'File upload', 'dev3-ai-form-builder' ),
	signature: __( 'Signature', 'dev3-ai-form-builder' ),
	rating: __( 'Star rating', 'dev3-ai-form-builder' ),
	nps: __( 'Net Promoter Score', 'dev3-ai-form-builder' ),
	scale: __( 'Opinion scale', 'dev3-ai-form-builder' ),
	range: __( 'Slider', 'dev3-ai-form-builder' ),
	color: __( 'Colour picker', 'dev3-ai-form-builder' ),
	password: __( 'Password', 'dev3-ai-form-builder' ),
	hidden: __( 'Hidden field', 'dev3-ai-form-builder' ),
	consent: __( 'Consent / GDPR', 'dev3-ai-form-builder' ),
	calculation: __( 'Calculation', 'dev3-ai-form-builder' ),
	product: __( 'Product', 'dev3-ai-form-builder' ),
	quantity: __( 'Quantity', 'dev3-ai-form-builder' ),
	amount: __( 'Custom amount', 'dev3-ai-form-builder' ),
	total: __( 'Order total', 'dev3-ai-form-builder' ),
	heading: __( 'Heading', 'dev3-ai-form-builder' ),
	paragraph: __( 'Text block', 'dev3-ai-form-builder' ),
	divider: __( 'Divider', 'dev3-ai-form-builder' ),
};

export const typeLabel = ( type ) => LABELS[ type ] || ( TYPES[ type ] && TYPES[ type ].label ) || type;
export const typeIcon = ( type ) => ( TYPES[ type ] && TYPES[ type ].icon ) || 'edit';
export const kind = ( type ) => ( TYPES[ type ] && TYPES[ type ].kind ) || 'string';
export const hasOptions = ( type ) => !! ( TYPES[ type ] && TYPES[ type ].options ) || type === 'product';
export const isInput = ( type ) => kind( type ) !== 'content';
export const isComputed = ( type ) => kind( type ) === 'computed';

const opt = ( label, extra = {} ) => ( { label, value: label, ...extra } );

/**
 * A new field of a type with sensible defaults.
 *
 * @param {string} type Type.
 * @param {string} id   Unique id.
 * @return {Object} Field.
 */
export function newField( type, id ) {
	const base = {
		id,
		type,
		label: typeLabel( type ),
		placeholder: '',
		help: '',
		required: false,
		width: 'full',
		hide_label: false,
		default: '',
		css: '',
		role: '',
		logic: null,
	};
	switch ( type ) {
		case 'select':
		case 'multiselect':
		case 'radio':
		case 'checkbox':
		case 'image_choice':
			return { ...base, options: [ opt( __( 'First choice', 'dev3-ai-form-builder' ) ), opt( __( 'Second choice', 'dev3-ai-form-builder' ) ), opt( __( 'Third choice', 'dev3-ai-form-builder' ) ) ], layout: type === 'image_choice' ? 'grid' : 'stacked' };
		case 'product':
			return { ...base, options: [ opt( __( 'Basic', 'dev3-ai-form-builder' ), { price: 19 } ), opt( __( 'Pro', 'dev3-ai-form-builder' ), { price: 49 } ) ], price: 0, layout: 'stacked' };
		case 'country':
			return { ...base, options: [], layout: 'stacked' };
		case 'textarea':
			return { ...base, rows: 4, maxlength: 0 };
		case 'text':
		case 'password':
			return { ...base, maxlength: 0 };
		case 'number':
		case 'quantity':
		case 'amount':
			return { ...base, min: type === 'number' ? '' : 0, max: '', step: type === 'amount' ? 0.01 : 1, prefix: '', suffix: '', ...( type === 'quantity' ? { product: '', default: '1' } : {} ) };
		case 'range':
			return { ...base, min: 0, max: 100, step: 1, prefix: '', suffix: '' };
		case 'rating':
			return { ...base, max: 5 };
		case 'scale':
			return { ...base, min: 1, max: 5, min_label: __( 'Disagree', 'dev3-ai-form-builder' ), max_label: __( 'Agree', 'dev3-ai-form-builder' ) };
		case 'nps':
			return { ...base, label: __( 'How likely are you to recommend us?', 'dev3-ai-form-builder' ), min_label: __( 'Not likely', 'dev3-ai-form-builder' ), max_label: __( 'Very likely', 'dev3-ai-form-builder' ) };
		case 'date':
			return { ...base, min_date: '', max_date: '' };
		case 'file':
			return { ...base, accept: 'pdf,jpg,jpeg,png,doc,docx', max_size: 5, max_files: 1 };
		case 'calculation':
			return { ...base, label: __( 'Estimated price', 'dev3-ai-form-builder' ), formula: '', decimals: 2, prefix: '', suffix: '', add_to_total: false, show: true };
		case 'total':
			return { ...base, price: 0 };
		case 'heading':
			return { ...base, label: __( 'Section title', 'dev3-ai-form-builder' ), level: 3 };
		case 'paragraph':
			return { ...base, label: '', content: __( 'Add some helpful text for your visitors here.', 'dev3-ai-form-builder' ) };
		case 'divider':
			return { ...base, label: '' };
		case 'consent':
			return { ...base, label: __( 'Consent', 'dev3-ai-form-builder' ), content: __( 'I agree to the privacy policy.', 'dev3-ai-form-builder' ), required: true };
		case 'toggle':
			return { ...base, on_label: __( 'Yes', 'dev3-ai-form-builder' ), off_label: __( 'No', 'dev3-ai-form-builder' ) };
		case 'name':
			return { ...base, label: __( 'Your name', 'dev3-ai-form-builder' ) };
	}
	return base;
}
