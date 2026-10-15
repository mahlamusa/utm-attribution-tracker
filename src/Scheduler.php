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
 * Schedules heal themselves: heal() runs on `init` at most every 12 hours
 * and recreates any job whose setting is on but whose action is missing
 * (after a deactivate/reactivate, or on a multisite subsite that never ran
 * the activation hook). Scheduling clears the matching legacy WP-Cron event
 * from builds that used WP-Cron. When Action Scheduler is not loaded (a dev
 * checkout without `composer install`), jobs fall back to WP-Cron so
 * pruning and the weekly report never silently stop.
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
	 * Autoloaded option holding the next time heal() checks the schedules.
	 *
	 * Autoloaded (unlike a transient with an expiry), so the throttle check
	 * costs no query on ordinary requests.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const HEAL_OPTION = 'utmatra_schedules_next_check';

	/**
	 * Ensure a recurring action exists for a hook.
	 *
	 * A no-op when one is already scheduled; scheduled as unique, so two
	 * concurrent calls cannot create a duplicate. Called before Action
	 * Scheduler has initialised (e.g. from the activation hook), the call is
	 * deferred to `action_scheduler_init`. Without Action Scheduler the job
	 * falls back to a WP-Cron event (daily, or weekly for a week interval).
	 *
	 * @since 1.0.0
	 * @param string $hook        Action hook name.
	 * @param int    $interval    Recurrence interval in seconds (DAY_IN_SECONDS or WEEK_IN_SECONDS).
	 * @param int    $first_delay Seconds from now until the first run.
	 * @return void
	 */
	public static function ensure( string $hook, int $interval, int $first_delay = 0 ): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + $first_delay, WEEK_IN_SECONDS === $interval ? 'weekly' : 'daily', $hook );
			}
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

		if ( wp_next_scheduled( $hook ) ) {
			wp_clear_scheduled_hook( $hook );
		}

		if ( ! as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + $first_delay, $interval, $hook, array(), self::GROUP, true );
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
	 * Recreate any missing recurring job — hooked on `init`.
	 *
	 * Runs at most once per 12 hours: the next-check time lives in an
	 * autoloaded option (HEAL_OPTION), so ordinary requests pay no query.
	 * Activation deletes the option so the next request heals immediately.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function heal(): void {
		if ( (int) get_option( self::HEAL_OPTION, 0 ) > time() ) {
			return;
		}
		update_option( self::HEAL_OPTION, time() + 12 * HOUR_IN_SECONDS, true );

		Retention::schedule();
		if ( WeeklyEmail::enabled() ) {
			WeeklyEmail::schedule();
		}
	}
}
