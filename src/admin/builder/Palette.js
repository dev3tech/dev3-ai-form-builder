/**
 * Field palette: grouped, searchable; click (or Enter) to add, or drag onto the canvas.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Icon } from '../../core3/ui';
import { GROUPS, TYPES, typeIcon, typeLabel } from './fieldTypes';

export const DRAG_TYPE = 'application/x-dev3fb-type';

/**
 * @param {Object}   props       Props.
 * @param {Function} props.onAdd ( type ) => void.
 * @return {Element} Palette.
 */
export default function Palette( { onAdd } ) {
	const [ query, setQuery ] = useState( '' );
	const q = query.trim().toLowerCase();
	const matches = ( type ) => ! q || typeLabel( type ).toLowerCase().includes( q ) || type.includes( q );
	return (
		<div className="dev3fb-palette">
			<label className="dev3fb-ui-sr-only" htmlFor="dev3fb-palette-search">
				{ __( 'Search fields', 'dev3-ai-form-builder' ) }
			</label>
			<input
				id="dev3fb-palette-search"
				className="dev3fb-ui-input dev3fb-palette__search"
				type="search"
				placeholder={ __( 'Search 35 field types…', 'dev3-ai-form-builder' ) }
				value={ query }
				onChange={ ( e ) => setQuery( e.target.value ) }
			/>
			{ GROUPS.map( ( group ) => {
				const types = Object.keys( TYPES ).filter( ( t ) => TYPES[ t ].group === group.id && matches( t ) );
				if ( ! types.length ) {
					return null;
				}
				return (
					<div key={ group.id } className="dev3fb-palette__group">
						<h3 className="dev3fb-palette__title">{ group.label }</h3>
						<div className="dev3fb-palette__grid">
							{ types.map( ( type ) => (
								<button
									key={ type }
									type="button"
									className="dev3fb-palette__item"
									draggable
									onDragStart={ ( e ) => {
										e.dataTransfer.setData( DRAG_TYPE, type );
										e.dataTransfer.setData( 'text/plain', type );
										e.dataTransfer.effectAllowed = 'copy';
									} }
									onClick={ () => onAdd( type ) }
									title={ __( 'Click to add, or drag onto the form', 'dev3-ai-form-builder' ) }
								>
									<Icon name={ typeIcon( type ) } size={ 16 } />
									<span>{ typeLabel( type ) }</span>
								</button>
							) ) }
						</div>
					</div>
				);
			} ) }
		</div>
	);
}
