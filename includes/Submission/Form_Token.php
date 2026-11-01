<?php
/**
 * Signed, stateless per-form token printed with every form (ported from dev3fa's Form_Token).
 *
 * It carries the render time, so the server can enforce the "too fast to be human" time trap,
 * and it gates the public paste-to-fill endpoint so bots can't point the AI at arbitrary forms.
 * HMAC over form id + issue time with the site salt; works with full-page caches.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Submission;

defined( 'ABSPATH' ) || exit;

/**
 * Token.
 */
class Form_Token {

	/**
	 * Lifetime (seconds).
	 *
	 * @return int
	 */
	public static function ttl() {
		/**
		 * Form token lifetime in seconds; keep it above your page-cache lifetime.
		 *
		 * @param int $ttl Seconds (default 7 days).
		 */
		return max( 300, (int) apply_filters( 'dev3fb_token_ttl', 7 * DAY_IN_SECONDS ) );
	}

	/**
	 * Creates a token.
	 *
	 * @param int      $form_id Form id.
	 * @param int|null $now     Clock.
	 * @return string
	 */
	public static function create( $form_id, $now = null ) {
		$issued = null === $now ? time() : (int) $now;
		return $issued . '.' . self::sign( $form_id, $issued );
	}

	/**
	 * Verifies a token; returns the issue time.
	 *
	 * @param string   $token   Token.
	 * @param int      $form_id Form id.
	 * @param int|null $now     Clock.
	 * @return int|\WP_Error Issue timestamp.
	 */
	public static function verify( $token, $form_id, $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$parts = explode( '.', (string) $token );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! hash_equals( self::sign( $form_id, (int) $parts[0] ), $parts[1] ) ) {
			return new \WP_Error( 'dev3fb_bad_token', __( 'This form is out of date. Reload the page and try again.', 'dev3-ai-form-builder' ), array( 'status' => 403 ) );
		}
		$issued = (int) $parts[0];
		if ( $issued > $now + 60 || $issued + self::ttl() < $now ) {
			return new \WP_Error( 'dev3fb_token_expired', __( 'This form is out of date. Reload the page and try again.', 'dev3-ai-form-builder' ), array( 'status' => 403 ) );
		}
		return $issued;
	}

	/**
	 * HMAC.
	 *
	 * @param int $form_id Form id.
	 * @param int $issued  Issue time.
	 * @return string
	 */
	private static function sign( $form_id, $issued ) {
		return substr( hash_hmac( 'sha256', 'dev3fb|' . (int) $form_id . '|' . (int) $issued, wp_salt( 'nonce' ) ), 0, 32 );
	}
}
