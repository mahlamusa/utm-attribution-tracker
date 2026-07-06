<?php
/**
 * Plugin singleton — wires all hooks together.
 *
 * @package UTMAT
 */

namespace UTMAT;

use UTMAT\DB\Schema;
use UTMAT\Tracking\Tracker;
use UTMAT\Tracking\LinkManager;
use UTMAT\Admin\Dashboard;
use UTMAT\Admin\RestController;
use UTMAT\Shortcode\TrackedLink;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin — the singleton returned by Plugin::instance().
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

		// REST API.
		add_action( 'rest_api_init', array( RestController::class, 'register_routes' ) );

		// Admin dashboard.
		if ( is_admin() ) {
			/*
			 * Menu registration:
			 *
			 * If the companion SMTP plugin is installed and active (detected by its constant),
			 * hook into the `companion_smtp_admin_menu` action it fires after registering
			 * its own pages — this is the intended extension point for add-ons.
			 *
			 * Otherwise fall back to a standalone top-level menu entry.
			 */
			if ( defined( 'COMPANION_SMTP_VERSION' ) ) {
				add_action( 'companion_smtp_admin_menu', array( Dashboard::class, 'register_as_submenu' ) );
			} else {
				add_action( 'admin_menu', array( Dashboard::class, 'register_menu' ) );
			}

			add_action( 'admin_enqueue_scripts', array( Dashboard::class, 'enqueue_assets' ) );

			// "UTM Tracker" row link on the Plugins screen.
			add_filter( 'plugin_action_links_' . UTM_ATTRIBUTION_TRACKER_BASENAME, array( Dashboard::class, 'add_plugin_action_link' ) );
		}

		// Shortcode: [utmat_link].
		add_shortcode( 'utmat_link', array( TrackedLink::class, 'render' ) );
	}

	/**
	 * Plugin activation — install DB and seed example link.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function activate(): void {
		Schema::install();
		LinkManager::seed_defaults();
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
