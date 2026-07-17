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
	 * Cron callback for the daily prune event. Does nothing when the
	 * retention window is 0 (keep forever).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function prune(): void {
		global $wpdb;

		$days = self::days();
		if ( $days <= 0 ) {
			return;
		}

		$table  = Schema::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE clicked_at < %s", $cutoff ) );
	}
}
