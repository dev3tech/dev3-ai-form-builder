/**
 * Choice options editor: label, value, price (products/calculations) and image (image choice).
 * Includes bulk paste ("one option per line").
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, IconButton, Textarea, Toggle } from '../../core3/ui';

/**
 * @param {Object}   props          Props.
 * @param {Array}    props.options  Options.
 * @param {string}   props.type     Field type.
 * @param {Function} props.onChange ( options ) => void.
 * @return {Element} Editor.
 */
export default function OptionsEditor( { options, type, onChange } ) {
	const [ bulk, setBulk ] = useState( null );
	const [ values, setValues ] = useState( () => options.some( ( o ) => o.value !== o.label ) );
	const priced = type === 'product' || options.some( ( o ) => o.price !== undefined && o.price !== '' );
	const [ prices, setPrices ] = useState( priced );
	const images = type === 'image_choice';

	const set = ( i, patch ) => {
		onChange(
			options.map( ( o, n ) => {
				if ( n !== i ) {
					return o;
				}
				const next = { ...o, ...patch };
				if ( patch.label !== undefined && ! values ) {
					next.value = patch.label;
				}
				return next;
			} )
		);
	};
	const move = ( i, dir ) => {
		const to = i + dir;
		if ( to < 0 || to >= options.length ) {
			return;
		}
		const next = [ ...options ];
		[ next[ i ], next[ to ] ] = [ next[ to ], next[ i ] ];
		onChange( next );
	};
	const pick = ( i ) => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( { title: __( 'Choose image', 'dev3-ai-form-builder' ), library: { type: 'image' }, multiple: false } );
		frame.on( 'select', () => {
			const att = frame.state().get( 'selection' ).first().toJSON();
			set( i, { image: ( att.sizes && att.sizes.medium && att.sizes.medium.url ) || att.url } );
		} );
		frame.open();
	};

	if ( bulk !== null ) {
		return (
			<div className="dev3fb-options">
				<Textarea label={ __( 'One option per line', 'dev3-ai-form-builder' ) } help={ __( 'Use "Label | value | price" to set a value or price.', 'dev3-ai-form-builder' ) } rows={ 8 } value={ bulk } onChange={ setBulk } />
				<div className="dev3fb-inline">
					<Button
						variant="primary"
						size="sm"
						onClick={ () => {
							const next = bulk
								.split( '\n' )
								.map( ( line ) => line.trim() )
								.filter( Boolean )
								.map( ( line ) => {
									const [ label, value, price ] = line.split( '|' ).map( ( p ) => p.trim() );
									const o = { label, value: value || label };
									if ( price !== undefined && price !== '' && ! isNaN( Number( price ) ) ) {
										o.price = Number( price );
									}
									return o;
								} );
							onChange( next );
							setBulk( null );
						} }
					>
						{ __( 'Apply', 'dev3-ai-form-builder' ) }
					</Button>
					<Button size="sm" variant="ghost" onClick={ () => setBulk( null ) }>
						{ __( 'Cancel', 'dev3-ai-form-builder' ) }
					</Button>
				</div>
			</div>
		);
	}

	return (
		<div className="dev3fb-options">
			<div className="dev3fb-options__toggles">
				<Toggle label={ __( 'Separate values', 'dev3-ai-form-builder' ) } checked={ values } onChange={ setValues } />
				{ type !== 'product' && <Toggle label={ __( 'Prices', 'dev3-ai-form-builder' ) } checked={ prices } onChange={ setPrices } /> }
			</div>
			<ol className="dev3fb-options__list">
				{ options.map( ( o, i ) => (
					<li key={ i } className="dev3fb-options__row">
						{ images && (
							<button type="button" className="dev3fb-options__img" onClick={ () => pick( i ) } aria-label={ sprintf( /* translators: %s: option label */ __( 'Choose image for %s', 'dev3-ai-form-builder' ), o.label ) }>
								{ o.image ? <img src={ o.image } alt="" /> : <span aria-hidden="true">+</span> }
							</button>
						) }
						<input
							className="dev3fb-ui-input"
							value={ o.label }
							onChange={ ( e ) => set( i, { label: e.target.value } ) }
							aria-label={ sprintf( /* translators: %d: option number */ __( 'Option %d label', 'dev3-ai-form-builder' ), i + 1 ) }
						/>
						{ values && (
							<input
								className="dev3fb-ui-input dev3fb-options__value"
								value={ o.value }
								onChange={ ( e ) => set( i, { value: e.target.value } ) }
								aria-label={ sprintf( /* translators: %d: option number */ __( 'Option %d value', 'dev3-ai-form-builder' ), i + 1 ) }
								placeholder={ __( 'value', 'dev3-ai-form-builder' ) }
							/>
						) }
						{ ( prices || type === 'product' ) && (
							<input
								className="dev3fb-ui-input dev3fb-options__price"
								type="number"
								step="0.01"
								min="0"
								value={ o.price === undefined ? '' : o.price }
								onChange={ ( e ) => set( i, { price: e.target.value === '' ? undefined : Number( e.target.value ) } ) }
								aria-label={ sprintf( /* translators: %d: option number */ __( 'Option %d price', 'dev3-ai-form-builder' ), i + 1 ) }
								placeholder="0.00"
							/>
						) }
						<span className="dev3fb-options__tools">
							<IconButton icon="chevronLeft" size="sm" className="dev3fb-rot90" label={ __( 'Move up', 'dev3-ai-form-builder' ) } disabled={ i === 0 } onClick={ () => move( i, -1 ) } />
							<IconButton icon="x" size="sm" label={ __( 'Remove option', 'dev3-ai-form-builder' ) } disabled={ options.length <= 1 } onClick={ () => onChange( options.filter( ( x, n ) => n !== i ) ) } />
						</span>
					</li>
				) ) }
			</ol>
			<div className="dev3fb-inline">
				<Button size="sm" icon="plus" onClick={ () => onChange( [ ...options, { label: sprintf( /* translators: %d: number */ __( 'Option %d', 'dev3-ai-form-builder' ), options.length + 1 ), value: sprintf( /* translators: %d: number */ __( 'Option %d', 'dev3-ai-form-builder' ), options.length + 1 ) } ] ) }>
					{ __( 'Add option', 'dev3-ai-form-builder' ) }
				</Button>
				<Button size="sm" variant="ghost" icon="list" onClick={ () => setBulk( options.map( ( o ) => [ o.label, o.value !== o.label ? o.value : '', o.price !== undefined ? o.price : '' ].join( ' | ' ).replace( /( \| )+$/, '' ) ).join( '\n' ) ) }>
					{ __( 'Bulk edit', 'dev3-ai-form-builder' ) }
				</Button>
			</div>
		</div>
	);
}
