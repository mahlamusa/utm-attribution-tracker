<?php
/**
 * DB Schema — creates and upgrades the utmat_events table.
 *
 * @package UTMAT\DB
 */

namespace UTMAT\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Schema
 */
class Schema {

	private const DB_VERSION_OPTION = 'utmat_db_version';
	private const DB_VERSION        = '1';

	/**
	 * Create / upgrade the events table on activation.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function install(): void {
		self::run_dbdelta();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Run a lightweight version check and upgrade if needed.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = get_option( self::DB_VERSION_OPTION, '0' );
		if ( version_compare( (string) $installed, self::DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	/**
	 * Returns the fully-qualified table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'utmat_events';
	}

	/**
	 * Run dbDelta to create or update the table.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function run_dbdelta(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::table();

		// Two spaces before column names, one before KEY — required by dbDelta.
		$sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  link_id VARCHAR(64) NOT NULL DEFAULT '',
  utm_source VARCHAR(100) NOT NULL DEFAULT '',
  utm_medium VARCHAR(100) NOT NULL DEFAULT '',
  utm_campaign VARCHAR(100) NOT NULL DEFAULT '',
  utm_id VARCHAR(100) NOT NULL DEFAULT '',
  utm_term VARCHAR(100) NOT NULL DEFAULT '',
  utm_content VARCHAR(255) NOT NULL DEFAULT '',
  destination VARCHAR(2083) NOT NULL DEFAULT '',
  ip_hash VARCHAR(64) NOT NULL DEFAULT '',
  user_agent VARCHAR(500) NOT NULL DEFAULT '',
  referrer VARCHAR(2083) NOT NULL DEFAULT '',
  clicked_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY link_id (link_id(64)),
  KEY utm_campaign (utm_campaign(100)),
  KEY clicked_at (clicked_at)
) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Drop the events table.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		delete_option( self::DB_VERSION_OPTION );
	}
}
