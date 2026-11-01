<?php
/**
 * "Save and continue later" drafts. The visitor gets a random resume token; only its hash is stored.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Drafts.
 */
class Drafts {

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		return Tables::name( 'drafts' );
	}

	/**
	 * Lifetime in days.
	 *
	 * @return int
	 */
	public static function ttl_days() {
		/**
		 * Days a saved draft stays resumable.
		 *
		 * @param int $days Days.
		 */
		return max( 1, (int) apply_filters( 'dev3fb_draft_days', 30 ) );
	}

	/**
	 * Token hash.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function hash( $token ) {
		return hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) );
	}

	/**
	 * Saves (or updates when $token is given) a draft.
	 *
	 * @param int    $form_id Form id.
	 * @param array  $data    Values.
	 * @param int    $step    Step index.
	 * @param string $email   Optional email.
	 * @param string $token   Existing token.
	 * @return string Token.
	 */
	public static function save( $form_id, array $data, $step, $email = '', $token = '' ) {
		global $wpdb;
		$existing = '' !== $token ? self::get( $token, $form_id ) : null;
		$row      = array(
			'form_id'    => (int) $form_id,
			'data'       => wp_json_encode( $data ),
			'step'       => max( 0, (int) $step ),
			'email'      => (string) $email,
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::ttl_days() * DAY_IN_SECONDS ),
		);
		if ( $existing ) {
			$wpdb->update( self::table(), $row, array( 'id' => $existing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $token;
		}
		$token             = wp_generate_password( 32, false, false );
		$row['token_hash'] = self::hash( $token );
		$row['created_at'] = current_time( 'mysql', true );
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $token;
	}

	/**
	 * Loads a live draft.
	 *
	 * @param string $token   Token.
	 * @param int    $form_id Form id.
	 * @return array|null { id, form_id, data, step, email }
	 */
	public static function get( $token, $form_id ) {
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', (string) $token ) ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s AND form_id = %d AND expires_at > %s", self::hash( $token ), (int) $form_id, current_time( 'mysql', true ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return null;
		}
		$data = json_decode( (string) $row['data'], true );
		return array(
			'id'      => (int) $row['id'],
			'form_id' => (int) $row['form_id'],
			'data'    => is_array( $data ) ? $data : array(),
			'step'    => (int) $row['step'],
			'email'   => (string) $row['email'],
		);
	}

	/**
	 * Deletes a draft (after the form is submitted).
	 *
	 * @param string $token   Token.
	 * @param int    $form_id Form id.
	 */
	public static function delete( $token, $form_id ) {
		global $wpdb;
		$draft = self::get( $token, $form_id );
		if ( $draft ) {
			$wpdb->delete( self::table(), array( 'id' => $draft['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Removes expired drafts.
	 */
	public static function prune() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s", current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Open drafts per form.
	 *
	 * @param int $form_id Form id.
	 * @return int
	 */
	public static function count( $form_id ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND expires_at > %s", (int) $form_id, current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
