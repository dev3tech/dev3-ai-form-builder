<?php
/**
 * Outgoing webhooks (per form, JSON or form-encoded, HMAC signed).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Delivery;

use Dev3\AiFormBuilder\Data\Entry_Log;
use Dev3\AiFormBuilder\Engine\Logic;
use Dev3\AiFormBuilder\Forms\Definition;
use Dev3\AiFormBuilder\Submission\Entry_Format;

defined( 'ABSPATH' ) || exit;

/**
 * Webhooks.
 */
class Webhooks {

	/**
	 * Standard payload (also used by Zapier).
	 *
	 * @param array $form  Form.
	 * @param array $entry Entry.
	 * @return array
	 */
	public static function payload( array $form, array $entry ) {
		$fields = array();
		foreach ( Definition::input_fields( $form['definition'] ) as $field ) {
			$value = isset( $entry['data'][ $field['id'] ] ) ? $entry['data'][ $field['id'] ] : '';
			if ( in_array( $field['type'], array( 'file', 'signature' ), true ) ) {
				$value = Entry_Format::display( $field, $value );
			}
			if ( 'password' === $field['type'] ) {
				continue;
			}
			$fields[ $field['id'] ] = array(
				'label'   => $field['label'],
				'type'    => $field['type'],
				'value'   => $value,
				'display' => Entry_Format::display( $field, isset( $entry['data'][ $field['id'] ] ) ? $entry['data'][ $field['id'] ] : '' ),
			);
		}
		$payload = array(
			'event'   => 'entry.created',
			'form'    => array(
				'id'    => $form['id'],
				'title' => $form['title'],
			),
			'entry'   => array(
				'id'         => $entry['id'],
				'created_at' => $entry['created_at'],
				'status'     => $entry['status'],
				'lang'       => $entry['lang'],
				'payment'    => array(
					'status'   => $entry['payment_status'],
					'total'    => $entry['payment_total'],
					'currency' => $entry['payment_currency'],
				),
			),
			'fields'  => $fields,
			'site'    => home_url( '/' ),
			'sent_at' => gmdate( 'c' ),
		);
		/**
		 * Filters the webhook / Zapier payload.
		 *
		 * @param array $payload Payload.
		 * @param array $form    Form.
		 * @param array $entry   Entry.
		 */
		return (array) apply_filters( 'dev3fb_webhook_payload', $payload, $form, $entry );
	}

	/**
	 * Sends every matching webhook.
	 *
	 * @param array $form  Form.
	 * @param array $entry Entry.
	 * @return int Sent OK.
	 */
	public static function send_all( array $form, array $entry ) {
		$ok = 0;
		foreach ( $form['definition']['webhooks'] as $hook ) {
			if ( empty( $hook['enabled'] ) || ! Logic::visible( $hook['logic'], $entry['data'] ) ) {
				continue;
			}
			$ok += true === self::send( $hook, self::payload( $form, $entry ), $entry['id'] ) ? 1 : 0;
		}
		return $ok;
	}

	/**
	 * Posts one webhook.
	 *
	 * @param array  $hook     Hook.
	 * @param array  $payload  Payload.
	 * @param int    $entry_id Entry id (log).
	 * @param string $type    Log type.
	 * @return true|\WP_Error
	 */
	public static function send( array $hook, array $payload, $entry_id = 0, $type = 'webhook' ) {
		if ( 'form' === ( isset( $hook['format'] ) ? $hook['format'] : 'json' ) ) {
			$flat = array(
				'form_id'  => $payload['form']['id'],
				'entry_id' => $payload['entry']['id'],
			);
			foreach ( $payload['fields'] as $id => $field ) {
				$flat[ $id ] = $field['display'];
			}
			$body         = http_build_query( $flat );
			$content_type = 'application/x-www-form-urlencoded';
		} else {
			$body         = (string) wp_json_encode( $payload );
			$content_type = 'application/json';
		}
		$headers = array(
			'Content-Type' => $content_type,
			'User-Agent'   => 'DEV3-Form-Builder/' . DEV3FB_VERSION,
		);
		if ( ! empty( $hook['secret'] ) ) {
			$headers['X-DEV3FB-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $hook['secret'] );
		}
		$response = wp_safe_remote_post(
			$hook['url'],
			array(
				'timeout' => 8,
				'headers' => $headers,
				'body'    => $body,
			)
		);
		$name     = isset( $hook['name'] ) ? $hook['name'] : $type;
		if ( is_wp_error( $response ) ) {
			Entry_Log::add( $entry_id, $type, 'error', sprintf( '"%s": %s', $name, $response->get_error_message() ) );
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			Entry_Log::add( $entry_id, $type, 'error', sprintf( '"%s": HTTP %d', $name, $code ) );
			return new \WP_Error( 'dev3fb_webhook_http', sprintf( 'HTTP %d', $code ) );
		}
		Entry_Log::add( $entry_id, $type, 'ok', sprintf( '"%s": delivered (HTTP %d)', $name, $code ) );
		return true;
	}
}
