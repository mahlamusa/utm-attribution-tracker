<?php
/**
 * Class autoloader for Utmatra.
 *
 * Plugin classes load via explicit require_once calls (no Composer
 * autoloader). The one Composer dependency, Action Scheduler, is loaded
 * from the main plugin file; without it, recurring jobs fall back to WP-Cron.
 *
 * @package Utmatra
 */

namespace Utmatra;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap — loads all plugin classes in dependency order.
 *
 * @since 1.0.0
 */
class Bootstrap {

	/**
	 * Require all source files.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function load(): void {
		$base = UTMATRA_PATH . 'src/';

		require_once $base . 'DB/Schema.php';
		require_once $base . 'DB/Retention.php';
		require_once $base . 'Scheduler.php';
		require_once $base . 'Privacy/PrivacyPolicy.php';
		require_once $base . 'Tracking/TrafficSource.php';
		require_once $base . 'Tracking/LinkManager.php';
		require_once $base . 'Tracking/Attribution.php';
		require_once $base . 'Tracking/Tracker.php';
		require_once $base . 'Tracking/Rewrites.php';
		require_once $base . 'Reports/WeeklyEmail.php';
		require_once $base . 'Reports/ConversionWebhook.php';
		require_once $base . 'Insights/Recorder.php';
		require_once $base . 'Insights/Insights.php';
		require_once $base . 'Rest/CollectController.php';
		require_once $base . 'Integrations/WooCommerce.php';
		require_once $base . 'Integrations/Forms/ContactForm7.php';
		require_once $base . 'Integrations/Forms/GravityForms.php';
		require_once $base . 'Integrations/Forms/NinjaForms.php';
		require_once $base . 'Integrations/Forms/Elementor.php';
		require_once $base . 'Integrations/Forms/WPForms.php';
		require_once $base . 'Integrations/Forms/FluentForms.php';
		require_once $base . 'Integrations/Forms/Formidable.php';
		require_once $base . 'Integrations/Forms/Forminator.php';
		require_once $base . 'Integrations/Forms/Bricks.php';
		require_once $base . 'Admin/Dashboard.php';
		require_once $base . 'Admin/DashboardWidget.php';
		require_once $base . 'Diagnostics/Checks.php';
		require_once $base . 'Admin/RestController.php';
		require_once $base . 'Shortcode/TrackedLink.php';
		require_once $base . 'Plugin.php';
	}
}
