<?php
/**
 * Payments: Stripe Checkout and PayPal Orders v2 (redirect flows, no card data touches the site).
 *
 * The amount is always computed on the server from the form's product/quantity/amount/
 * calculation fields. Entries wait in status "pending" until the provider confirms payment
 * (return URL verification, or the Stripe webhook); notifications are sent then.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Payments;

use Dev3\AiFormBuilder\Data\Entries;
use Dev3\AiFormBuilder\Data\Entry_Log;
use Dev3\AiFormBuilder\Delivery\Dispatcher;
use Dev3\AiFormBuilder\Forms\Form_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Payment orchestration.
 */
class Payments {

	const ZERO_DECIMAL = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/**
	 * Provider is configured.
	 *
	 * @param string $provider stripe|paypal.
	 * @return bool
	 */
	public static function available( $provider ) {
		return 'stripe' === $provider ? Stripe::configured() : ( 'paypal' === $provider ? PayPal::configured() : false );
	}

	/**
	 * Smallest-unit amount (cents).
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 * @return int
	 */
	public static function minor_units( $amount, $currency ) {
		return in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ? (int) round( $amount ) : (int) round( $amount * 100 );
	}

	/**
	 * Signature binding a return URL to an entry.
	 *
	 * @param int $entry_id Entry id.
	 * @return string
	 */
	public static function sig( $entry_id ) {
		return substr( hash_hmac( 'sha256', 'dev3fb-pay|' . (int) $entry_id, wp_salt( 'auth' ) ), 0, 24 );
	}

	/**
	 * Return / cancel URL.
	 *
	 * @param string $base     Page URL.
	 * @param string $provider Provider or "cancel".
	 * @param int    $entry_id Entry id.
	 * @return string
	 */
	public static function return_url( $base, $provider, $entry_id ) {
		$base = '' !== (string) $base && wp_validate_redirect( $base, '' ) ? $base : home_url( '/' );
		$base = remove_query_arg( array( 'dev3fb_pay', 'dev3fb_entry', 'dev3fb_sig', 'session_id', 'token', 'PayerID' ), $base );
		return add_query_arg(
			array(
				'dev3fb_pay'   => $provider,
				'dev3fb_entry' => (int) $entry_id,
				'dev3fb_sig'   => self::sig( $entry_id ),
			),
			$base
		);
	}

	/**
	 * Starts a payment for an entry.
	 *
	 * @param array  $form     Form.
	 * @param array  $entry    Entry (status pending).
	 * @param string $provider stripe|paypal.
	 * @param string $page_url Page to come back to.
	 * @return string|\WP_Error Redirect URL.
	 */
	public static function start( array $form, array $entry, $provider, $page_url ) {
		if ( ! self::available( $provider ) ) {
			return new \WP_Error( 'dev3fb_payment_unavailable', __( 'Online payment is not available right now. Please contact us.', 'dev3-ai-form-builder' ), array( 'status' => 503 ) );
		}
		$description = '' !== $form['definition']['payments']['description'] ? $form['definition']['payments']['description'] : $form['title'];
		$args        = array(
			'entry_id'    => $entry['id'],
			'amount'      => (float) $entry['payment_total'],
			'currency'    => $entry['payment_currency'],
			'description' => $description,
			'email'       => $entry['email'],
			'success'     => self::return_url( $page_url, $provider, $entry['id'] ),
			'cancel'      => self::return_url( $page_url, 'cancel', $entry['id'] ),
		);
		$result      = 'stripe' === $provider ? Stripe::create_session( $args ) : PayPal::create_order( $args );
		if ( is_wp_error( $result ) ) {
			Entry_Log::add( $entry['id'], 'payment', 'error', sprintf( '%s: %s', ucfirst( $provider ), $result->get_error_message() ) );
			return new \WP_Error( 'dev3fb_payment_failed', __( 'We could not start the payment. Please try again.', 'dev3-ai-form-builder' ), array( 'status' => 502 ) );
		}
		Entries::update(
			$entry['id'],
			array(
				'payment_provider' => $provider,
				'payment_ref'      => $result['ref'],
				'payment_status'   => 'pending',
			)
		);
		Entry_Log::add( $entry['id'], 'payment', 'info', sprintf( '%s checkout started (%s %s).', ucfirst( $provider ), number_format( (float) $entry['payment_total'], 2 ), $entry['payment_currency'] ) );
		return $result['url'];
	}

	/**
	 * Marks an entry paid (idempotent) and delivers it.
	 *
	 * @param int    $entry_id Entry id.
	 * @param string $provider Provider.
	 * @param string $txn      Transaction / capture id.
	 * @return bool Whether it changed.
	 */
	public static function complete( $entry_id, $provider, $txn = '' ) {
		$entry = Entries::get( $entry_id );
		if ( ! $entry || 'paid' === $entry['payment_status'] ) {
			return false;
		}
		Entries::update(
			$entry_id,
			array(
				'payment_status' => 'paid',
				'status'         => 'pending' === $entry['status'] ? 'unread' : $entry['status'],
			)
		);
		Entries::merge_meta(
			$entry_id,
			array(
				'payment_txn'  => sanitize_text_field( (string) $txn ),
				'payment_paid' => gmdate( 'c' ),
			)
		);
		Entry_Log::add( $entry_id, 'payment', 'ok', sprintf( '%s payment completed%s.', ucfirst( $provider ), '' !== $txn ? ' (' . $txn . ')' : '' ) );
		$entry = Entries::get( $entry_id );
		$form  = Form_Repository::get( $entry['form_id'] );
		if ( $form ) {
			Dispatcher::deliver( $form, $entry );
		}
		/**
		 * Fires when a payment completes.
		 *
		 * @param array  $entry    Entry.
		 * @param string $provider Provider.
		 */
		do_action( 'dev3fb_payment_completed', $entry, $provider );
		return true;
	}

	/**
	 * Handles the browser coming back from the provider.
	 *
	 * @param array $query Query args.
	 * @return array { entry_id, form_id, status: paid|pending|cancelled|failed|invalid }
	 */
	public static function handle_return( array $query ) {
		$provider = isset( $query['dev3fb_pay'] ) ? sanitize_key( (string) $query['dev3fb_pay'] ) : '';
		$entry_id = isset( $query['dev3fb_entry'] ) ? absint( $query['dev3fb_entry'] ) : 0;
		$sig      = isset( $query['dev3fb_sig'] ) ? (string) $query['dev3fb_sig'] : '';
		$entry    = $entry_id ? Entries::get( $entry_id ) : null;
		$out      = array(
			'entry_id' => $entry_id,
			'form_id'  => $entry ? $entry['form_id'] : 0,
			'status'   => 'invalid',
		);
		if ( ! $entry || ! hash_equals( self::sig( $entry_id ), $sig ) ) {
			return $out;
		}
		if ( 'paid' === $entry['payment_status'] ) {
			$out['status'] = 'paid';
			return $out;
		}
		if ( 'cancel' === $provider ) {
			Entries::update( $entry_id, array( 'payment_status' => 'cancelled' ) );
			Entry_Log::add( $entry_id, 'payment', 'info', 'The visitor cancelled the payment.' );
			$out['status'] = 'cancelled';
			return $out;
		}
		if ( 'stripe' === $provider ) {
			$check = Stripe::verify_session( $entry['payment_ref'] );
		} elseif ( 'paypal' === $provider ) {
			$order = isset( $query['token'] ) ? sanitize_text_field( (string) $query['token'] ) : '';
			$check = $order === $entry['payment_ref'] ? PayPal::capture( $order ) : new \WP_Error( 'dev3fb_paypal_mismatch', 'Order mismatch.' );
		} else {
			return $out;
		}
		if ( is_wp_error( $check ) ) {
			Entry_Log::add( $entry_id, 'payment', 'error', $check->get_error_message() );
			$out['status'] = 'failed';
			return $out;
		}
		if ( 'paid' === $check['status'] ) {
			self::complete( $entry_id, $provider, $check['txn'] );
			$out['status'] = 'paid';
		} else {
			$out['status'] = 'pending';
		}
		return $out;
	}
}
