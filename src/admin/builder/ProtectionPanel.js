/**
 * Spam protection and paste-to-fill for one form (DEV3 AI Spam Shield + Autofill).
 */
import { __, sprintf } from '@wordpress/i18n';
import { Card, Columns, NumberField, Notice, Select, Stack, Toggle, Button } from '../../core3/ui';
import { allFields } from './model';

/**
 * @param {Object}   props            Props.
 * @param {Object}   props.definition Definition.
 * @param {boolean}  props.aiReady    API key set and AI on.
 * @param {Function} props.onChange   ( protectionPatch ) => void.
 * @return {Element} Panel.
 */
export default function ProtectionPanel( { definition, aiReady, onChange } ) {
	const p = definition.protection;
	const textareas = allFields( definition ).filter( ( f ) => f.type === 'textarea' ).map( ( f ) => ( { value: f.id, label: f.label || f.id } ) );
	return (
		<Stack gap={ 20 }>
			{ ! aiReady && (
				<Notice tone="ai" title={ __( 'Connect DEV3 AI', 'dev3-ai-form-builder' ) } actions={ <Button size="sm" variant="primary" href="#/api">{ __( 'Add your API key', 'dev3-ai-form-builder' ) }</Button> }>
					{ __( 'The honeypot, time trap and rate limit work without AI. The AI spam shield and paste-to-fill need a Google Gemini API key.', 'dev3-ai-form-builder' ) }
				</Notice>
			) }
			<Columns min={ 340 }>
				<Card title={ __( 'Bot protection (no AI)', 'dev3-ai-form-builder' ) } description={ __( 'Invisible to people, no CAPTCHA.', 'dev3-ai-form-builder' ) }>
					<Stack gap={ 14 }>
						<Toggle label={ __( 'Honeypot field', 'dev3-ai-form-builder' ) } help={ __( 'A hidden field only bots fill in. They get a fake "thank you".', 'dev3-ai-form-builder' ) } checked={ p.honeypot } onChange={ ( v ) => onChange( { honeypot: v } ) } />
						<NumberField label={ __( 'Minimum seconds to fill the form', 'dev3-ai-form-builder' ) } value={ p.min_seconds } min={ 0 } max={ 60 } onChange={ ( v ) => onChange( { min_seconds: v === '' ? 0 : v } ) } help={ __( 'Submissions faster than this are treated as bots. 0 turns it off.', 'dev3-ai-form-builder' ) } />
						<NumberField label={ __( 'Submissions per minute per visitor', 'dev3-ai-form-builder' ) } value={ p.rate_limit } min={ 1 } max={ 120 } onChange={ ( v ) => onChange( { rate_limit: v === '' ? 6 : v } ) } />
					</Stack>
				</Card>
				<Card tone="ai" title={ __( 'DEV3 AI Spam Shield', 'dev3-ai-form-builder' ) } description={ __( 'Reads each message like a person would: SEO pitches, scams and abuse are caught; real enquiries pass.', 'dev3-ai-form-builder' ) }>
					<Stack gap={ 14 }>
						<Toggle label={ __( 'Check submissions with DEV3 AI', 'dev3-ai-form-builder' ) } checked={ p.ai_spam } onChange={ ( v ) => onChange( { ai_spam: v } ) } disabled={ ! aiReady && ! p.ai_spam } />
						{ p.ai_spam && (
							<>
								<NumberField label={ sprintf( /* translators: %d: percent */ __( 'Spam threshold (%d%% = flagged)', 'dev3-ai-form-builder' ), p.spam_threshold ) } value={ p.spam_threshold } min={ 50 } max={ 99 } onChange={ ( v ) => onChange( { spam_threshold: v === '' ? 80 : v } ) } />
								<Select
									label={ __( 'When spam is detected', 'dev3-ai-form-builder' ) }
									value={ p.spam_action }
									onChange={ ( v ) => onChange( { spam_action: v } ) }
									options={ [
										{ value: 'spam', label: __( 'Keep it in the Spam folder (no emails)', 'dev3-ai-form-builder' ) },
										{ value: 'block', label: __( 'Block it and show a message', 'dev3-ai-form-builder' ) },
										{ value: 'flag', label: __( 'Deliver it, but flag it', 'dev3-ai-form-builder' ) },
									] }
								/>
								<Toggle label={ __( 'Also stop abusive or threatening messages', 'dev3-ai-form-builder' ) } checked={ p.block_abusive } onChange={ ( v ) => onChange( { block_abusive: v } ) } />
								<p className="dev3fb-muted">{ __( 'Only text answers are checked; email addresses are reduced to their domain and phone numbers are never sent.', 'dev3-ai-form-builder' ) }</p>
							</>
						) }
					</Stack>
				</Card>
				<Card tone="ai" title={ __( 'DEV3 AI Paste-to-fill', 'dev3-ai-form-builder' ) } description={ __( 'Visitors paste an email signature or message and DEV3 AI fills the empty fields. They review before sending.', 'dev3-ai-form-builder' ) }>
					<Stack gap={ 14 }>
						<Toggle label={ __( 'Show "Paste your details" on this form', 'dev3-ai-form-builder' ) } checked={ p.paste_fill } onChange={ ( v ) => onChange( { paste_fill: v } ) } disabled={ ! aiReady && ! p.paste_fill } />
						{ p.paste_fill && textareas.length > 0 && (
							<Select label={ __( 'Never fill this field', 'dev3-ai-form-builder' ) } value={ p.paste_source || '' } onChange={ ( v ) => onChange( { paste_source: v } ) } options={ textareas } placeholder={ __( 'None', 'dev3-ai-form-builder' ) } help={ __( 'Optional: e.g. a free-text box visitors use themselves.', 'dev3-ai-form-builder' ) } />
						) }
					</Stack>
				</Card>
			</Columns>
		</Stack>
	);
}
