<?php
/**
 * Dashboard — registers the admin menu page and enqueues assets.
 *
 * @package Utmatra\Admin
 */

namespace Utmatra\Admin;

use Utmatra\Reports\ConversionWebhook;
use Utmatra\Reports\WeeklyEmail;
use Utmatra\Tracking\Attribution;
use Utmatra\Tracking\LinkManager;
use Utmatra\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard
 *
 * @since 1.0.0
 */
class Dashboard {

	private const PAGE_SLUG = 'utmatra';

	/**
	 * Admin menu icon: the Utmatra "UTM" route (a first-touch ring, link
	 * dots where the letters join, a conversion dot), as a base64 SVG
	 * data URI.
	 *
	 * Single fill colour so core's svg-painter recolours it for the default,
	 * hover and current menu states.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCIgd2lkdGg9IjIwIiBoZWlnaHQ9IjIwIiBmaWxsPSIjYTdhYWFkIj48ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgwLjIgMC4zNSkiPjxwYXRoIGZpbGwtcnVsZT0iZXZlbm9kZCIgZD0iTTEuOCAyLjZhMS40IDEuNCAwIDEgMSAwIDIuOCAxLjQgMS40IDAgMCAxIDAtMi44em0wIC43NWEuNjUuNjUgMCAxIDAgMCAxLjMuNjUuNjUgMCAwIDAgMC0xLjN6Ii8+PHBhdGggZD0iTTEuNCA1LjNoLjhWMTRoLS44eiIvPjxwYXRoIGQ9Ik0xLjQgMTRhMi42IDIuNiAwIDAgMCA1LjIgMGgtLjhhMS44IDEuOCAwIDAgMS0zLjYgMHoiLz48cGF0aCBkPSJNNS44IDRoLjh2MTBoLS44eiIvPjxwYXRoIGQ9Ik01LjggMy42aDcuMnYuOEg1Ljh6Ii8+PHBhdGggZD0iTTkgMy42aC44VjE3SDl6Ii8+PHBhdGggZD0iTTEyLjIgMy42aC44VjE3aC0uOHpNMTcuNCAzLjZoLjhWMTdoLS44eiIvPjxwYXRoIGQ9Ik0xMi4yIDMuNmguOWwyLjEgNSAyLjEtNWguOWwtMyA3LjF6Ii8+PGNpcmNsZSBjeD0iNi4yIiBjeT0iNCIgcj0iMSIvPjxjaXJjbGUgY3g9IjEyLjYiIGN5PSI0IiByPSIxIi8+PGNpcmNsZSBjeD0iMTcuOCIgY3k9IjQiIHI9IjEuMyIvPjwvZz48L3N2Zz4K';

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
	 * @since 1.0.0 Uses the plugin's own SVG menu icon instead of a dashicon.
	 * @return void
	 */
	public static function register_menu(): void {
		self::$hook_suffix = (string) add_menu_page(
			__( 'Utmatra - UTM Attribution and Tracking', 'utmatra-utm-attribution-and-tracking' ),
			__( 'Utmatra', 'utmatra-utm-attribution-and-tracking' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_page' ),
			self::MENU_ICON,
			30
		);
	}

	/**
	 * Enqueue Chart.js and dashboard assets on this page only.
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
			UTMATRA_URL . 'assets/js/vendor/chart.umd.min.js',
			array(),
			'4.5.1',
			true
		);

		// qrcode-generator (MIT) — bundled locally, dashboard page only.
		wp_enqueue_script(
			'utmatra-qrcode',
			UTMATRA_URL . 'assets/js/vendor/qrcode.min.js',
			array(),
			'2.0.4',
			true
		);

		// Version off the file's own mtime (not the plugin version) so a saved
		// edit always busts the browser cache, even between releases.
		$js_path  = UTMATRA_PATH . 'assets/js/dashboard.js';
		$css_path = UTMATRA_PATH . 'assets/css/dashboard.css';

		wp_enqueue_script(
			'utmatra-dashboard',
			UTMATRA_URL . 'assets/js/dashboard.js',
			array( 'chartjs', 'utmatra-qrcode' ),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : UTMATRA_VERSION,
			true
		);

		wp_enqueue_style(
			'utmatra-dashboard',
			UTMATRA_URL . 'assets/css/dashboard.css',
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : UTMATRA_VERSION
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
			'utmatra-dashboard',
			'utmatraData',
			array(
				'apiBase'        => rest_url( 'utmatra/v1' ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'siteUrl'        => home_url( '/' ),
				'links'          => $links_out,
				'wooActive'      => $woo_active,
				'currencySymbol' => $woo_active && function_exists( 'get_woocommerce_currency_symbol' )
					? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
					: '',
				'settings'       => array(
					'retentionDays'   => (int) get_option( 'utmatra_retention_days', 180 ),
					'weeklyEmail'     => WeeklyEmail::enabled(),
					'cookieDays'      => (int) get_option( 'utmatra_cookie_days', 90 ),
					'webhookUrl'      => ConversionWebhook::url(),
					'webhookPreset'   => ConversionWebhook::preset(),
					'webhookOrders'   => ConversionWebhook::events()['orders'],
					'webhookLeads'    => ConversionWebhook::events()['leads'],
					'consentCategory' => Attribution::consent_category(),
					'trackVisits'     => 1 === (int) get_option( 'utmatra_track_visits', 1 ),
				),
				'cachePlugin'    => self::detect_cache_plugin(),
				'webhookSecret'  => ConversionWebhook::secret(),
				'webhookLast'    => ConversionWebhook::last_delivery(),
				'customParams'   => Tracker::custom_params(),
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
	 * @since 1.0.0
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'utmatra-utm-attribution-and-tracking' ) );
		}
		require_once UTMATRA_PATH . 'views/dashboard.php';
	}

	/**
	 * Add a "Utmatra" action link on the Plugins screen.
	 *
	 * The link always points to `admin.php?page=utmatra` — the
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
			esc_html__( 'Utmatra', 'utmatra-utm-attribution-and-tracking' )
		);
		return $links;
	}
}
