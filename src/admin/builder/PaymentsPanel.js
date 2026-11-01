/**
 * Payments for one form (Stripe Checkout / PayPal).
 */
import { __ } from '@wordpress/i18n';
import { Badge, Button, Card, Checkbox, Columns, FormGrid, Notice, Select, Stack, TextField, Toggle } from '../../core3/ui';
import { allFields } from './model';

const CURRENCIES = [ 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'JPY', 'SGD', 'HKD', 'INR', 'BRL', 'MXN', 'ZAR', 'AED' ].map( ( c ) => ( { value: c, label: c } ) );

/**
 * @param {Object}   props            Props.
 * @param {Object}   props.definition Definition.
 * @param {Object}   props.payments   Credentials status { stripe, paypal }.
 * @param {Function} props.onChange   ( paymentsPatch ) => void.
 * @param {Function} props.onAddTotal Adds product + total fields.
 * @return {Element} Panel.
 */
export default function PaymentsPanel( { definition, payments, onChange, onAddTotal } ) {
	const p = definition.payments;
	const fields = allFields( definition );
	const hasAmount = fields.some( ( f ) => [ 'product', 'amount', 'total', 'calculation' ].includes( f.type ) );
	const amountFields = fields.filter( ( f ) => [ 'total', 'calculation', 'amount' ].includes( f.type ) ).map( ( f ) => ( { value: f.id, label: f.label || f.id } ) );
	const toggleProvider = ( id, on ) => {
		const list = on ? Array.from( new Set( [ ...p.providers, id ] ) ) : p.providers.filter( ( x ) => x !== id );
		onChange( { providers: list.length ? list : [ id === 'stripe' ? 'paypal' : 'stripe' ] } );
	};
	return (
		<Stack gap={ 20 }>
			<Card title={ __( 'Collect payments', 'dev3-ai-form-builder' ) } description={ __( 'Visitors are sent to a secure Stripe or PayPal checkout page after submitting. The amount is always recalculated on your server.', 'dev3-ai-form-builder' ) }>
				<Stack gap={ 14 }>
					<Toggle label={ __( 'Take payment for this form', 'dev3-ai-form-builder' ) } checked={ p.enabled } onChange={ ( v ) => onChange( { enabled: v } ) } />
					{ p.enabled && ! hasAmount && (
						<Notice tone="warning" title={ __( 'Add something to charge for', 'dev3-ai-form-builder' ) } actions={ <Button size="sm" onClick={ onAddTotal }>{ __( 'Add a product and total', 'dev3-ai-form-builder' ) }</Button> }>
							{ __( 'Use Product, Quantity, Custom amount or Calculation fields; the Order total field shows the sum.', 'dev3-ai-form-builder' ) }
						</Notice>
					) }
					{ p.enabled && (
						<FormGrid>
							<Select label={ __( 'Currency', 'dev3-ai-form-builder' ) } value={ p.currency } onChange={ ( v ) => onChange( { currency: v } ) } options={ CURRENCIES } />
							<Select label={ __( 'Amount to charge', 'dev3-ai-form-builder' ) } value={ p.amount_field } onChange={ ( v ) => onChange( { amount_field: v } ) } options={ amountFields } placeholder={ __( 'Order total (automatic)', 'dev3-ai-form-builder' ) } />
							<TextField label={ __( 'Description on the checkout page', 'dev3-ai-form-builder' ) } value={ p.description } onChange={ ( v ) => onChange( { description: v } ) } maxLength={ 200 } placeholder={ __( 'Defaults to the form title', 'dev3-ai-form-builder' ) } />
						</FormGrid>
					) }
				</Stack>
			</Card>
			{ p.enabled && (
				<Columns min={ 300 }>
					<Card title="Stripe" actions={ payments && payments.stripe && payments.stripe.configured ? <Badge tone="success" dot>{ payments.stripe.test_mode ? __( 'Test mode', 'dev3-ai-form-builder' ) : __( 'Live', 'dev3-ai-form-builder' ) }</Badge> : <Badge>{ __( 'Not set up', 'dev3-ai-form-builder' ) }</Badge> }>
						<Stack gap={ 10 }>
							<Checkbox label={ __( 'Offer card payment (Stripe Checkout)', 'dev3-ai-form-builder' ) } checked={ p.providers.includes( 'stripe' ) } onChange={ ( v ) => toggleProvider( 'stripe', v ) } />
							{ ! ( payments && payments.stripe && payments.stripe.configured ) && <Button size="sm" href="#/settings/payments">{ __( 'Add Stripe keys', 'dev3-ai-form-builder' ) }</Button> }
						</Stack>
					</Card>
					<Card title="PayPal" actions={ payments && payments.paypal && payments.paypal.configured ? <Badge tone="success" dot>{ __( 'Connected', 'dev3-ai-form-builder' ) }</Badge> : <Badge>{ __( 'Not set up', 'dev3-ai-form-builder' ) }</Badge> }>
						<Stack gap={ 10 }>
							<Checkbox label={ __( 'Offer PayPal', 'dev3-ai-form-builder' ) } checked={ p.providers.includes( 'paypal' ) } onChange={ ( v ) => toggleProvider( 'paypal', v ) } />
							{ ! ( payments && payments.paypal && payments.paypal.configured ) && <Button size="sm" href="#/settings/payments">{ __( 'Add PayPal credentials', 'dev3-ai-form-builder' ) }</Button> }
						</Stack>
					</Card>
				</Columns>
			) }
		</Stack>
	);
}
