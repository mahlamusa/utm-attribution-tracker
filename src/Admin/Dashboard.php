<?php
/**
 * Dashboard — registers the admin menu page and enqueues assets.
 *
 * If the companion SMTP plugin is active, registers as a submenu via the
 * `companion_smtp_admin_menu` action it provides for add-ons.
 * Otherwise registers as a standalone top-level menu.
 *
 * @package UTMAT\Admin
 */

namespace UTMAT\Admin;

use UTMAT\Reports\WeeklyEmail;
use UTMAT\Tracking\LinkManager;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard
 */
class Dashboard {

	private const PAGE_SLUG = 'utm-attribution-tracker';

	/**
	 * The hook suffix returned by add_menu_page / add_submenu_page.
	 * Used to target asset enqueuing precisely.
	 *
	 * @var string
	 */
	private static string $hook_suffix = '';

	/**
	 * Register as a standalone top-level menu page.
	 * Called when the companion SMTP plugin is not installed.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_menu(): void {
		self::$hook_suffix = (string) add_menu_page(
			__( 'UTM Tracker', 'utm-attribution-tracker' ),
			__( 'UTM Tracker', 'utm-attribution-tracker' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_page' ),
			'dashicons-chart-area',
			30
		);
	}

	/**
	 * Register as a submenu under the companion SMTP plugin.
	 * Called via the `companion_smtp_admin_menu` action when the companion SMTP plugin is active.
	 *
	 * the companion SMTP plugin fires this action as the last step of its own menu
	 * registration, after its "Settings" submenu — so the default
	 * (position-less) `add_submenu_page()` call would always append UTM
	 * Tracker below Settings. Insert it just above the last registered item
	 * instead, so it appears above Settings regardless of which of
	 * the companion plugin's optional submenus (Email Logs, Queue) are enabled.
	 *
	 * @since 1.0.0
	 * @param string $parent_slug The the companion SMTP plugin parent menu slug ('companion-smtp').
	 * @return void
	 */
	public static function register_as_submenu( string $parent_slug ): void {
		global $submenu;

		$position = null;
		if ( isset( $submenu[ $parent_slug ] ) && is_array( $submenu[ $parent_slug ] ) ) {
			$position = max( 0, count( $submenu[ $parent_slug ] ) - 1 );
		}

		self::$hook_suffix = (string) add_submenu_page(
			$parent_slug,
			__( 'UTM Tracker', 'utm-attribution-tracker' ),
			__( 'UTM Tracker', 'utm-attribution-tracker' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_page' ),
			$position
		);
	}

	/**
	 * Enqueue Chart.js and dashboard assets on this page only.
	 *
	 * Compares against the stored hook suffix so the check works regardless
	 * of whether the page is a top-level menu or a submenu.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( '' === self::$hook_suffix || self::$hook_suffix !== $hook ) {
			return;
		}

		// Chart.js — bundled locally (no CDN; required for wp.org and offline admin).
		wp_enqueue_script(
			'chartjs',
			UTM_ATTRIBUTION_TRACKER_URL . 'assets/js/vendor/chart.umd.min.js',
			array(),
			'4.4.0',
			true
		);

		// qrcode-generator (MIT) — bundled locally, dashboard page only.
		wp_enqueue_script(
			'utmat-qrcode',
			UTM_ATTRIBUTION_TRACKER_URL . 'assets/js/vendor/qrcode.min.js',
			array(),
			'2.0.4',
			true
		);

		// Version off the file's own mtime (not the plugin version) so a saved
		// edit always busts the browser cache, even between releases.
		$js_path  = UTM_ATTRIBUTION_TRACKER_PATH . 'assets/js/dashboard.js';
		$css_path = UTM_ATTRIBUTION_TRACKER_PATH . 'assets/css/dashboard.css';

		wp_enqueue_script(
			'utmat-dashboard',
			UTM_ATTRIBUTION_TRACKER_URL . 'assets/js/dashboard.js',
			array( 'chartjs', 'utmat-qrcode' ),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : UTM_ATTRIBUTION_TRACKER_VERSION,
			true
		);

		wp_enqueue_style(
			'utmat-dashboard',
			UTM_ATTRIBUTION_TRACKER_URL . 'assets/css/dashboard.css',
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : UTM_ATTRIBUTION_TRACKER_VERSION
		);

		// Pass config to JS.
		$links_raw = LinkManager::all();
		$links_out = array();
		foreach ( $links_raw as $id => $link ) {
			$links_out[] = array(
				'id'          => $id,
				'label'       => $link['label'] ?? $id,
				'redirectUrl' => LinkManager::redirect_url( $id ),
				'destination' => LinkManager::build_url( $link ),
			);
		}

		// WooCommerce revenue card config. The symbol is entity-decoded because
		// get_woocommerce_currency_symbol() returns HTML entities (&#36;) and
		// the JS renders it as plain text (Chart.js canvas labels).
		$woo_active = class_exists( 'WooCommerce' );

		wp_localize_script(
			'utmat-dashboard',
			'utmatData',
			array(
				'apiBase'        => rest_url( 'utmat/v1' ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'siteUrl'        => home_url( '/' ),
				'links'          => $links_out,
				'wooActive'      => $woo_active,
				'currencySymbol' => $woo_active && function_exists( 'get_woocommerce_currency_symbol' )
					? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
					: '',
				'settings'       => array(
					'retentionDays' => (int) get_option( 'utmat_retention_days', 180 ),
					'weeklyEmail'   => WeeklyEmail::enabled(),
				),
			)
		);
	}

	/**
	 * Render the dashboard page.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'utm-attribution-tracker' ) );
		}
		require_once UTM_ATTRIBUTION_TRACKER_PATH . 'views/dashboard.php';
	}

	/**
	 * Add a "UTM Tracker" action link on the Plugins screen.
	 *
	 * The link always points to `admin.php?page=utm-attribution-tracker` — the
	 * page URL is the same whether the plugin registered as a standalone
	 * top-level menu or as a the companion SMTP plugin submenu, since WP builds submenu
	 * URLs from the page slug, not the parent slug.
	 *
	 * @since 1.0.0
	 * @param string[] $links Existing plugin action links.
	 * @return string[]
	 */
	public static function add_plugin_action_link( array $links ): array {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'UTM Tracker', 'utm-attribution-tracker' )
		);
		return $links;
	}
}
