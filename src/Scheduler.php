<?php
/**
 * Scheduler — recurring background jobs on Action Scheduler.
 *
 * The plugin's recurring jobs (the daily retention prune and the optional
 * weekly report) run as Action Scheduler recurring actions in the
 * `utmatra` group, visible under Tools → Scheduled Actions. Action
 * Scheduler is bundled via Composer and loaded from the main plugin file;
 * when another active plugin (e.g. WooCommerce) ships a newer copy, that
 * copy is used instead.
 *
 * Schedules heal themselves: heal() runs on `action_scheduler_init` at most
 * every 12 hours and recreates any job whose setting is on but whose
 * action is missing (after a deactivate/reactivate, or on a multisite
 * subsite that never ran the activation hook). Each call also clears the
 * matching legacy WP-Cron event from builds that used WP-Cron.
 *
 * @package Utmatra
 */

namespace Utmatra;

use Utmatra\DB\Retention;
use Utmatra\Reports\WeeklyEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Scheduler
 *
 * @since 1.0.0
 */
class Scheduler {

	/**
	 * Action Scheduler group for every plugin job.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const GROUP = 'utmatra';

	/**
	 * Transient that throttles heal() to one check per 12 hours.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const HEAL_TRANSIENT = 'utmatra_schedules_checked';

	/**
	 * Ensure a recurring action exists for a hook.
	 *
	 * A no-op when one is already scheduled. Called before Action Scheduler
	 * has initialised (e.g. from the activation hook), the call is deferred
	 * to `action_scheduler_init`. Without Action Scheduler (a dev checkout
	 * without `composer install`) nothing is scheduled.
	 *
	 * @since 1.0.0
	 * @param string $hook        Action hook name.
	 * @param int    $interval    Recurrence interval in seconds.
	 * @param int    $first_delay Seconds from now until the first run.
	 * @return void
	 */
	public static function ensure( string $hook, int $interval, int $first_delay = 0 ): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! did_action( 'action_scheduler_init' ) ) {
			add_action(
				'action_scheduler_init',
				static function () use ( $hook, $interval, $first_delay ) {
					self::ensure( $hook, $interval, $first_delay );
				}
			);
			return;
		}

		wp_clear_scheduled_hook( $hook );

		if ( ! as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + $first_delay, $interval, $hook, array(), self::GROUP );
		}
	}

	/**
	 * Remove every scheduled action for a hook, plus any legacy cron event.
	 *
	 * @since 1.0.0
	 * @param string $hook Action hook name.
	 * @return void
	 */
	public static function clear( string $hook ): void {
		wp_clear_scheduled_hook( $hook );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
		}
	}

	/**
	 * Recreate any missing recurring job — hooked on `action_scheduler_init`.
	 *
	 * Runs at most once per 12 hours (HEAL_TRANSIENT), so it adds no query
	 * to ordinary requests. Activation deletes the transient so the next
	 * request heals immediately.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function heal(): void {
		if ( false !== get_transient( self::HEAL_TRANSIENT ) ) {
			return;
		}
		set_transient( self::HEAL_TRANSIENT, 1, 12 * HOUR_IN_SECONDS );

		Retention::schedule();
		if ( WeeklyEmail::enabled() ) {
			WeeklyEmail::schedule();
		}
	}
}
