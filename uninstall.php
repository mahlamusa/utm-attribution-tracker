<?php
/**
 * UTM & Attribution Tracker — Uninstall
 *
 * Drops the events table and deletes all plugin options when the plugin is
 * deleted via the WordPress admin. Only runs on uninstall, not deactivation.
 * On multisite every site is cleaned up individually.
 *
 * @package UTMAT
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the events table, options, transients, and cron hook for the current site.
 *
 * @since 1.0.0 Table name passed through esc_sql() before interpolation.
 * @since 1.0.0 Also deletes the custom parameters option.
 * @since 1.0.0 Also deletes the consent category option.
 * @return void
 */
function utmat_uninstall_site(): void {
	global $wpdb;

	// Drop the events table.
	$table = esc_sql( $wpdb->prefix . 'utmat_events' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema/maintenance statement
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

	// Delete all plugin options.
	delete_option( 'utmat_db_version' );
	delete_option( 'utmat_links' );
	delete_option( 'utmat_salt' );
	delete_option( 'utmat_retention_days' );
	delete_option( 'utmat_weekly_email' );
	delete_option( 'utmat_cookie_days' );
	delete_option( 'utmat_custom_params' );
	delete_option( 'utmat_consent_category' );

	// Delete any transients with the plugin prefix.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_utmat_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_utmat_' ) . '%'
		)
	);

	// Clear the retention and weekly report cron events.
	wp_clear_scheduled_hook( 'utmat_prune_events' );
	wp_clear_scheduled_hook( 'utmat_weekly_report' );
}

if ( is_multisite() ) {
	$utmat_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $utmat_site_ids as $utmat_site_id ) {
		switch_to_blog( $utmat_site_id );
		utmat_uninstall_site();
		restore_current_blog();
	}
} else {
	utmat_uninstall_site();
}
