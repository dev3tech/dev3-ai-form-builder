<?php
/**
 * PayPal Checkout through the Orders v2 REST API (redirect to approve, capture on return).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Payments;

use Dev3\AiFormBuilder\Core3\Core\Settings_Store;
use Dev3\AiFormBuilder\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * PayPal.
 */
class PayPal {

	/**
	 * Has credentials.
	 *
	 * @return bool
	 */
	public static function configured() {
		return '' !== (string) Settings_Store::get( 'paypal_client_id', '' ) && '' !== Secrets::get( 'paypal_secret' );
	}

	/**
	 * API base.
	 *
	 * @return string
	 */
	public static function base() {
		return Settings_Store::get( 'paypal_sandbox', true ) ? 'https://api-m.sandbox.paypal.com/' : 'https://api-m.paypal.com/';
	}

	/**
	 * OAuth access token (cached).
	 *
	 * @return string|\WP_Error
	 */
	public static function token() {
		$cache_key = 'dev3fb_paypal_token_' . md5( self::base() . Settings_Store::get( 'paypal_client_id', '' ) );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$response = wp_remote_post(
			self::base() . 'v1/oauth2/token',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( Settings_Store::get( 'paypal_client_id', '' ) . ':' . Secrets::get( 'paypal_secret' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Accept'        => 'application/json',
				),
				'body'    => array( 'grant_type' => 'client_credentials' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			return new \WP_Error( 'dev3fb_paypal_auth', 'PayPal: authentication failed. Check the client id and secret.' );
		}
		set_transient( $cache_key, (string) $data['access_token'], max( 60, (int) ( isset( $data['expires_in'] ) ? $data['expires_in'] : 3000 ) - 120 ) );
		return (string) $data['access_token'];
	}

	/**
	 * API call.
	 *
	 * @param string $method Method.
	 * @param string $path   Path.
	 * @param mixed  $body   JSON body (array|object|null).
	 * @return array|\WP_Error
	 */
	public static function request( $method, $path, $body = null ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Prefer'        => 'return=representation',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( self::base() . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['message'] ) ? (string) $data['message'] : 'HTTP ' . $code;
			return new \WP_Error( 'dev3fb_paypal', 'PayPal: ' . $message, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Creates an order.
	 *
	 * @param array $args { entry_id, amount, currency, description, success, cancel }.
	 * @return array|\WP_Error { ref, url }
	 */
	public static function create_order( array $args ) {
		$decimals = in_array( strtoupper( $args['currency'] ), Payments::ZERO_DECIMAL, true ) ? 0 : 2;
		$data     = self::request(
			'POST',
			'v2/checkout/orders',
			array(
				'intent'              => 'CAPTURE',
				'purchase_units'      => array(
					array(
						'reference_id' => 'entry-' . (int) $args['entry_id'],
						'custom_id'    => (string) (int) $args['entry_id'],
						'description'  => mb_substr( $args['description'], 0, 120 ),
						'amount'       => array(
							'currency_code' => strtoupper( $args['currency'] ),
							'value'         => number_format( (float) $args['amount'], $decimals, '.', '' ),
						),
					),
				),
				'application_context' => array(
					'brand_name'  => mb_substr( wp_strip_all_tags( get_bloginfo( 'name' ) ), 0, 120 ),
					'user_action' => 'PAY_NOW',
					'return_url'  => $args['success'],
					'cancel_url'  => $args['cancel'],
				),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$url = '';
		foreach ( isset( $data['links'] ) ? (array) $data['links'] : array() as $link ) {
			if ( isset( $link['rel'], $link['href'] ) && in_array( $link['rel'], array( 'approve', 'payer-action' ), true ) ) {
				$url = (string) $link['href'];
				break;
			}
		}
		if ( empty( $data['id'] ) || '' === $url ) {
			return new \WP_Error( 'dev3fb_paypal', 'PayPal: unexpected response.' );
		}
		return array(
			'ref' => (string) $data['id'],
			'url' => $url,
		);
	}

	/**
	 * Captures an approved order.
	 *
	 * @param string $order_id Order id.
	 * @return array|\WP_Error { status: paid|pending, txn }
	 */
	public static function capture( $order_id ) {
		if ( ! preg_match( '/^[A-Z0-9]{10,30}$/', (string) $order_id ) ) {
			return new \WP_Error( 'dev3fb_paypal', 'PayPal: invalid order.' );
		}
		$data = self::request( 'POST', 'v2/checkout/orders/' . $order_id . '/capture', new \stdClass() );
		if ( is_wp_error( $data ) && 422 === (int) ( $data->get_error_data()['status'] ?? 0 ) ) {
			// Already captured: read the order instead.
			$data = self::request( 'GET', 'v2/checkout/orders/' . $order_id );
		}
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$txn = '';
		if ( isset( $data['purchase_units'][0]['payments']['captures'][0]['id'] ) ) {
			$txn = (string) $data['purchase_units'][0]['payments']['captures'][0]['id'];
		}
		return array(
			'status' => isset( $data['status'] ) && 'COMPLETED' === $data['status'] ? 'paid' : 'pending',
			'txn'    => $txn,
		);
	}
}
