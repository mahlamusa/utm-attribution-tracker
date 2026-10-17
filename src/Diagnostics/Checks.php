<?php
/**
 * Checks — rule-based Tracking Doctor diagnostics.
 *
 * Five deterministic, zero-external-call checks (caching, consent, cookies,
 * forms coverage, WooCommerce order attribution) ported from HandL's
 * Tracking Doctor. Every check inspects local plugin/site state only — no
 * AI, no live network probes — and returns the fixed shape documented on
 * run_all().
 *
 * @package Utmatra\Diagnostics
 */

namespace Utmatra\Diagnostics;

use Utmatra\Admin\Dashboard;
use Utmatra\Integrations\Forms\Bricks;
use Utmatra\Integrations\Forms\ContactForm7;
use Utmatra\Integrations\Forms\Elementor;
use Utmatra\Integrations\Forms\FluentForms;
use Utmatra\Integrations\Forms\Formidable;
use Utmatra\Integrations\Forms\Forminator;
use Utmatra\Integrations\Forms\GravityForms;
use Utmatra\Integrations\Forms\NinjaForms;
use Utmatra\Integrations\Forms\WPForms;
use Utmatra\Tracking\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * Checks
 *
 * @since 1.0.0
 */
class Checks {

	/**
	 * Run every diagnostic check, in a fixed order.
	 *
	 * @since 1.0.0
	 * @return array<int, array{id: string, label: string, status: string, message: string, details: array}> One row per check: caching, consent, cookies, forms, woocommerce.
	 */
	public static function run_all(): array {
		return array(
			self::caching(),
			self::consent(),
			self::cookies(),
			self::forms(),
			self::woocommerce(),
		);
	}

	/**
	 * Full-page cache detection.
	 *
	 * Reuses Dashboard::detect_cache_plugin()'s allow-list so the dashboard's
	 * settings-card notice and this diagnostic never disagree about which
	 * cache plugin is active.
	 *
	 * @since 1.0.0
	 * @return array{id: string, label: string, status: string, message: string, details: array}
	 */
	public static function caching(): array {
		$plugin = Dashboard::detect_cache_plugin();

		if ( '' !== $plugin ) {
			return self::result(
				'caching',
				__( 'Caching', 'utm-attribution-tracker' ),
				'warn',
				sprintf(
					/* translators: %s: detected cache plugin name. */
					__( '%s detected — confirm the tracking beacon and REST collect endpoint aren\'t excluded from caching.', 'utm-attribution-tracker' ),
					$plugin
				),
				array( 'plugin' => $plugin )
			);
		}

		return self::result(
			'caching',
			__( 'Caching', 'utm-attribution-tracker' ),
			'pass',
			__( 'No known full-page cache plugin detected.', 'utm-attribution-tracker' ),
			array()
		);
	}

	/**
	 * Consent-gating rule that applies to the attribution cookie.
	 *
	 * Describes the rule, never a verdict: wp_has_consent() is deliberately
	 * not called, because from the admin's REST request it would report the
	 * admin's own consent cookie as if it were a visitor's. The reads of
	 * wp_get_consent_type() and consent_api_registered() are guarded by the
	 * API's presence since both are defined by the WP Consent API plugin.
	 *
	 * @since 1.0.0
	 * @return array{id: string, label: string, status: string, message: string, details: array}
	 */
	public static function consent(): array {
		$label       = __( 'Consent', 'utm-attribution-tracker' );
		$api_present = function_exists( 'wp_has_consent' );
		$category    = Attribution::consent_category();
		$details     = array(
			'api_present'    => $api_present,
			'consent_type'   => $api_present ? wp_get_consent_type() : '',
			'registered'     => $api_present ? (bool) consent_api_registered( UTMATRA_BASENAME ) : false,
			'category'       => $category,
			'master_enabled' => Attribution::enabled(),
			'gate_events'    => Attribution::gate_events(),
		);

		if ( ! $details['master_enabled'] ) {
			return self::result(
				'consent',
				$label,
				'warn',
				__( 'The utmatra_enable_cookie filter disables the attribution cookie site-wide; consent settings have no effect until it is removed.', 'utm-attribution-tracker' ),
				$details
			);
		}

		if ( ! $api_present ) {
			return self::result(
				'consent',
				$label,
				'pass',
				sprintf(
					/* translators: %s: consent category label (marketing, statistics, or marketing or statistics). */
					__( 'No WP Consent API detected — the attribution cookie is written on every tagged visit. Install a consent plugin that implements the WP Consent API to gate it on the %s category.', 'utm-attribution-tracker' ),
					Attribution::consent_category_label( $category )
				),
				$details
			);
		}

		if ( '' === $details['consent_type'] ) {
			return self::result(
				'consent',
				$label,
				'warn',
				__( 'WP Consent API is active but no consent-management plugin has declared a consent type, so the API reports every category as granted and the cookie is written for every visitor.', 'utm-attribution-tracker' ),
				$details
			);
		}

		if ( ! $details['registered'] ) {
			return self::result(
				'consent',
				$label,
				'warn',
				__( 'WP Consent API is active but this plugin is not registered with it — another plugin may be removing the registration filter.', 'utm-attribution-tracker' ),
				$details
			);
		}

		if ( $details['gate_events'] ) {
			$message = sprintf(
				/* translators: 1: consent type (optin or optout), 2: consent category label. */
				__( 'WP Consent API active (%1$s). The attribution cookie is withheld until the visitor grants %2$s consent; the decision is made in the visitor\'s browser, and the server fallback applies the same rule per request. Tagged-visit events are also withheld until the visitor consents, because the utmatra_consent_gate_events filter is enabled.', 'utm-attribution-tracker' ),
				$details['consent_type'],
				Attribution::consent_category_label( $category )
			);
		} else {
			$message = sprintf(
				/* translators: 1: consent type (optin or optout), 2: consent category label. */
				__( 'WP Consent API active (%1$s). The attribution cookie is withheld until the visitor grants %2$s consent; the decision is made in the visitor\'s browser, and the server fallback applies the same rule per request. Tagged-visit events, which carry no cookie and only a salted IP hash, are recorded regardless of consent.', 'utm-attribution-tracker' ),
				$details['consent_type'],
				Attribution::consent_category_label( $category )
			);
		}

		return self::result( 'consent', $label, 'pass', $message, $details );
	}

	/**
	 * Attribution cookie transport security.
	 *
	 * `details` always carries the cookie name, configured lifetime, and the
	 * SameSite policy the writers apply (see Tracker::maybe_set_attribution_cookie()).
	 *
	 * @since 1.0.0
	 * @return array{id: string, label: string, status: string, message: string, details: array}
	 */
	public static function cookies(): array {
		$label   = __( 'Cookies', 'utm-attribution-tracker' );
		$details = array(
			'cookie_name' => Attribution::COOKIE_NAME,
			'cookie_days' => Attribution::cookie_days(),
			'samesite'    => 'Lax',
		);

		if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
			return self::result(
				'cookies',
				$label,
				'warn',
				__( 'Site is not served over HTTPS — the attribution cookie cannot be marked Secure.', 'utm-attribution-tracker' ),
				$details
			);
		}

		return self::result(
			'cookies',
			$label,
			'pass',
			__( 'Site is served over HTTPS; the attribution cookie can be marked Secure.', 'utm-attribution-tracker' ),
			$details
		);
	}

	/**
	 * Fixed list of the form plugins this diagnostic knows how to check.
	 *
	 * Each entry's `active` flag detects the underlying form plugin directly
	 * (not via the integration class), and `class` names the plugin adapter
	 * that may or may not exist yet — NinjaForms/Elementor (specs 008/009)
	 * are read defensively via class_exists() in forms() so an active plugin
	 * without an integration degrades to details.unsupported_plugins instead
	 * of failing.
	 *
	 * @since 1.0.0
	 * @return array<int, array{label: string, active: bool, class: string}>
	 */
	private static function form_plugin_definitions(): array {
		return array(
			array(
				'label'  => __( 'Contact Form 7', 'utm-attribution-tracker' ),
				'active' => defined( 'WPCF7_VERSION' ),
				'class'  => ContactForm7::class,
			),
			array(
				'label'  => __( 'Gravity Forms', 'utm-attribution-tracker' ),
				'active' => class_exists( 'GFForms' ),
				'class'  => GravityForms::class,
			),
			array(
				'label'  => __( 'Ninja Forms', 'utm-attribution-tracker' ),
				'active' => function_exists( 'Ninja_Forms' ),
				'class'  => NinjaForms::class,
			),
			array(
				'label'  => __( 'Elementor Pro Forms', 'utm-attribution-tracker' ),
				'active' => defined( 'ELEMENTOR_PRO_VERSION' ),
				'class'  => Elementor::class,
			),
			array(
				'label'  => __( 'WPForms', 'utm-attribution-tracker' ),
				'active' => defined( 'WPFORMS_VERSION' ),
				'class'  => WPForms::class,
			),
			array(
				'label'  => __( 'Fluent Forms', 'utm-attribution-tracker' ),
				'active' => defined( 'FLUENTFORM' ),
				'class'  => FluentForms::class,
			),
			array(
				'label'  => __( 'Formidable Forms', 'utm-attribution-tracker' ),
				'active' => class_exists( 'FrmAppHelper' ),
				'class'  => Formidable::class,
			),
			array(
				'label'  => __( 'Forminator', 'utm-attribution-tracker' ),
				'active' => defined( 'FORMINATOR_VERSION' ),
				'class'  => Forminator::class,
			),
			array(
				'label'  => __( 'Bricks Builder (untested)', 'utm-attribution-tracker' ),
				'active' => defined( 'BRICKS_VERSION' ),
				'class'  => Bricks::class,
			),
		);
	}

	/**
	 * Forms coverage — the per-form/per-param attribution matrix.
	 *
	 * Aggregates each active integration's get_form_status() into an overall
	 * verdict. An active form plugin whose integration class doesn't
	 * exist yet (NinjaForms/Elementor, specs 008/009) is listed in
	 * `details.unsupported_plugins` and does not affect the overall status.
	 *
	 * @since 1.0.0
	 * @return array{id: string, label: string, status: string, message: string, details: array}
	 */
	public static function forms(): array {
		$label = __( 'Forms coverage', 'utm-attribution-tracker' );

		$matrix                 = array();
		$unsupported_plugins    = array();
		$any_active_integration = false;

		foreach ( self::form_plugin_definitions() as $definition ) {
			if ( ! $definition['active'] ) {
				continue;
			}

			if ( ! class_exists( $definition['class'] ) ) {
				$unsupported_plugins[] = array(
					'label'   => $definition['label'],
					'message' => __( "Utmatra does not yet capture attribution for this plugin's forms.", 'utm-attribution-tracker' ),
				);
				continue;
			}

			$any_active_integration = true;

			$rows = call_user_func( array( $definition['class'], 'get_form_status' ) );
			foreach ( (array) $rows as $row ) {
				$row['integration'] = $definition['label'];
				$matrix[]           = $row;
			}
		}

		if ( ! $any_active_integration ) {
			return self::result(
				'forms',
				$label,
				'skip',
				__( 'No supported form plugin active.', 'utm-attribution-tracker' ),
				array(
					'matrix'              => array(),
					'problem_forms'       => array(),
					'unsupported_plugins' => $unsupported_plugins,
				)
			);
		}

		$problem_forms = array();
		foreach ( $matrix as $row ) {
			if ( 'complete' !== ( $row['status'] ?? '' ) ) {
				$problem_forms[] = $row;
			}
		}

		$total   = count( $matrix );
		$problem = count( $problem_forms );

		if ( 0 === $problem ) {
			$status  = 'pass';
			$message = sprintf(
				/* translators: %d: number of forms. */
				__( 'All %d forms across active integrations are fully covered.', 'utm-attribution-tracker' ),
				$total
			);
		} else {
			$status  = 'warn';
			$message = sprintf(
				/* translators: %1$d: forms missing attribution fields, %2$d: total forms. */
				__( '%1$d of %2$d forms are missing attribution fields.', 'utm-attribution-tracker' ),
				$problem,
				$total
			);
		}

		return self::result(
			'forms',
			$label,
			$status,
			$message,
			array(
				'matrix'              => $matrix,
				'problem_forms'       => $problem_forms,
				'unsupported_plugins' => $unsupported_plugins,
			)
		);
	}

	/**
	 * WooCommerce order attribution — samples the last 10 orders.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Checks the stored attribution array without a string cast.
	 * @return array{id: string, label: string, status: string, message: string, details: array}
	 */
	public static function woocommerce(): array {
		$label = __( 'WooCommerce', 'utm-attribution-tracker' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			return self::result(
				'woocommerce',
				$label,
				'skip',
				__( 'WooCommerce not active.', 'utm-attribution-tracker' ),
				array()
			);
		}

		$orders = wc_get_orders(
			array(
				'limit'   => 10,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);

		if ( empty( $orders ) ) {
			return self::result(
				'woocommerce',
				$label,
				'skip',
				__( 'No orders yet.', 'utm-attribution-tracker' ),
				array()
			);
		}

		$total     = count( $orders );
		$attribute = 0;
		foreach ( $orders as $order ) {
			// Stored as an array; a string cast warns and always reads "Array".
			if ( ! empty( $order->get_meta( '_utmatra_last', true ) ) ) {
				++$attribute;
			}
		}

		$details = array(
			'checked'          => $total,
			'with_attribution' => $attribute,
		);

		if ( 0 === $attribute ) {
			return self::result(
				'woocommerce',
				$label,
				'warn',
				__( 'Recent orders exist but none carry attribution data — check that visitors are landing with UTM parameters before checkout.', 'utm-attribution-tracker' ),
				$details
			);
		}

		return self::result(
			'woocommerce',
			$label,
			'pass',
			sprintf(
				/* translators: %1$d: orders carrying attribution data, %2$d: orders sampled. */
				__( '%1$d of the last %2$d orders carry attribution data.', 'utm-attribution-tracker' ),
				$attribute,
				$total
			),
			$details
		);
	}

	/**
	 * Build a single check result in the shape shared by every check.
	 *
	 * @since 1.0.0
	 * @param string $id      Check identifier.
	 * @param string $label   Human-readable check label.
	 * @param string $status  One of 'pass', 'warn', 'fail', 'skip'.
	 * @param string $message Static, pre-written status message.
	 * @param array  $details Extra structured data (per-check shape); empty when unused.
	 * @return array{id: string, label: string, status: string, message: string, details: array}
	 */
	private static function result( string $id, string $label, string $status, string $message, array $details ): array {
		return array(
			'id'      => $id,
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
			'details' => $details,
		);
	}
}
