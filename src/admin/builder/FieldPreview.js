/**
 * Static, non-interactive preview of a field inside the builder canvas.
 */
import { __ } from '@wordpress/i18n';

const Box = ( { children, tall } ) => <span className={ `dev3fb-pv-input${ tall ? ' is-tall' : '' }` }>{ children }</span>;
const Ph = ( { text } ) => <span className="dev3fb-pv-ph">{ text }</span>;

const money = ( n, symbol = '$' ) => `${ symbol }${ Number( n || 0 ).toFixed( 2 ) }`;

/**
 * @param {Object} props        Props.
 * @param {Object} props.field  Field.
 * @param {string} props.symbol Currency symbol.
 * @return {Element} Preview.
 */
export default function FieldPreview( { field, symbol = '$' } ) {
	const f = field;
	const ph = f.placeholder || '';
	const label = (
		<span className="dev3fb-pv-label">
			{ f.label || <em>{ __( '(no label)', 'dev3-ai-form-builder' ) }</em> }
			{ f.required && <span className="dev3fb-pv-req" aria-hidden="true">*</span> }
		</span>
	);
	const help = f.help ? <span className="dev3fb-pv-help">{ f.help }</span> : null;
	let control = null;
	switch ( f.type ) {
		case 'heading':
			return <span className={ `dev3fb-pv-heading is-h${ f.level || 3 }` }>{ f.label || __( 'Heading', 'dev3-ai-form-builder' ) }</span>;
		case 'paragraph':
			return <span className="dev3fb-pv-paragraph">{ String( f.content || '' ).replace( /<[^>]+>/g, '' ) || __( 'Text block', 'dev3-ai-form-builder' ) }</span>;
		case 'divider':
			return <span className="dev3fb-pv-divider" />;
		case 'hidden':
			return (
				<span className="dev3fb-pv-hidden">
					{ __( 'Hidden field', 'dev3-ai-form-builder' ) } · <code>{ f.id }</code> { f.default ? `= ${ f.default }` : '' }
				</span>
			);
		case 'textarea':
			control = <Box tall><Ph text={ ph } /></Box>;
			break;
		case 'select':
		case 'country':
		case 'multiselect':
			control = (
				<Box>
					<Ph text={ ph || ( f.type === 'country' ? __( 'Choose a country…', 'dev3-ai-form-builder' ) : __( 'Choose…', 'dev3-ai-form-builder' ) ) } />
					<span className="dev3fb-pv-caret" aria-hidden="true" />
				</Box>
			);
			break;
		case 'radio':
		case 'checkbox':
		case 'product':
			if ( f.type === 'product' && ! ( f.options && f.options.length ) ) {
				control = <span className="dev3fb-pv-price">{ money( f.price, symbol ) }</span>;
				break;
			}
			control = (
				<span className={ `dev3fb-pv-choices is-${ f.layout || 'stacked' }` }>
					{ ( f.options || [] ).slice( 0, 6 ).map( ( o, i ) => (
						<span key={ i } className="dev3fb-pv-choice">
							<span className={ `dev3fb-pv-mark is-${ f.type === 'checkbox' ? 'box' : 'circle' }` } />
							{ o.label }
							{ o.price > 0 && <span className="dev3fb-pv-price">{ money( o.price, symbol ) }</span> }
						</span>
					) ) }
					{ ( f.options || [] ).length > 6 && <span className="dev3fb-pv-more">+{ f.options.length - 6 }</span> }
				</span>
			);
			break;
		case 'image_choice':
			control = (
				<span className="dev3fb-pv-images">
					{ ( f.options || [] ).slice( 0, 4 ).map( ( o, i ) => (
						<span key={ i } className="dev3fb-pv-image">
							{ o.image ? <img src={ o.image } alt="" /> : <span className="dev3fb-pv-tile">{ String( o.label || '?' ).slice( 0, 1 ).toUpperCase() }</span> }
							<span>{ o.label }</span>
						</span>
					) ) }
				</span>
			);
			break;
		case 'name':
			control = (
				<span className="dev3fb-pv-split">
					<Box><Ph text={ __( 'First name', 'dev3-ai-form-builder' ) } /></Box>
					<Box><Ph text={ __( 'Last name', 'dev3-ai-form-builder' ) } /></Box>
				</span>
			);
			break;
		case 'address':
			control = (
				<span className="dev3fb-pv-stack">
					<Box><Ph text={ __( 'Street address', 'dev3-ai-form-builder' ) } /></Box>
					<span className="dev3fb-pv-split">
						<Box><Ph text={ __( 'City', 'dev3-ai-form-builder' ) } /></Box>
						<Box><Ph text={ __( 'Postal code', 'dev3-ai-form-builder' ) } /></Box>
					</span>
				</span>
			);
			break;
		case 'file':
			control = (
				<span className="dev3fb-pv-file">
					{ __( 'Choose file', 'dev3-ai-form-builder' ) } · { String( f.accept || '' ).toUpperCase().replace( /,/g, ', ' ) } · { __( 'max', 'dev3-ai-form-builder' ) } { f.max_size } MB
				</span>
			);
			break;
		case 'signature':
			control = <span className="dev3fb-pv-signature">{ __( 'Sign here', 'dev3-ai-form-builder' ) }</span>;
			break;
		case 'rating':
			control = <span className="dev3fb-pv-stars" aria-hidden="true">{ '★'.repeat( f.max || 5 ) }</span>;
			break;
		case 'nps':
		case 'scale': {
			const min = f.type === 'nps' ? 0 : Number( f.min || 1 );
			const max = f.type === 'nps' ? 10 : Number( f.max || 5 );
			const cells = [];
			for ( let i = min; i <= max; i++ ) {
				cells.push( <span key={ i } className="dev3fb-pv-cell">{ i }</span> );
			}
			control = <span className="dev3fb-pv-scale">{ cells }</span>;
			break;
		}
		case 'range':
			control = (
				<span className="dev3fb-pv-range">
					<span className="dev3fb-pv-track"><span /></span>
					<span>{ f.min }–{ f.max }</span>
				</span>
			);
			break;
		case 'toggle':
			return (
				<span className="dev3fb-pv-toggle">
					<span className="dev3fb-pv-switch" />
					{ label }
				</span>
			);
		case 'consent':
			return (
				<span className="dev3fb-pv-consent">
					<span className="dev3fb-pv-mark is-box" />
					<span>
						{ String( f.content || f.label || '' ).replace( /<[^>]+>/g, '' ) }
						{ f.required && <span className="dev3fb-pv-req" aria-hidden="true">*</span> }
					</span>
				</span>
			);
		case 'calculation':
			control = (
				<span className="dev3fb-pv-output">
					{ f.prefix }0{ f.suffix } <code>{ f.formula || __( 'no formula yet', 'dev3-ai-form-builder' ) }</code>
				</span>
			);
			break;
		case 'total':
			control = <span className="dev3fb-pv-output is-total">{ money( 0, symbol ) }</span>;
			break;
		case 'color':
			control = <span className="dev3fb-pv-color" />;
			break;
		default:
			control = (
				<Box>
					{ ( f.prefix || f.type === 'amount' ) && <span className="dev3fb-pv-affix">{ f.type === 'amount' ? symbol : f.prefix }</span> }
					<Ph text={ ph || ( f.type === 'date' ? 'yyyy-mm-dd' : f.type === 'time' ? '--:--' : '' ) } />
				</Box>
			);
	}
	return (
		<span className="dev3fb-pv">
			{ ! f.hide_label && label }
			{ help }
			{ control }
		</span>
	);
}
