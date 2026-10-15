<?php
/**
 * Retention — prunes old event rows on a daily Action Scheduler action.
 *
 * The retention window comes from the `utmatra_retention_days` option
 * (default 180), filterable via the `utmatra_retention_days` filter.
 * A value of 0 keeps events forever.
 *
 * @package Utmatra\DB
 */

namespace Utmatra\DB;

use Utmatra\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Retention
 */
class Retention {

	/**
	 * Action hook name for the daily prune job.
	 *
	 * @var string
	 */
	public const HOOK = 'utmatra_prune_events';

	/**
	 * Option name holding the retention window in days.
	 *
	 * @var string
	 */
	private const OPTION = 'utmatra_retention_days';

	/**
	 * Default retention window in days.
	 *
	 * @var int
	 */
	private const DEFAULT_DAYS = 180;

	/**
	 * Schedule the daily prune job if not already scheduled.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Uses Action Scheduler instead of WP-Cron.
	 * @return void
	 */
	public static function schedule(): void {
		Scheduler::ensure( self::HOOK, DAY_IN_SECONDS );
	}

	/**
	 * Remove the scheduled prune job.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Uses Action Scheduler instead of WP-Cron.
	 * @return void
	 */
	public static function unschedule(): void {
		Scheduler::clear( self::HOOK );
	}

	/**
	 * Return the retention window in days.
	 *
	 * 0 means keep events forever.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public static function days(): int {
		/**
		 * Filter the event retention window in days.
		 *
		 * @since 1.0.0
		 * @param int $days Retention window in days. 0 keeps events forever.
		 */
		return (int) apply_filters( 'utmatra_retention_days', (int) get_option( self::OPTION, self::DEFAULT_DAYS ) );
	}

	/**
	 * Delete event rows older than the retention window.
	 *
	 * Scheduled-action callback for the daily prune job. Expired plugin
	 * transients are always swept; the event-row delete does nothing when
	 * the retention window is 0 (keep forever).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function prune(): void {
		global $wpdb;

		self::cleanup_expired_transients();

		$days = self::days();
		if ( $days <= 0 ) {
			return;
		}

		$table  = esc_sql( Schema::table() );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema/maintenance statement
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE clicked_at < %s", $cutoff ) );
	}

	/**
	 * Delete expired plugin transients from the options table.
	 *
	 * The dedup and rate-limit transients are high-cardinality (one per
	 * visitor/param combination) and WordPress core only sweeps expired
	 * transients on version upgrades, so without this daily sweep they
	 * accumulate in wp_options on sites without a persistent object cache.
	 * No-ops when an external object cache handles transients.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function cleanup_expired_transients(): void {
		global $wpdb;

		if ( wp_using_ext_object_cache() ) {
			return;
		}

		// Deletes each expired _transient_timeout_utmatra_* row together
		// with its matching _transient_utmatra_* value row (the timeout
		// prefix is 19 characters, so the shared suffix starts at 20).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE a, b FROM {$wpdb->options} a
				 INNER JOIN {$wpdb->options} b
				         ON b.option_name = CONCAT( '_transient_', SUBSTRING( a.option_name, 20 ) )
				 WHERE a.option_name LIKE %s AND a.option_value < %d",
				$wpdb->esc_like( '_transient_timeout_utmatra_' ) . '%',
				time()
			)
		);
	}
}
