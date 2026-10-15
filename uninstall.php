<?php
/**
 * Utmatra — Uninstall
 *
 * Drops the events table and deletes all plugin options when the plugin is
 * deleted via the WordPress admin. Only runs on uninstall, not deactivation.
 * On multisite every site is cleaned up individually.
 *
 * @package Utmatra
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the events table, options, transients, and cron hook for the current site.
 *
 * @since 1.0.0
 * @return void
 */
function utmatra_uninstall_site(): void {
	global $wpdb;

	// Drop the events table.
	$table = esc_sql( $wpdb->prefix . 'utmatra_events' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema/maintenance statement
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

	// Delete all plugin options.
	delete_option( 'utmatra_db_version' );
	delete_option( 'utmatra_links' );
	delete_option( 'utmatra_salt' );
	delete_option( 'utmatra_retention_days' );
	delete_option( 'utmatra_weekly_email' );
	delete_option( 'utmatra_cookie_days' );
	delete_option( 'utmatra_custom_params' );
	delete_option( 'utmatra_consent_category' );
	delete_option( 'utmatra_track_visits' );
	delete_option( 'utmatra_webhook_url' );
	delete_option( 'utmatra_webhook_secret' );
	delete_option( 'utmatra_webhook_last' );
	delete_option( 'utmatra_webhook_preset' );
	delete_option( 'utmatra_webhook_events' );
	delete_option( 'utmatra_recent_leads' );
	delete_option( 'utmatra_presets' );

	// Delete any transients with the plugin prefix.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_utmatra_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_utmatra_' ) . '%'
		)
	);

	// Remove the retention and weekly report jobs (and any legacy cron events).
	foreach ( array( 'utmatra_prune_events', 'utmatra_weekly_report' ) as $utmatra_hook ) {
		wp_clear_scheduled_hook( $utmatra_hook );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $utmatra_hook, array(), 'utmatra' );
		}
	}

	// The plugin is not loaded during uninstall, so Action Scheduler is only
	// available when another plugin (e.g. WooCommerce) ships it; otherwise
	// delete the pending rows directly when its tables exist.
	if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
		$utmatra_as_table = $wpdb->prefix . 'actionscheduler_actions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $utmatra_as_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $utmatra_as_table ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$utmatra_as_table}` WHERE hook IN ( %s, %s ) AND status = %s", 'utmatra_prune_events', 'utmatra_weekly_report', 'pending' ) );
		}
	}
}

if ( is_multisite() ) {
	$utmatra_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $utmatra_site_ids as $utmatra_site_id ) {
		switch_to_blog( $utmatra_site_id );
		utmatra_uninstall_site();
		restore_current_blog();
	}
} else {
	utmatra_uninstall_site();
}
