<?php
/**
 * Entry activity log (emails, webhooks, integrations, payments, notes, replies).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Log access.
 */
class Entry_Log {

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		return Tables::name( 'entry_log' );
	}

	/**
	 * Adds a line.
	 *
	 * @param int    $entry_id Entry id.
	 * @param string $type     email|webhook|mailchimp|brevo|hubspot|sheets|zapier|payment|note|reply|ai|status.
	 * @param string $status   ok|error|info.
	 * @param string $message  Message.
	 * @return int
	 */
	public static function add( $entry_id, $type, $status, $message ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'entry_id'   => (int) $entry_id,
				'type'       => substr( sanitize_key( $type ), 0, 20 ),
				'status'     => in_array( $status, array( 'ok', 'error', 'info' ), true ) ? $status : 'info',
				'message'    => mb_substr( wp_strip_all_tags( (string) $message ), 0, 2000 ),
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Lines for an entry (newest first).
	 *
	 * @param int $entry_id Entry id.
	 * @return array
	 */
	public static function for_entry( $entry_id ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE entry_id = %d ORDER BY id DESC LIMIT 200", (int) $entry_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$user  = (int) $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
			$out[] = array(
				'id'         => (int) $row['id'],
				'type'       => $row['type'],
				'status'     => $row['status'],
				'message'    => $row['message'],
				'user'       => $user ? $user->display_name : '',
				'created_at' => mysql_to_rfc3339( $row['created_at'] ),
			);
		}
		return $out;
	}
}
