/**
 * Form settings: buttons, multi-step progress, save & continue, limits/schedule, styling.
 */
import { __ } from '@wordpress/i18n';
import { Card, ColorField, Columns, FormGrid, NumberField, Select, Stack, TextField, Textarea, Toggle } from '../../core3/ui';

/**
 * @param {Object}   props            Props.
 * @param {Object}   props.definition Definition.
 * @param {Function} props.onChange   ( settingsPatch ) => void.
 * @return {Element} Panel.
 */
export default function SettingsPanel( { definition, onChange } ) {
	const s = definition.settings;
	const set = ( patch ) => onChange( patch );
	const multi = definition.steps.length > 1;
	return (
		<Columns min={ 340 }>
			<Card title={ __( 'Buttons & steps', 'dev3-ai-form-builder' ) }>
				<Stack gap={ 14 }>
					<FormGrid>
						<TextField label={ __( 'Submit button', 'dev3-ai-form-builder' ) } value={ s.submit_label } onChange={ ( v ) => set( { submit_label: v } ) } maxLength={ 60 } />
						<TextField label={ __( 'Next button', 'dev3-ai-form-builder' ) } value={ s.next_label } onChange={ ( v ) => set( { next_label: v } ) } maxLength={ 60 } />
						<TextField label={ __( 'Back button', 'dev3-ai-form-builder' ) } value={ s.prev_label } onChange={ ( v ) => set( { prev_label: v } ) } maxLength={ 60 } />
						<Select
							label={ __( 'Progress indicator', 'dev3-ai-form-builder' ) }
							value={ s.progress }
							onChange={ ( v ) => set( { progress: v } ) }
							options={ [
								{ value: 'bar', label: __( 'Progress bar', 'dev3-ai-form-builder' ) },
								{ value: 'steps', label: __( 'Step names', 'dev3-ai-form-builder' ) },
								{ value: 'none', label: __( 'None', 'dev3-ai-form-builder' ) },
							] }
							help={ multi ? undefined : __( 'Shown when the form has more than one step.', 'dev3-ai-form-builder' ) }
						/>
					</FormGrid>
					<Toggle label={ __( 'Save and continue later', 'dev3-ai-form-builder' ) } help={ __( 'Visitors get a private link (and an email if they entered one) to finish the form later. Drafts expire after 30 days.', 'dev3-ai-form-builder' ) } checked={ s.save_continue } onChange={ ( v ) => set( { save_continue: v } ) } />
					{ s.save_continue && <TextField label={ __( 'Save link text', 'dev3-ai-form-builder' ) } value={ s.save_label } onChange={ ( v ) => set( { save_label: v } ) } maxLength={ 60 } /> }
				</Stack>
			</Card>
			<Card title={ __( 'Availability', 'dev3-ai-form-builder' ) }>
				<Stack gap={ 14 }>
					<FormGrid>
						<TextField type="datetime-local" label={ __( 'Open from', 'dev3-ai-form-builder' ) } value={ ( s.open_at || '' ).replace( ' ', 'T' ) } onChange={ ( v ) => set( { open_at: v.replace( 'T', ' ' ).slice( 0, 16 ) } ) } />
						<TextField type="datetime-local" label={ __( 'Close at', 'dev3-ai-form-builder' ) } value={ ( s.close_at || '' ).replace( ' ', 'T' ) } onChange={ ( v ) => set( { close_at: v.replace( 'T', ' ' ).slice( 0, 16 ) } ) } />
					</FormGrid>
					<NumberField label={ __( 'Maximum number of entries (0 = unlimited)', 'dev3-ai-form-builder' ) } value={ s.limit_entries } min={ 0 } onChange={ ( v ) => set( { limit_entries: v === '' ? 0 : v } ) } />
					<TextField label={ __( 'Message when closed', 'dev3-ai-form-builder' ) } value={ s.closed_message } onChange={ ( v ) => set( { closed_message: v } ) } maxLength={ 300 } />
					<Toggle label={ __( 'Only logged-in users', 'dev3-ai-form-builder' ) } checked={ s.require_login } onChange={ ( v ) => set( { require_login: v } ) } />
					{ s.require_login && <TextField label={ __( 'Message for logged-out visitors', 'dev3-ai-form-builder' ) } value={ s.login_message } onChange={ ( v ) => set( { login_message: v } ) } maxLength={ 300 } /> }
					<Toggle label={ __( 'Store entries in WordPress', 'dev3-ai-form-builder' ) } help={ __( 'Turn off to only send notifications (payment forms always store entries).', 'dev3-ai-form-builder' ) } checked={ s.store_entries } onChange={ ( v ) => set( { store_entries: v } ) } />
				</Stack>
			</Card>
			<Card title={ __( 'Look & feel', 'dev3-ai-form-builder' ) } description={ __( 'Forms inherit your theme\'s fonts and colours.', 'dev3-ai-form-builder' ) }>
				<Stack gap={ 14 }>
					<Toggle label={ __( 'Show the form title above the form', 'dev3-ai-form-builder' ) } checked={ s.show_title } onChange={ ( v ) => set( { show_title: v } ) } />
					<Textarea label={ __( 'Intro text', 'dev3-ai-form-builder' ) } rows={ 3 } value={ s.description } onChange={ ( v ) => set( { description: v } ) } help={ __( 'Shown above the first field. Basic HTML allowed.', 'dev3-ai-form-builder' ) } />
					<ColorField label={ __( 'Accent colour', 'dev3-ai-form-builder' ) } value={ s.accent } onChange={ ( v ) => set( { accent: v } ) } help={ __( 'Buttons, focus rings and selected choices. Leave empty for the default blue.', 'dev3-ai-form-builder' ) } />
					<TextField label={ __( 'Extra CSS class', 'dev3-ai-form-builder' ) } value={ s.css_class } onChange={ ( v ) => set( { css_class: v } ) } />
				</Stack>
			</Card>
		</Columns>
	);
}
