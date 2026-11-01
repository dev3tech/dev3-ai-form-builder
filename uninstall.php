<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiFormBuilder
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3fb_settings = get_option( 'dev3fb_settings', array() );
if ( empty( $dev3fb_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
$dev3fb_tables = array( 'dev3fb_usage', 'dev3fb_core_cache', 'dev3fb_core_jobs', 'dev3fb_core_embeddings', 'dev3fb_entries', 'dev3fb_entry_log', 'dev3fb_drafts', 'dev3fb_events', 'dev3fb_spam_log' );
foreach ( $dev3fb_tables as $dev3fb_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3fb_table}" );
}
$dev3fb_forms = get_posts(
	array(
		'post_type'      => 'dev3fb_form',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $dev3fb_forms as $dev3fb_form ) {
	wp_delete_post( $dev3fb_form, true );
}
foreach ( array( 'dev3fb_settings', 'dev3fb_secrets', 'dev3fb_allowlist', 'dev3fb_db_version' ) as $dev3fb_option ) {
	delete_option( $dev3fb_option );
}
delete_transient( 'dev3fb_models' );
wp_unschedule_hook( 'dev3fb_run_job' );
wp_unschedule_hook( 'dev3fb_daily' );
// Uploaded files.
$dev3fb_uploads = wp_upload_dir( null, false );
$dev3fb_dir     = trailingslashit( $dev3fb_uploads['basedir'] ) . 'dev3fb';
if ( is_dir( $dev3fb_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;
	if ( $wp_filesystem ) {
		$wp_filesystem->delete( $dev3fb_dir, true );
	}
}
