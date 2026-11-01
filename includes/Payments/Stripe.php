<?php
/**
 * Stripe Checkout (hosted payment page) through the REST API; no Stripe.js needed.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Payments;

use Dev3\AiFormBuilder\Data\Entries;
use Dev3\AiFormBuilder\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe.
 */
class Stripe {

	const API = 'https://api.stripe.com/v1/';

	/**
	 * Has a secret key.
	 *
	 * @return bool
	 */
	public static function configured() {
		return '' !== Secrets::get( 'stripe_secret' );
	}

	/**
	 * Test mode (sk_test_ key).
	 *
	 * @return bool
	 */
	public static function test_mode() {
		return 0 === strpos( Secrets::get( 'stripe_secret' ), 'sk_test_' ) || 0 === strpos( Secrets::get( 'stripe_secret' ), 'rk_test_' );
	}

	/**
	 * API call.
	 *
	 * @param string $method Method.
	 * @param string $path   Path.
	 * @param array  $body   Form body.
	 * @return array|\WP_Error
	 */
	public static function request( $method, $path, array $body = array() ) {
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization'  => 'Bearer ' . Secrets::get( 'stripe_secret' ),
				'Stripe-Version' => '2024-06-20',
			),
		);
		if ( $body ) {
			$args['body'] = $body;
		}
		$response = wp_remote_request( self::API . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'HTTP ' . $code;
			return new \WP_Error( 'dev3fb_stripe', 'Stripe: ' . $message, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Creates a Checkout Session.
	 *
	 * @param array $args { entry_id, amount, currency, description, email, success, cancel }.
	 * @return array|\WP_Error { ref, url }
	 */
	public static function create_session( array $args ) {
		$body = array(
			'mode'                                   => 'payment',
			'success_url'                            => add_query_arg( 'session_id', '{CHECKOUT_SESSION_ID}', $args['success'] ),
			'cancel_url'                             => $args['cancel'],
			'client_reference_id'                    => (string) $args['entry_id'],
			'metadata[dev3fb_entry]'                 => (string) $args['entry_id'],
			'line_items[0][quantity]'                => 1,
			'line_items[0][price_data][currency]'    => strtolower( $args['currency'] ),
			'line_items[0][price_data][unit_amount]' => Payments::minor_units( $args['amount'], $args['currency'] ),
			'line_items[0][price_data][product_data][name]' => mb_substr( $args['description'], 0, 120 ),
		);
		if ( is_email( $args['email'] ) ) {
			$body['customer_email'] = $args['email'];
		}
		// The literal {CHECKOUT_SESSION_ID} must survive URL encoding.
		$body['success_url'] = str_replace( array( '%7B', '%7D' ), array( '{', '}' ), $body['success_url'] );
		$data                = self::request( 'POST', 'checkout/sessions', $body );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['id'] ) || empty( $data['url'] ) ) {
			return new \WP_Error( 'dev3fb_stripe', 'Stripe: unexpected response.' );
		}
		return array(
			'ref' => (string) $data['id'],
			'url' => (string) $data['url'],
		);
	}

	/**
	 * Checks a session.
	 *
	 * @param string $session_id Session id.
	 * @return array|\WP_Error { status: paid|pending, txn }
	 */
	public static function verify_session( $session_id ) {
		if ( ! preg_match( '/^cs_[A-Za-z0-9_]+$/', (string) $session_id ) ) {
			return new \WP_Error( 'dev3fb_stripe', 'Stripe: invalid session.' );
		}
		$data = self::request( 'GET', 'checkout/sessions/' . $session_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return array(
			'status' => isset( $data['payment_status'] ) && 'paid' === $data['payment_status'] ? 'paid' : 'pending',
			'txn'    => isset( $data['payment_intent'] ) && is_string( $data['payment_intent'] ) ? $data['payment_intent'] : '',
		);
	}

	/**
	 * Verifies a webhook signature (Stripe-Signature: t=…,v1=…).
	 *
	 * @param string   $payload Raw body.
	 * @param string   $header  Header.
	 * @param string   $secret  whsec_ secret.
	 * @param int|null $now     Clock.
	 * @return bool
	 */
	public static function verify_signature( $payload, $header, $secret, $now = null ) {
		if ( '' === (string) $secret || '' === (string) $header ) {
			return false;
		}
		$time = 0;
		$sigs = array();
		foreach ( explode( ',', (string) $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$time = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$sigs[] = $kv[1];
			}
		}
		$now = null === $now ? time() : (int) $now;
		if ( ! $time || abs( $now - $time ) > 300 ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $time . '.' . $payload, $secret );
		foreach ( $sigs as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Handles a webhook event.
	 *
	 * @param string $payload Raw body.
	 * @param string $header  Stripe-Signature header.
	 * @return true|\WP_Error
	 */
	public static function webhook( $payload, $header ) {
		if ( ! self::verify_signature( $payload, $header, Secrets::get( 'stripe_webhook_secret' ) ) ) {
			return new \WP_Error( 'dev3fb_stripe_signature', 'Invalid signature.', array( 'status' => 400 ) );
		}
		$event = json_decode( (string) $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new \WP_Error( 'dev3fb_stripe_event', 'Invalid event.', array( 'status' => 400 ) );
		}
		if ( in_array( $event['type'], array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded' ), true ) ) {
			$session = isset( $event['data']['object'] ) ? $event['data']['object'] : array();
			$entry   = Entries::by_payment_ref( isset( $session['id'] ) ? (string) $session['id'] : '' );
			if ( $entry && isset( $session['payment_status'] ) && 'paid' === $session['payment_status'] ) {
				Payments::complete( $entry['id'], 'stripe', isset( $session['payment_intent'] ) && is_string( $session['payment_intent'] ) ? $session['payment_intent'] : '' );
			}
		}
		return true;
	}
}
