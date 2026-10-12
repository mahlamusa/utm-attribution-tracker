<?php
/**
 * Dashboard — registers the admin menu page and enqueues assets.
 *
 * @package UTMAT\Admin
 */

namespace UTMAT\Admin;

use UTMAT\Reports\ConversionWebhook;
use UTMAT\Reports\WeeklyEmail;
use UTMAT\Tracking\Attribution;
use UTMAT\Tracking\LinkManager;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard
 *
 * @since 1.0.0 Localizes the consent category setting.
 * @since 1.0.0 Localizes the untagged-visit tracking setting.
 */
class Dashboard {

	private const PAGE_SLUG = 'utm-attribution-tracker';

	/**
	 * The hook suffix returned by add_menu_page.
	 * Used to target asset enqueuing precisely.
	 *
	 * @var string
	 */
	private static string $hook_suffix = '';

	/**
	 * Register the top-level menu page.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_menu(): void {
		self::$hook_suffix = (string) add_menu_page(
			__( 'UTM & Attribution Tracker', 'utm-attribution-tracker' ),
			__( 'UTM & Attribution', 'utm-attribution-tracker' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_page' ),
			'dashicons-chart-area',
			30
		);
	}

	/**
	 * Enqueue Chart.js and dashboard assets on this page only.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Localizes the consent category setting.
	 * @since 1.0.0 Localizes the untagged-visit tracking setting.
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
					'retentionDays'   => (int) get_option( 'utmat_retention_days', 180 ),
					'weeklyEmail'     => WeeklyEmail::enabled(),
					'cookieDays'      => (int) get_option( 'utmat_cookie_days', 90 ),
					'webhookUrl'      => ConversionWebhook::url(),
					'consentCategory' => Attribution::consent_category(),
					'trackVisits'     => 1 === (int) get_option( 'utmat_track_visits', 1 ),
				),
				'cachePlugin'    => self::detect_cache_plugin(),
				'webhookSecret'  => ConversionWebhook::secret(),
				'webhookLast'    => ConversionWebhook::last_delivery(),
			)
		);
	}

	/**
	 * Detect a known full-page cache plugin by its telltale constant or class.
	 *
	 * Purely informational — the plugin already tracks cached pages correctly
	 * via the front-end beacon, but caching is the single most common cause
	 * of "why isn't anything being recorded?" support requests for
	 * server-side-only trackers, so the dashboard reassures the admin that
	 * their specific cache is accounted for instead of leaving them to
	 * wonder. Detection is a fixed allow-list of well-known plugins; an
	 * unrecognised cache returns an empty string and the notice is skipped.
	 *
	 * @since 1.0.0 Made public so Diagnostics\Checks::caching() can reuse the same allow-list.
	 * @return string The detected plugin's display name, or '' when none matched.
	 */
	public static function detect_cache_plugin(): string {
		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			return 'WP Rocket';
		}
		if ( defined( 'LSCWP_V' ) ) {
			return 'LiteSpeed Cache';
		}
		if ( defined( 'W3TC' ) ) {
			return 'W3 Total Cache';
		}
		if ( function_exists( 'wpsc_init' ) ) {
			return 'WP Super Cache';
		}
		if ( class_exists( 'Cache_Enabler' ) ) {
			return 'Cache Enabler';
		}
		if ( class_exists( 'WpFastestCache' ) ) {
			return 'WP Fastest Cache';
		}
		if ( defined( 'SG_CACHEPRESS_VERSION' ) ) {
			return 'SiteGround Optimizer';
		}
		if ( defined( 'WPO_VERSION' ) ) {
			return 'WP-Optimize';
		}

		return '';
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
	 * Add a "UTM & Attribution" action link on the Plugins screen.
	 *
	 * The link always points to `admin.php?page=utm-attribution-tracker` — the
	 * page URL is the same whether the plugin registered as a standalone
	 * top-level menu or as a submenu, since WP builds submenu
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
			esc_html__( 'UTM & Attribution', 'utm-attribution-tracker' )
		);
		return $links;
	}
}
