<?php
/**
 * UTM & Attribution Tracker — Uninstall
 *
 * Drops the events table and deletes all plugin options when the plugin is
 * deleted via the WordPress admin. Only runs on uninstall, not deactivation.
 *
 * @package UTMAT
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Drop the events table.
$table = $wpdb->prefix . 'utmat_events';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

// Delete all plugin options.
delete_option( 'utmat_db_version' );
delete_option( 'utmat_links' );
