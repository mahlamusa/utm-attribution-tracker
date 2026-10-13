<?php
/**
 * DB Schema — creates and upgrades the utmat_events table.
 *
 * @since 1.0.0
 *
 * @package UTMAT\DB
 */

namespace UTMAT\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Schema
 *
 * @since 1.0.0
 */
class Schema {

	private const DB_VERSION_OPTION = 'utmat_db_version';
	private const DB_VERSION        = '5';

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
	 * Running dbDelta against a version-4 table only adds what is missing: it
	 * appends the four newer columns and their keys without touching
	 * existing rows. The `custom` and `meta` TEXT columns carry no DEFAULT
	 * clause on purpose: TEXT columns cannot have one on older MySQL, and
	 * dbDelta would otherwise try to re-alter them on every run.
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
  gclid VARCHAR(255) NOT NULL DEFAULT '',
  fbclid VARCHAR(255) NOT NULL DEFAULT '',
  msclkid VARCHAR(255) NOT NULL DEFAULT '',
  ttclid VARCHAR(255) NOT NULL DEFAULT '',
  li_fat_id VARCHAR(255) NOT NULL DEFAULT '',
  twclid VARCHAR(255) NOT NULL DEFAULT '',
  is_conversion TINYINT(1) NOT NULL DEFAULT 0,
  touch VARCHAR(10) NOT NULL DEFAULT '',
  order_total DECIMAL(19,4) NULL DEFAULT NULL,
  device VARCHAR(10) NOT NULL DEFAULT '',
  ip_hash VARCHAR(64) NOT NULL DEFAULT '',
  user_agent VARCHAR(500) NOT NULL DEFAULT '',
  referrer VARCHAR(2083) NOT NULL DEFAULT '',
  clicked_at DATETIME NULL DEFAULT NULL,
  channel VARCHAR(20) NOT NULL DEFAULT '',
  visitor_id VARCHAR(36) NOT NULL DEFAULT '',
  custom TEXT NULL,
  meta TEXT NULL,
  PRIMARY KEY  (id),
  KEY link_id (link_id(64)),
  KEY utm_campaign (utm_campaign(100)),
  KEY clicked_at (clicked_at),
  KEY conv_time (is_conversion, clicked_at),
  KEY visitor_id (visitor_id),
  KEY channel (channel)
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
		$table = esc_sql( self::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema/maintenance statement
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		delete_option( self::DB_VERSION_OPTION );
	}
}
