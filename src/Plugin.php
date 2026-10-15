<?php
/**
 * Plugin singleton — wires all hooks together.
 *
 * @package Utmatra
 */

namespace Utmatra;

use Utmatra\DB\Schema;
use Utmatra\DB\Retention;
use Utmatra\Privacy\PrivacyPolicy;
use Utmatra\Reports\ConversionWebhook;
use Utmatra\Reports\WeeklyEmail;
use Utmatra\Tracking\Attribution;
use Utmatra\Tracking\Tracker;
use Utmatra\Tracking\TrafficSource;
use Utmatra\Tracking\LinkManager;
use Utmatra\Tracking\Rewrites;
use Utmatra\Admin\Dashboard;
use Utmatra\Admin\DashboardWidget;
use Utmatra\Admin\RestController;
use Utmatra\Integrations\WooCommerce;
use Utmatra\Integrations\Forms\Bricks;
use Utmatra\Integrations\Forms\ContactForm7;
use Utmatra\Integrations\Forms\Elementor;
use Utmatra\Integrations\Forms\FluentForms;
use Utmatra\Integrations\Forms\Formidable;
use Utmatra\Integrations\Forms\Forminator;
use Utmatra\Integrations\Forms\GravityForms;
use Utmatra\Integrations\Forms\NinjaForms;
use Utmatra\Integrations\Forms\WPForms;
use Utmatra\Rest\CollectController;
use Utmatra\Shortcode\TrackedLink;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin — the singleton returned by Plugin::instance().
 *
 * @since 1.0.0
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
	 * @since 1.0.0 Registers the schedule-heal hook.
	 * @return void
	 */
	private function init_hooks(): void {
		// DB version check on every load. This runs during `plugins_loaded`
		// itself, so hook `init` (priority 0) rather than `plugins_loaded`.
		add_action( 'init', array( Schema::class, 'maybe_upgrade' ), 0 );

		// Register utmatra_track query var.
		add_filter( 'query_vars', array( Tracker::class, 'register_query_vars' ) );

		// Track incoming requests at priority 1 (before most other code).
		add_action( 'init', array( Tracker::class, 'handle_incoming_request' ), 1 );

		// Pretty short links: register /go/{slug}/ and bridge parsed requests
		// into the redirect handler (the query var only exists post-init).
		add_action( 'init', array( Rewrites::class, 'add_rules' ) );
		add_action( 'parse_request', array( Rewrites::class, 'handle_pretty_request' ) );

		// Daily retention pruning.
		add_action( Retention::HOOK, array( Retention::class, 'prune' ) );

		// Weekly email report (only scheduled while the toggle is on).
		add_action( WeeklyEmail::HOOK, array( WeeklyEmail::class, 'send' ) );

		// Recreate any missing recurring job (throttled to twice a day). On
		// `init`, so the WP-Cron fallback heals too when Action Scheduler is
		// not loaded; Scheduler::ensure() defers until it has initialised.
		add_action( 'init', array( Scheduler::class, 'heal' ), 20 );

		// Lead webhook: deliver every attributed form submission recorded by
		// the Insights\Recorder to the configured webhook URL.
		add_action( 'utmatra_lead_recorded', array( ConversionWebhook::class, 'send_lead' ), 10, 2 );

		// Suggested privacy policy text.
		add_action( 'admin_init', array( PrivacyPolicy::class, 'register' ) );

		// WP Consent API: declare compliance (the filter is inert without the
		// API) and describe the attribution cookie so consent banners can list it.
		add_filter( 'wp_consent_api_registered_' . UTMATRA_BASENAME, '__return_true' );
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
		add_action( 'plugins_loaded', array( WPForms::class, 'maybe_init' ), 20 );
		add_action( 'plugins_loaded', array( FluentForms::class, 'maybe_init' ), 20 );
		add_action( 'plugins_loaded', array( Formidable::class, 'maybe_init' ), 20 );
		add_action( 'plugins_loaded', array( Forminator::class, 'maybe_init' ), 20 );

		// Bricks is a theme: nothing of it exists at `plugins_loaded`, so its
		// adapter detects it once the theme has been set up.
		add_action( 'after_setup_theme', array( Bricks::class, 'maybe_init' ) );

		// Admin dashboard.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( Dashboard::class, 'register_menu' ) );

			add_action( 'admin_enqueue_scripts', array( Dashboard::class, 'enqueue_assets' ) );

			// Recent-leads widget on wp-admin → Dashboard → Home.
			add_action( 'wp_dashboard_setup', array( DashboardWidget::class, 'register' ) );

			// "Utmatra" row link on the Plugins screen.
			add_filter( 'plugin_action_links_' . UTMATRA_BASENAME, array( Dashboard::class, 'add_plugin_action_link' ) );
		}

		// Shortcode: [utmatra_link].
		add_shortcode( 'utmatra_link', array( TrackedLink::class, 'render' ) );
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
	 * `utmatra_track_logged_in_users` allows it), mirroring the passive PHP
	 * path's exclusion; `hosts` carries the three filtered referrer host
	 * lists the beacon classifies the landing with.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function enqueue_beacon(): void {
		// Version off the file's own mtime so edits always bust browser caches.
		$js_path     = UTMATRA_PATH . 'assets/js/beacon.js';
		$consent_api = function_exists( 'wp_has_consent' );

		$visit_enabled = 1 === (int) get_option( 'utmatra_track_visits', 1 );
		if ( $visit_enabled && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			/** This filter is documented in src/Tracking/Tracker.php */
			$visit_enabled = (bool) apply_filters( 'utmatra_track_logged_in_users', false );
		}

		wp_enqueue_script(
			'utmatra-beacon',
			UTMATRA_URL . 'assets/js/beacon.js',
			$consent_api ? array( 'wp-consent-api' ) : array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : UTMATRA_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// wp_localize_script() casts scalars to strings: true → '1' and
		// false → '' — both read correctly as truthy/falsy in the beacon.
		wp_localize_script(
			'utmatra-beacon',
			'utmatraBeacon',
			array(
				'endpoint'        => rest_url( 'utmatra/v1/collect' ),
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
	 * @since 1.0.0
	 * @since 1.0.0 Resets the schedule-heal throttle.
	 * @return void
	 */
	public static function activate(): void {
		Schema::install();
		if ( ! get_option( 'utmatra_salt' ) ) {
			add_option( 'utmatra_salt', wp_generate_password( 64, true, true ) );
		}
		LinkManager::seed_defaults();
		// Heal on the next request too, so a reactivation restores the
		// weekly report (deactivation removed it) without a settings save.
		delete_option( Scheduler::HEAL_OPTION );
		Retention::schedule();
		Rewrites::add_rules();
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation — unschedule retention pruning and the weekly report.
	 *
	 * Queued order webhooks are cancelled too: with the plugin inactive no
	 * callback is registered, so they would otherwise run and fail.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Cancels queued order webhooks.
	 * @return void
	 */
	public static function deactivate(): void {
		Retention::unschedule();
		WeeklyEmail::unschedule();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// Null args match every queued webhook (each carries an order id).
			as_unschedule_all_actions( WooCommerce::WEBHOOK_HOOK, null, Scheduler::GROUP );
		}
		flush_rewrite_rules();
	}
}
