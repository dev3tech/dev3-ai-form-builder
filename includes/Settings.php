<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 * Integration and payment credentials are NOT stored here; see Secrets (encrypted, never
 * returned to the browser).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3fb_settings_defaults / dev3fb_settings_sanitize.
 */
class Settings {

	/**
	 * Adds plugin defaults.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'email_from_name'     => '',
				'email_from_address'  => '',
				'reply_signature'     => '',
				'auto_analyze'        => false,
				'analytics'           => true,
				'store_ip'            => false,
				'retention_days'      => 0,
				'upload_max_mb'       => 10,
				'classify_rate_limit' => 12,
				'daily_ai_cap'        => 2000,
				'fail_open'           => true,
				'skip_logged_in'      => false,
				'paypal_sandbox'      => true,
				'paypal_client_id'    => '',
				'block_message'       => __( 'Your message looks like spam and was not sent. If this is a mistake, please contact us another way.', 'dev3-ai-form-builder' ),
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		if ( isset( $input['email_from_name'] ) ) {
			$out['email_from_name'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['email_from_name'] ) ), 0, 100 );
		}
		if ( isset( $input['email_from_address'] ) ) {
			$email                     = sanitize_email( wp_unslash( (string) $input['email_from_address'] ) );
			$out['email_from_address'] = is_email( $email ) ? $email : '';
		}
		if ( isset( $input['reply_signature'] ) ) {
			$out['reply_signature'] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $input['reply_signature'] ) ), 0, 300 );
		}
		if ( isset( $input['block_message'] ) ) {
			$out['block_message'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['block_message'] ) ), 0, 300 );
		}
		if ( isset( $input['paypal_client_id'] ) ) {
			$out['paypal_client_id'] = mb_substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['paypal_client_id'] ), 0, 200 );
		}
		foreach ( array( 'auto_analyze', 'analytics', 'store_ip', 'fail_open', 'skip_logged_in', 'paypal_sandbox' ) as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = (bool) $input[ $flag ];
			}
		}
		if ( isset( $input['retention_days'] ) ) {
			$out['retention_days'] = max( 0, min( 3650, (int) $input['retention_days'] ) );
		}
		if ( isset( $input['upload_max_mb'] ) ) {
			$out['upload_max_mb'] = max( 1, min( 512, (int) $input['upload_max_mb'] ) );
		}
		if ( isset( $input['classify_rate_limit'] ) ) {
			$out['classify_rate_limit'] = max( 1, min( 600, (int) $input['classify_rate_limit'] ) );
		}
		if ( isset( $input['daily_ai_cap'] ) ) {
			$out['daily_ai_cap'] = max( 0, min( 1000000, (int) $input['daily_ai_cap'] ) );
		}
		return $out;
	}
}
