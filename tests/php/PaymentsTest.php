<?php
/**
 * Stripe Checkout and PayPal Orders flows with mocked APIs.
 *
 * @package Dev3\AiFormBuilder
 */

use Dev3\AiFormBuilder\Core3\Core\Settings_Store;
use Dev3\AiFormBuilder\Data\Entries;
use Dev3\AiFormBuilder\Payments\Payments;
use Dev3\AiFormBuilder\Payments\Stripe;
use Dev3\AiFormBuilder\Secrets;
use Dev3\AiFormBuilder\Submission\Submission_Handler;

class PaymentsTest extends Dev3fb_TestCase {

	private function ticket_form( $providers = array( 'stripe', 'paypal' ), $currency = 'EUR' ) {
		return $this->make_form(
			array(
				array( 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
				array( 'id' => 'ticket', 'type' => 'product', 'label' => 'Ticket', 'required' => true, 'options' => array( array( 'label' => 'Std', 'price' => 49.5 ) ) ),
				array( 'id' => 'qty', 'type' => 'quantity', 'label' => 'Qty', 'product' => 'ticket', 'min' => 1 ),
				array( 'id' => 'total', 'type' => 'total', 'label' => 'Total' ),
			),
			array(
				'payments' => array(
					'enabled'   => true,
					'providers' => $providers,
					'currency'  => $currency,
				),
			)
		);
	}

	public function test_minor_units() {
		$this->assertSame( 1999, Payments::minor_units( 19.99, 'USD' ) );
		$this->assertSame( 500, Payments::minor_units( 500, 'JPY' ) );
	}

	public function test_stripe_checkout_return_and_delivery() {
		Secrets::update( array( 'stripe_secret' => 'sk_test_123' ) );
		$this->routes['api.stripe.com/v1/checkout/sessions/cs_test_abc'] = array( 200, array( 'id' => 'cs_test_abc', 'payment_status' => 'paid', 'payment_intent' => 'pi_1' ) );
		$this->routes['api.stripe.com/v1/checkout/sessions']             = array( 200, array( 'id' => 'cs_test_abc', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_abc' ) );
		$id  = $this->ticket_form();
		$res = Submission_Handler::handle( $id, $this->params( $id, array( 'email' => 'ann@example.com', 'ticket' => 'Std', 'qty' => '2' ), array( 'dev3fb_provider' => 'stripe' ) ) );
		$this->assertTrue( $res['ok'], wp_json_encode( $res ) );
		$this->assertSame( 'https://checkout.stripe.com/c/pay/cs_test_abc', $res['redirect'] );
		$create = $this->requests_to( 'checkout/sessions' )[0];
		$this->assertSame( 'POST', $create['method'] );
		$this->assertSame( 'Bearer sk_test_123', $create['headers']['Authorization'] );
		$this->assertSame( 9900, $create['body']['line_items[0][price_data][unit_amount]'] );
		$this->assertSame( 'eur', $create['body']['line_items[0][price_data][currency]'] );
		$this->assertStringContainsString( 'session_id={CHECKOUT_SESSION_ID}', $create['body']['success_url'] );

		$entry = Entries::get( $res['entry_id'] );
		$this->assertSame( 'pending', $entry['status'] );
		$this->assertSame( 'cs_test_abc', $entry['payment_ref'] );
		$this->assertCount( 0, $this->mails, 'Notifications wait for the payment' );

		$bad = Payments::handle_return( array( 'dev3fb_pay' => 'stripe', 'dev3fb_entry' => $entry['id'], 'dev3fb_sig' => 'forged' ) );
		$this->assertSame( 'invalid', $bad['status'] );
		$ok = Payments::handle_return( array( 'dev3fb_pay' => 'stripe', 'dev3fb_entry' => $entry['id'], 'dev3fb_sig' => Payments::sig( $entry['id'] ) ) );
		$this->assertSame( 'paid', $ok['status'] );
		$entry = Entries::get( $entry['id'] );
		$this->assertSame( 'paid', $entry['payment_status'] );
		$this->assertSame( 'unread', $entry['status'] );
		$this->assertSame( 'pi_1', $entry['meta']['payment_txn'] );
		$this->assertCount( 1, $this->mails );
		$this->assertFalse( Payments::complete( $entry['id'], 'stripe', 'pi_1' ), 'Idempotent' );
		$this->assertCount( 1, $this->mails );
	}

	public function test_stripe_webhook_signature() {
		Secrets::update( array( 'stripe_secret' => 'sk_test_123', 'stripe_webhook_secret' => 'whsec_abc' ) );
		$payload = '{"type":"checkout.session.completed"}';
		$time    = time();
		$header  = 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $payload, 'whsec_abc' );
		$this->assertTrue( Stripe::verify_signature( $payload, $header, 'whsec_abc' ) );
		$this->assertFalse( Stripe::verify_signature( $payload . ' ', $header, 'whsec_abc' ) );
		$this->assertFalse( Stripe::verify_signature( $payload, 't=' . ( $time - 999 ) . ',v1=' . hash_hmac( 'sha256', ( $time - 999 ) . '.' . $payload, 'whsec_abc' ), 'whsec_abc' ), 'Replay window' );

		$this->routes['api.stripe.com/v1/checkout/sessions'] = array( 200, array( 'id' => 'cs_test_hook', 'url' => 'https://checkout.stripe.com/x' ) );
		$id    = $this->ticket_form( array( 'stripe' ) );
		$res   = Submission_Handler::handle( $id, $this->params( $id, array( 'email' => 'ann@example.com', 'ticket' => 'Std' ) ) );
		$event = wp_json_encode(
			array(
				'type' => 'checkout.session.completed',
				'data' => array( 'object' => array( 'id' => 'cs_test_hook', 'payment_status' => 'paid', 'payment_intent' => 'pi_9' ) ),
			)
		);
		$sig   = 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $event, 'whsec_abc' );
		do_action( 'rest_api_init' );
		$req = new WP_REST_Request( 'POST', '/dev3fb/v1/payments/stripe' );
		$req->set_body( $event );
		$req->set_header( 'Stripe-Signature', $sig );
		$this->assertSame( 200, rest_do_request( $req )->get_status() );
		$this->assertSame( 'paid', Entries::get( $res['entry_id'] )['payment_status'] );
		$req->set_header( 'Stripe-Signature', 't=' . $time . ',v1=bad' );
		$this->assertSame( 400, rest_do_request( $req )->get_status() );
	}

	public function test_paypal_order_and_capture() {
		Settings_Store::update( array( 'paypal_client_id' => 'client-abc', 'paypal_sandbox' => true ) );
		Settings_Store::flush();
		Secrets::update( array( 'paypal_secret' => 'pp-secret' ) );
		$this->routes['sandbox.paypal.com/v1/oauth2/token']                     = array( 200, array( 'access_token' => 'A21', 'expires_in' => 3600 ) );
		$this->routes['sandbox.paypal.com/v2/checkout/orders/ORDER12345/capture'] = array( 201, array( 'id' => 'ORDER12345', 'status' => 'COMPLETED', 'purchase_units' => array( array( 'payments' => array( 'captures' => array( array( 'id' => 'CAP1' ) ) ) ) ) ) );
		$this->routes['sandbox.paypal.com/v2/checkout/orders']                  = array(
			201,
			array(
				'id'    => 'ORDER12345',
				'links' => array(
					array( 'rel' => 'self', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER12345' ),
					array( 'rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER12345' ),
				),
			),
		);
		$id  = $this->ticket_form( array( 'paypal' ), 'USD' );
		$res = Submission_Handler::handle( $id, $this->params( $id, array( 'email' => 'ann@example.com', 'ticket' => 'Std', 'qty' => '3' ) ) );
		$this->assertSame( 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER12345', $res['redirect'] );
		$order = $this->requests_to( 'v2/checkout/orders' )[0]['json'];
		$this->assertSame( '148.50', $order['purchase_units'][0]['amount']['value'] );
		$this->assertSame( 'Bearer A21', $this->requests_to( 'v2/checkout/orders' )[0]['headers']['Authorization'] );
		$entry = Entries::get( $res['entry_id'] );
		$mismatch = Payments::handle_return( array( 'dev3fb_pay' => 'paypal', 'dev3fb_entry' => $entry['id'], 'dev3fb_sig' => Payments::sig( $entry['id'] ), 'token' => 'OTHER99999' ) );
		$this->assertSame( 'failed', $mismatch['status'] );
		$done = Payments::handle_return( array( 'dev3fb_pay' => 'paypal', 'dev3fb_entry' => $entry['id'], 'dev3fb_sig' => Payments::sig( $entry['id'] ), 'token' => 'ORDER12345' ) );
		$this->assertSame( 'paid', $done['status'] );
		$this->assertSame( 'CAP1', Entries::get( $entry['id'] )['meta']['payment_txn'] );
	}

	public function test_cancel_and_unconfigured_provider() {
		$id  = $this->ticket_form( array( 'stripe' ) );
		$res = Submission_Handler::handle( $id, $this->params( $id, array( 'email' => 'ann@example.com', 'ticket' => 'Std' ) ) );
		$this->assertFalse( $res['ok'], 'No Stripe key: payment cannot start' );
		$this->assertSame( 503, $res['status'] );

		Secrets::update( array( 'stripe_secret' => 'sk_test_1' ) );
		$this->routes['api.stripe.com'] = array( 200, array( 'id' => 'cs_test_c', 'url' => 'https://checkout.stripe.com/c' ) );
		$res   = Submission_Handler::handle( $id, $this->params( $id, array( 'email' => 'bo@example.com', 'ticket' => 'Std' ) ) );
		$entry = Entries::get( $res['entry_id'] );
		$out   = Payments::handle_return( array( 'dev3fb_pay' => 'cancel', 'dev3fb_entry' => $entry['id'], 'dev3fb_sig' => Payments::sig( $entry['id'] ) ) );
		$this->assertSame( 'cancelled', $out['status'] );
		$this->assertSame( 'cancelled', Entries::get( $entry['id'] )['payment_status'] );
	}
}
