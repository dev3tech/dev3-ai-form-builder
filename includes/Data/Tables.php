<?php
/**
 * Plugin tables (hooked into dev3fb_schema_sql).
 *
 * - dev3fb_entries:   one row per submission (also partial/pending-payment ones).
 * - dev3fb_entry_log: activity per entry (emails, webhooks, integrations, payments, notes, replies).
 * - dev3fb_drafts:    "save and continue later" drafts, addressed by a hashed resume token.
 * - dev3fb_events:    privacy-light funnel events (view/start/step/abandon/submit) for conversion insights.
 * - dev3fb_spam_log:  submissions the spam shield triggered on (hashes + redacted excerpt only).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Table names + CREATE statements.
 */
class Tables {

	/**
	 * Table name.
	 *
	 * @param string $name Short name (entries, entry_log, drafts, events, spam_log).
	 * @return string
	 */
	public static function name( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'dev3fb_' . $name;
	}

	/**
	 * All plugin tables.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array_map( array( __CLASS__, 'name' ), array( 'entries', 'entry_log', 'drafts', 'events', 'spam_log' ) );
	}

	/**
	 * Adds CREATE TABLE statements.
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$entries = self::name( 'entries' );
		$log     = self::name( 'entry_log' );
		$drafts  = self::name( 'drafts' );
		$events  = self::name( 'events' );
		$spam    = self::name( 'spam_log' );

		$sql[] = "CREATE TABLE {$entries} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'unread',
			starred tinyint(1) unsigned NOT NULL DEFAULT 0,
			data longtext NULL,
			meta longtext NULL,
			ai longtext NULL,
			lead_score tinyint(3) unsigned NULL,
			sentiment varchar(10) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			lang varchar(12) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			payment_status varchar(20) NOT NULL DEFAULT '',
			payment_total decimal(12,2) NOT NULL DEFAULT 0,
			payment_currency char(3) NOT NULL DEFAULT '',
			payment_provider varchar(10) NOT NULL DEFAULT '',
			payment_ref varchar(120) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY form_status (form_id,status),
			KEY created (created_at),
			KEY payment_ref (payment_ref)
		) {$charset};";

		$sql[] = "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(20) NOT NULL DEFAULT '',
			status varchar(10) NOT NULL DEFAULT 'ok',
			message text NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY entry (entry_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$drafts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			token_hash char(64) NOT NULL DEFAULT '',
			data longtext NULL,
			step smallint(5) unsigned NOT NULL DEFAULT 0,
			email varchar(190) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token_hash),
			KEY expires (expires_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			session char(32) NOT NULL DEFAULT '',
			event varchar(12) NOT NULL DEFAULT '',
			step smallint(5) unsigned NOT NULL DEFAULT 0,
			field varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY form_event (form_id,event,created_at),
			KEY session (session)
		) {$charset};";

		$sql[] = "CREATE TABLE {$spam} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			form_title varchar(190) NOT NULL DEFAULT '',
			action varchar(10) NOT NULL DEFAULT '',
			trigger_type varchar(20) NOT NULL DEFAULT '',
			status varchar(12) NOT NULL DEFAULT 'open',
			score tinyint(3) unsigned NOT NULL DEFAULT 0,
			abusive tinyint(1) unsigned NOT NULL DEFAULT 0,
			sentiment varchar(10) NOT NULL DEFAULT '',
			language varchar(12) NOT NULL DEFAULT '',
			reason varchar(255) NOT NULL DEFAULT '',
			excerpt varchar(255) NOT NULL DEFAULT '',
			content_hash char(64) NOT NULL DEFAULT '',
			email_hash char(64) NOT NULL DEFAULT '',
			ip_hash char(64) NOT NULL DEFAULT '',
			entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created (created_at),
			KEY form (form_id),
			KEY status_action (status,action)
		) {$charset};";

		return $sql;
	}

	/**
	 * Drops plugin tables (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		foreach ( self::all() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}
}
