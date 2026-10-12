<?php
/**
 * Plugin Name:       UTM & Attribution Tracker
 * Plugin URI:        https://github.com/mahlamusa/utm-attribution-tracker
 * Description:       Track UTM-tagged links, click IDs, and first/last-touch attribution across form leads and WooCommerce orders, with a live Chart.js dashboard.
 * Version:           1.0.0
 * Author:            Lindeni Mahlalela
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       utm-attribution-tracker
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Tested up to:      7.1
 * Requires PHP:      8.0
 *
 * @package UTMAT
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'UTM_ATTRIBUTION_TRACKER_VERSION', '1.0.0' );
define( 'UTM_ATTRIBUTION_TRACKER_FILE', __FILE__ );
define( 'UTM_ATTRIBUTION_TRACKER_PATH', plugin_dir_path( __FILE__ ) );
define( 'UTM_ATTRIBUTION_TRACKER_URL', plugin_dir_url( __FILE__ ) );
define( 'UTM_ATTRIBUTION_TRACKER_BASENAME', plugin_basename( __FILE__ ) );

// Load all classes via bootstrap (no Composer required).
require_once UTM_ATTRIBUTION_TRACKER_PATH . 'src/Bootstrap.php';
UTMAT\Bootstrap::load();

use UTMAT\Plugin;

/**
 * Returns the main plugin instance.
 *
 * @since 1.0.0
 * @return Plugin
 */
function utmat(): Plugin {
	return Plugin::instance();
}

/**
 * Boot the plugin singleton — `plugins_loaded` callback.
 *
 * @since 1.0.0
 * @return void
 */
function utmat_boot(): void {
	utmat();
}

add_action( 'plugins_loaded', 'utmat_boot' );

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
