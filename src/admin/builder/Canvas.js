/**
 * Builder canvas: steps with field cards. Drag and drop (palette → canvas, reorder, between
 * steps) plus keyboard/touch alternatives (move up/down buttons) on every card.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Badge, Button, EmptyState, IconButton } from '../../core3/ui';
import FieldPreview from './FieldPreview';
import { DRAG_TYPE } from './Palette';
import { typeLabel } from './fieldTypes';

const FIELD_DRAG = 'application/x-dev3fb-field';

/**
 * @param {Object}   props            Props.
 * @param {Object}   props.definition Definition.
 * @param {Object}   props.selection  { kind: 'field'|'step', id, step }.
 * @param {Function} props.onSelect   ( selection ) => void.
 * @param {Function} props.onDropType ( type, step, index ) => void.
 * @param {Function} props.onMove     ( id, step, index ) => void.
 * @param {Function} props.onNudge    ( id, dir ) => void.
 * @param {Function} props.onDuplicate ( id ) => void.
 * @param {Function} props.onRemove   ( id ) => void.
 * @param {Function} props.onAddStep  () => void.
 * @param {Function} props.onStep     ( action, index ) => void.
 * @param {Function} props.onAddField ( step ) => void.
 * @param {string}   props.symbol     Currency symbol.
 * @return {Element} Canvas.
 */
export default function Canvas( { definition, selection, onSelect, onDropType, onMove, onNudge, onDuplicate, onRemove, onAddStep, onStep, onAddField, symbol } ) {
	const [ target, setTarget ] = useState( null );
	const multi = definition.steps.length > 1;

	const over = ( e, step, index ) => {
		const types = Array.from( e.dataTransfer.types || [] );
		if ( ! types.includes( DRAG_TYPE ) && ! types.includes( FIELD_DRAG ) ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		e.dataTransfer.dropEffect = types.includes( FIELD_DRAG ) ? 'move' : 'copy';
		if ( ! target || target.step !== step || target.index !== index ) {
			setTarget( { step, index } );
		}
	};
	const overCard = ( e, step, index ) => {
		const rect = e.currentTarget.getBoundingClientRect();
		over( e, step, e.clientY < rect.top + rect.height / 2 ? index : index + 1 );
	};
	const drop = ( e ) => {
		e.preventDefault();
		e.stopPropagation();
		const t = target;
		setTarget( null );
		if ( ! t ) {
			return;
		}
		const type = e.dataTransfer.getData( DRAG_TYPE );
		const id = e.dataTransfer.getData( FIELD_DRAG );
		if ( id ) {
			onMove( id, t.step, t.index );
		} else if ( type ) {
			onDropType( type, t.step, t.index );
		}
	};
	const indicator = ( step, index ) => target && target.step === step && target.index === index && <div className="dev3fb-canvas__drop" aria-hidden="true" />;

	return (
		<div className="dev3fb-canvas" onDragLeave={ ( e ) => ! e.currentTarget.contains( e.relatedTarget ) && setTarget( null ) }>
			{ definition.steps.map( ( step, s ) => {
				const stepSelected = selection && selection.kind === 'step' && selection.step === s;
				return (
					<section key={ step.id } className={ `dev3fb-canvas__step${ stepSelected ? ' is-selected' : '' }` } aria-label={ sprintf( /* translators: %d: step number */ __( 'Step %d', 'dev3-ai-form-builder' ), s + 1 ) }>
						{ multi && (
							<header className="dev3fb-canvas__stephead">
								<button type="button" className="dev3fb-canvas__steptitle" onClick={ () => onSelect( { kind: 'step', step: s } ) }>
									<span className="dev3fb-canvas__stepnum">{ s + 1 }</span>
									<span>{ step.title || __( 'Untitled step', 'dev3-ai-form-builder' ) }</span>
									{ step.logic && <Badge tone="info">{ __( 'Logic', 'dev3-ai-form-builder' ) }</Badge> }
								</button>
								<span className="dev3fb-canvas__stepactions">
									<IconButton icon="chevronLeft" size="sm" label={ __( 'Move step up', 'dev3-ai-form-builder' ) } disabled={ s === 0 } onClick={ () => onStep( 'up', s ) } className="dev3fb-rot90" />
									<IconButton icon="chevronRight" size="sm" label={ __( 'Move step down', 'dev3-ai-form-builder' ) } disabled={ s === definition.steps.length - 1 } onClick={ () => onStep( 'down', s ) } className="dev3fb-rot90" />
									<IconButton icon="settings" size="sm" label={ __( 'Step settings', 'dev3-ai-form-builder' ) } onClick={ () => onSelect( { kind: 'step', step: s } ) } />
									<IconButton icon="trash" size="sm" label={ __( 'Remove step (fields move to the previous step)', 'dev3-ai-form-builder' ) } onClick={ () => onStep( 'remove', s ) } />
								</span>
							</header>
						) }
						<div
							className={ `dev3fb-canvas__fields${ step.fields.length ? '' : ' is-empty' }` }
							onDragOver={ ( e ) => {
								if ( e.target === e.currentTarget ) {
									over( e, s, step.fields.length );
								}
							} }
							onDrop={ drop }
						>
							{ step.fields.length === 0 && (
								<>
									{ indicator( s, 0 ) }
									<EmptyState
										compact
										icon="plus"
										title={ __( 'Drop fields here', 'dev3-ai-form-builder' ) }
										description={ __( 'Drag a field from the list, or add one.', 'dev3-ai-form-builder' ) }
										action={ <Button size="sm" icon="plus" onClick={ () => onAddField( s ) }>{ __( 'Add field', 'dev3-ai-form-builder' ) }</Button> }
									/>
								</>
							) }
							{ step.fields.map( ( field, i ) => {
								const selected = selection && selection.kind === 'field' && selection.id === field.id;
								return (
									<div key={ field.id } className={ `dev3fb-canvas__slot is-${ field.width || 'full' }` }>
										{ indicator( s, i ) }
										<div
											className={ `dev3fb-canvas__card${ selected ? ' is-selected' : '' }` }
											draggable
											onDragStart={ ( e ) => {
												e.dataTransfer.setData( FIELD_DRAG, field.id );
												e.dataTransfer.effectAllowed = 'move';
											} }
											onDragEnd={ () => setTarget( null ) }
											onDragOver={ ( e ) => overCard( e, s, i ) }
											onDrop={ drop }
										>
											<button
												type="button"
												className="dev3fb-canvas__select"
												onClick={ () => onSelect( { kind: 'field', id: field.id, step: s } ) }
												aria-pressed={ selected }
												aria-label={ sprintf( /* translators: 1: field label, 2: field type */ __( 'Edit %1$s (%2$s)', 'dev3-ai-form-builder' ), field.label || field.id, typeLabel( field.type ) ) }
											/>
											<span className="dev3fb-canvas__grip" aria-hidden="true">⋮⋮</span>
											<FieldPreview field={ field } symbol={ symbol } />
											<span className="dev3fb-canvas__meta">
												<span className="dev3fb-canvas__type">{ typeLabel( field.type ) }</span>
												{ field.logic && <Badge tone="info">{ __( 'Logic', 'dev3-ai-form-builder' ) }</Badge> }
												{ field.width !== 'full' && <Badge>{ field.width === 'half' ? '1/2' : '1/3' }</Badge> }
											</span>
											<span className="dev3fb-canvas__tools">
												<IconButton icon="chevronLeft" size="sm" className="dev3fb-rot90" label={ __( 'Move up', 'dev3-ai-form-builder' ) } onClick={ () => onNudge( field.id, -1 ) } />
												<IconButton icon="chevronRight" size="sm" className="dev3fb-rot90" label={ __( 'Move down', 'dev3-ai-form-builder' ) } onClick={ () => onNudge( field.id, 1 ) } />
												<IconButton icon="copy" size="sm" label={ __( 'Duplicate', 'dev3-ai-form-builder' ) } onClick={ () => onDuplicate( field.id ) } />
												<IconButton icon="trash" size="sm" label={ __( 'Delete', 'dev3-ai-form-builder' ) } onClick={ () => onRemove( field.id ) } />
											</span>
										</div>
										{ i === step.fields.length - 1 && indicator( s, i + 1 ) }
									</div>
								);
							} ) }
						</div>
						{ step.fields.length > 0 && (
							<div className="dev3fb-canvas__stepfoot">
								<Button size="sm" variant="ghost" icon="plus" onClick={ () => onAddField( s ) }>
									{ __( 'Add field', 'dev3-ai-form-builder' ) }
								</Button>
							</div>
						) }
					</section>
				);
			} ) }
			<div className="dev3fb-canvas__addstep">
				<Button variant="secondary" icon="plus" onClick={ onAddStep }>
					{ multi ? __( 'Add step', 'dev3-ai-form-builder' ) : __( 'Make it multi-step', 'dev3-ai-form-builder' ) }
				</Button>
			</div>
		</div>
	);
}
