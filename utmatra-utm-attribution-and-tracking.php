<?php
/**
 * Plugin Name:       Utmatra - UTM Attribution and Tracking
 * Plugin URI:        https://github.com/mahlamusa/utmatra-utm-attribution-and-tracking
 * Description:       Track UTM-tagged links, click IDs, and first/last-touch attribution across form leads and WooCommerce orders, with a live Chart.js dashboard.
 * Version:           1.0.0
 * Author:            Lindeni Mahlalela
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       utmatra-utm-attribution-and-tracking
 * Domain Path:       /languages
 * Requires at least: 6.9
 * Requires PHP:      8.0
 *
 * @package Utmatra
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'UTMATRA_VERSION', '1.0.0' );
define( 'UTMATRA_FILE', __FILE__ );
define( 'UTMATRA_PATH', plugin_dir_path( __FILE__ ) );
define( 'UTMATRA_URL', plugin_dir_url( __FILE__ ) );
define( 'UTMATRA_BASENAME', plugin_basename( __FILE__ ) );

// Action Scheduler (bundled via Composer) runs the recurring jobs. It must
// load with the plugin file so it can register itself and use the newest
// copy among all active plugins (WooCommerce ships one too).
if ( file_exists( UTMATRA_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once UTMATRA_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

// Load all classes via bootstrap.
require_once UTMATRA_PATH . 'src/Bootstrap.php';
Utmatra\Bootstrap::load();

use Utmatra\Plugin;

/**
 * Returns the main plugin instance.
 *
 * @since 1.0.0
 * @return Plugin
 */
function utmatra(): Plugin {
	return Plugin::instance();
}

/**
 * Boot the plugin singleton — `plugins_loaded` callback.
 *
 * @since 1.0.0
 * @return void
 */
function utmatra_boot(): void {
	utmatra();
}

add_action( 'plugins_loaded', 'utmatra_boot' );

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
