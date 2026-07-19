<?php
/**
 * Retention — prunes old event rows on a daily cron.
 *
 * The retention window comes from the `utmat_retention_days` option
 * (default 180), filterable via the `utmat_retention_days` filter.
 * A value of 0 keeps events forever.
 *
 * @package UTMAT\DB
 */

namespace UTMAT\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Retention
 */
class Retention {

	/**
	 * Cron hook name for the daily prune event.
	 *
	 * @var string
	 */
	public const HOOK = 'utmat_prune_events';

	/**
	 * Option name holding the retention window in days.
	 *
	 * @var string
	 */
	private const OPTION = 'utmat_retention_days';

	/**
	 * Default retention window in days.
	 *
	 * @var int
	 */
	private const DEFAULT_DAYS = 180;

	/**
	 * Schedule the daily prune event if not already scheduled.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	/**
	 * Clear the scheduled prune event.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
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
		return (int) apply_filters( 'utmat_retention_days', (int) get_option( self::OPTION, self::DEFAULT_DAYS ) );
	}

	/**
	 * Delete event rows older than the retention window.
	 *
	 * Cron callback for the daily prune event. Expired plugin transients are
	 * always swept; the event-row delete does nothing when the retention
	 * window is 0 (keep forever).
	 *
	 * @since 1.0.0 Also sweeps expired plugin transients.
	 * @return void
	 */
	public static function prune(): void {
		global $wpdb;

		self::cleanup_expired_transients();

		$days = self::days();
		if ( $days <= 0 ) {
			return;
		}

		$table  = Schema::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

		// Deletes each expired _transient_timeout_utmat_* row together
		// with its matching _transient_utmat_* value row (the timeout
		// prefix is 19 characters, so the shared suffix starts at 20).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE a, b FROM {$wpdb->options} a
				 INNER JOIN {$wpdb->options} b
				         ON b.option_name = CONCAT( '_transient_', SUBSTRING( a.option_name, 20 ) )
				 WHERE a.option_name LIKE %s AND a.option_value < %d",
				$wpdb->esc_like( '_transient_timeout_utmat_' ) . '%',
				time()
			)
		);
	}
}
