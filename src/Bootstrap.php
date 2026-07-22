<?php
/**
 * Class autoloader for UTM & Attribution Tracker.
 *
 * Uses explicit require_once calls — no Composer vendor directory required.
 *
 * @package UTMAT
 */

namespace UTMAT;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap — loads all plugin classes in dependency order.
 */
class Bootstrap {

	/**
	 * Require all source files.
	 *
	 * @since 1.0.0 Added Diagnostics/Checks.php.
	 * @since 1.0.0 Added Integrations/Forms/NinjaForms.php.
	 * @return void
	 */
	public static function load(): void {
		$base = UTM_ATTRIBUTION_TRACKER_PATH . 'src/';

		require_once $base . 'DB/Schema.php';
		require_once $base . 'DB/Retention.php';
		require_once $base . 'Privacy/PrivacyPolicy.php';
		require_once $base . 'Tracking/TrafficSource.php';
		require_once $base . 'Tracking/LinkManager.php';
		require_once $base . 'Tracking/Attribution.php';
		require_once $base . 'Tracking/Tracker.php';
		require_once $base . 'Tracking/Rewrites.php';
		require_once $base . 'Reports/WeeklyEmail.php';
		require_once $base . 'Reports/ConversionWebhook.php';
		require_once $base . 'Rest/CollectController.php';
		require_once $base . 'Integrations/WooCommerce.php';
		require_once $base . 'Integrations/Forms/ContactForm7.php';
		require_once $base . 'Integrations/Forms/GravityForms.php';
		require_once $base . 'Integrations/Forms/NinjaForms.php';
		require_once $base . 'Admin/Dashboard.php';
		require_once $base . 'Diagnostics/Checks.php';
		require_once $base . 'Admin/RestController.php';
		require_once $base . 'Shortcode/TrackedLink.php';
		require_once $base . 'Plugin.php';
	}
}
