<?php
/**
 * Plugin singleton — wires all hooks together.
 *
 * @package UTMAT
 */

namespace UTMAT;

use UTMAT\DB\Schema;
use UTMAT\DB\Retention;
use UTMAT\Privacy\PrivacyPolicy;
use UTMAT\Reports\WeeklyEmail;
use UTMAT\Tracking\Attribution;
use UTMAT\Tracking\Tracker;
use UTMAT\Tracking\TrafficSource;
use UTMAT\Tracking\LinkManager;
use UTMAT\Tracking\Rewrites;
use UTMAT\Admin\Dashboard;
use UTMAT\Admin\DashboardWidget;
use UTMAT\Admin\RestController;
use UTMAT\Integrations\WooCommerce;
use UTMAT\Integrations\Forms\ContactForm7;
use UTMAT\Integrations\Forms\Elementor;
use UTMAT\Integrations\Forms\GravityForms;
use UTMAT\Integrations\Forms\NinjaForms;
use UTMAT\Rest\CollectController;
use UTMAT\Shortcode\TrackedLink;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin — the singleton returned by Plugin::instance().
 *
 * @since 1.0.0 The beacon config now carries the parameter whitelist.
 * @since 1.0.0 The beacon config carries the consent gating keys and the plugin registers with the WP Consent API.
 * @since 1.0.0 The beacon config carries the visit-tracking switch and the referrer host lists.
 */
class Plugin {

	/**
	 * The singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Return (and create) the plugin singleton.
	 *
	 * @since 1.0.0
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register all WordPress hooks.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Added the Ninja Forms adapter to the form-plugin wiring.
	 * @since 1.0.0 Added the recent-leads Dashboard widget.
	 * @since 1.0.0 Dropped the manual text-domain load; WordPress.org loads translations for the plugin slug automatically.
	 * @since 1.0.0 Registers the plugin and its cookie with the WP Consent API.
	 * @return void
	 */
	private function init_hooks(): void {
		// DB version check on every load. This runs during `plugins_loaded`
		// itself, so hook `init` (priority 0) rather than `plugins_loaded`.
		add_action( 'init', array( Schema::class, 'maybe_upgrade' ), 0 );

		// Register utmat_track query var.
		add_filter( 'query_vars', array( Tracker::class, 'register_query_vars' ) );

		// Track incoming requests at priority 1 (before most other code).
		add_action( 'init', array( Tracker::class, 'handle_incoming_request' ), 1 );

		// Pretty short links: register /go/{slug}/ and bridge parsed requests
		// into the redirect handler (the query var only exists post-init).
		add_action( 'init', array( Rewrites::class, 'add_rules' ) );
		add_action( 'parse_request', array( Rewrites::class, 'handle_pretty_request' ) );

		// Daily retention pruning.
		add_action( Retention::HOOK, array( Retention::class, 'prune' ) );

		// Weekly email report (cron is only scheduled while the toggle is on).
		add_action( WeeklyEmail::HOOK, array( WeeklyEmail::class, 'send' ) );

		// Suggested privacy policy text.
		add_action( 'admin_init', array( PrivacyPolicy::class, 'register' ) );

		// WP Consent API: declare compliance (the filter is inert without the
		// API) and describe the attribution cookie so consent banners can list it.
		add_filter( 'wp_consent_api_registered_' . UTM_ATTRIBUTION_TRACKER_BASENAME, '__return_true' );
		add_action( 'init', array( Attribution::class, 'register_cookie_info' ) );

		// REST API.
		add_action( 'rest_api_init', array( RestController::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( CollectController::class, 'register_routes' ) );

		// Front-end tracking beacon.
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_beacon' ) );

		// WooCommerce order attribution. This runs during `plugins_loaded`
		// priority 10 and Woo may load after us, so defer the class_exists
		// check to priority 20 — maybe_init() adds zero hooks without Woo.
		add_action( 'plugins_loaded', array( WooCommerce::class, 'maybe_init' ), 20 );

		// Form plugin adapters — same deferred detection as WooCommerce;
		// each maybe_init() adds zero hooks when its plugin is absent.
		add_action( 'plugins_loaded', array( ContactForm7::class, 'maybe_init' ), 20 );
		add_action( 'plugins_loaded', array( GravityForms::class, 'maybe_init' ), 20 );
		add_action( 'plugins_loaded', array( NinjaForms::class, 'maybe_init' ), 20 );
		add_action( 'plugins_loaded', array( Elementor::class, 'maybe_init' ), 20 );

		// Admin dashboard.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( Dashboard::class, 'register_menu' ) );

			add_action( 'admin_enqueue_scripts', array( Dashboard::class, 'enqueue_assets' ) );

			// Recent-leads widget on wp-admin → Dashboard → Home.
			add_action( 'wp_dashboard_setup', array( DashboardWidget::class, 'register' ) );

			// "UTM & Attribution" row link on the Plugins screen.
			add_filter( 'plugin_action_links_' . UTM_ATTRIBUTION_TRACKER_BASENAME, array( Dashboard::class, 'add_plugin_action_link' ) );
		}

		// Shortcode: [utmat_link].
		add_shortcode( 'utmat_link', array( TrackedLink::class, 'render' ) );
	}

	/**
	 * Enqueue the front-end tracking beacon on every front-end page.
	 *
	 * The script must be present in ALL front-end HTML — including pages
	 * rendered without UTM params — because page caches serve that same
	 * cached HTML for UTM-tagged URLs; the beacon self-gates in JS by
	 * inspecting location.search. No nonce is passed: the collect endpoint
	 * is public, and a nonce baked into cached HTML would go stale.
	 *
	 * The full parameter whitelist (built-ins plus custom parameters) is
	 * passed as `params` so the beacon no longer hard-codes the list; its
	 * literal copy is only a fallback for cached HTML without config.
	 *
	 * Consent is decided in the browser: when the WP Consent API is active
	 * the beacon is made to depend on its script so the API always prints
	 * (and, under defer-all optimisers that keep print order, runs) first.
	 * The dependency is conditional because WordPress drops a script whose
	 * dependency is never registered. The localized `cookieEnabled` is the
	 * master switch only; the visitor's verdict is never baked into HTML.
	 *
	 * Untagged landing visits (`visitEnabled`) are reported only while the
	 * setting is on and the viewer is not a logged-in administrator (unless
	 * `utmat_track_logged_in_users` allows it), mirroring the passive PHP
	 * path's exclusion; `hosts` carries the three filtered referrer host
	 * lists the beacon classifies the landing with.
	 *
	 * @since 1.0.0 Added the params whitelist to the localized config.
	 * @since 1.0.0 Added the conditional wp-consent-api dependency and the consentApi, consentCategory and gateEvents keys.
	 * @since 1.0.0 Added the visitEnabled and hosts keys.
	 * @return void
	 */
	public static function enqueue_beacon(): void {
		// Version off the file's own mtime so edits always bust browser caches.
		$js_path     = UTM_ATTRIBUTION_TRACKER_PATH . 'assets/js/beacon.js';
		$consent_api = function_exists( 'wp_has_consent' );

		$visit_enabled = 1 === (int) get_option( 'utmat_track_visits', 1 );
		if ( $visit_enabled && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			/** This filter is documented in src/Tracking/Tracker.php */
			$visit_enabled = (bool) apply_filters( 'utmat_track_logged_in_users', false );
		}

		wp_enqueue_script(
			'utmat-beacon',
			UTM_ATTRIBUTION_TRACKER_URL . 'assets/js/beacon.js',
			$consent_api ? array( 'wp-consent-api' ) : array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : UTM_ATTRIBUTION_TRACKER_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// wp_localize_script() casts scalars to strings: true → '1' and
		// false → '' — both read correctly as truthy/falsy in the beacon.
		wp_localize_script(
			'utmat-beacon',
			'utmatBeacon',
			array(
				'endpoint'        => rest_url( 'utmat/v1/collect' ),
				'params'          => Tracker::param_whitelist(),
				'cookieDays'      => Attribution::cookie_days(),
				'cookieEnabled'   => Attribution::enabled(),
				'consentApi'      => $consent_api,
				'consentCategory' => Attribution::consent_category(),
				'gateEvents'      => Attribution::gate_events(),
				'secure'          => is_ssl(),
				'visitEnabled'    => $visit_enabled,
				'hosts'           => array(
					'ai'     => array_values( TrafficSource::ai_assistant_hosts() ),
					'search' => array_values( TrafficSource::search_engine_hosts() ),
					'social' => array_values( TrafficSource::social_network_hosts() ),
				),
			)
		);
	}

	/**
	 * Plugin activation — install DB, create the IP salt, seed example link,
	 * and schedule retention pruning.
	 *
	 * The /go/{slug}/ rewrite rule is registered before the flush so the
	 * flushed rules include it (activation runs before our `init` hook).
	 *
	 * @since 1.0.0 Added the pretty short-link rewrite registration.
	 * @return void
	 */
	public static function activate(): void {
		Schema::install();
		if ( ! get_option( 'utmat_salt' ) ) {
			add_option( 'utmat_salt', wp_generate_password( 64, true, true ) );
		}
		LinkManager::seed_defaults();
		Retention::schedule();
		Rewrites::add_rules();
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation — unschedule retention pruning and the weekly report.
	 *
	 * @since 1.0.0 Added weekly report unscheduling.
	 * @return void
	 */
	public static function deactivate(): void {
		Retention::unschedule();
		WeeklyEmail::unschedule();
		flush_rewrite_rules();
	}
}
