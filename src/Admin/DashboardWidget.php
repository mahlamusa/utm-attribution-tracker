<?php
/**
 * DashboardWidget — wp-core Dashboard widget showing recent leads.
 *
 * The per-lead counterpart to the dashboard's aggregate Channels
 * breakdown: a real WordPress-core Dashboard widget (wp-admin → Dashboard →
 * Home) listing the most recent form/order conversions, each tagged with a
 * traffic-source classification from Insights::classify(). Directly ports
 * HandL's `Handl_Insight_Engine::analyze()` widget pattern — deterministic
 * PHP, zero AI/network calls.
 *
 * @package Utmatra\Admin
 */

namespace Utmatra\Admin;

use Utmatra\Insights\Insights;
use Utmatra\Insights\Recorder;

defined( 'ABSPATH' ) || exit;

/**
 * DashboardWidget
 *
 * @since 1.0.0
 */
class DashboardWidget {

	/**
	 * Maximum leads shown per integration slug in the widget.
	 *
	 * Tighter than Recorder::MAX_PER_INTEGRATION (the storage cap) — the
	 * widget is a glanceable summary, not a full log.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const PER_INTEGRATION = 2;

	/**
	 * Display label per integration slug.
	 *
	 * Public so the dashboard view can expose the same product names to the
	 * Journeys tab (the `integrationLabels` key of the utmatraI18n strings);
	 * values are vendor product names and deliberately untranslated.
	 *
	 * @since 1.0.0
	 * @var array<string, string>
	 */
	public const INTEGRATION_LABELS = array(
		'contact-form-7' => 'Contact Form 7',
		'gravity-forms'  => 'Gravity Forms',
		'ninja-forms'    => 'Ninja Forms',
		'elementor'      => 'Elementor',
		'wpforms'        => 'WPForms',
		'fluent-forms'   => 'Fluent Forms',
		'formidable'     => 'Formidable Forms',
		'forminator'     => 'Forminator',
		'bricks'         => 'Bricks Builder',
		'woocommerce'    => 'WooCommerce',
	);

	/**
	 * Register the dashboard widget.
	 *
	 * Hooked on `wp_dashboard_setup`. wp_add_dashboard_widget() has no
	 * built-in capability gate, so render() re-checks manage_options itself.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register(): void {
		wp_add_dashboard_widget(
			'utmatra-recent-leads',
			__( 'Recent leads (Utmatra)', 'utmatra-utm-attribution-and-tracking' ),
			array( self::class, 'render' ),
			null,
			null,
			'normal',
			'high'
		);
	}

	/**
	 * Render the widget body.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$leads = Recorder::recent( self::PER_INTEGRATION );

		if ( empty( $leads ) ) {
			echo '<p>' . esc_html__( 'No recent leads yet.', 'utmatra-utm-attribution-and-tracking' ) . '</p>';
			return;
		}

		echo '<ul class="utmatra-lead-list">';
		foreach ( $leads as $integration => $records ) {
			foreach ( $records as $record ) {
				self::render_lead_row( (string) $integration, is_array( $record ) ? $record : array() );
			}
		}
		echo '</ul>';
	}

	/**
	 * Render one lead row.
	 *
	 * @since 1.0.0
	 * @param string               $integration Integration slug.
	 * @param array<string, mixed> $record      Stored lead record (see Recorder::record() for the expected shape).
	 * @return void
	 */
	private static function render_lead_row( string $integration, array $record ): void {
		$posted = isset( $record['posted'] ) && is_array( $record['posted'] ) ? $record['posted'] : array();
		$result = Insights::classify( $posted );

		$form_title = isset( $record['form_title'] ) ? (string) $record['form_title'] : '';
		$created_at = isset( $record['created_at'] ) ? (int) $record['created_at'] : 0;
		$label      = self::INTEGRATION_LABELS[ $integration ] ?? $integration;

		echo '<li class="utmatra-lead-row">';

		echo '<div class="utmatra-lead-heading">';
		echo '<span class="utmatra-lead-title">' . esc_html( '' !== $form_title ? $form_title : $label ) . '</span>';
		echo '<span class="utmatra-lead-meta">' . esc_html( $label );
		if ( $created_at > 0 ) {
			echo ' &middot; ' . esc_html(
				sprintf(
					/* translators: %s: human-readable relative time, e.g. "2 hours". */
					__( '%s ago', 'utmatra-utm-attribution-and-tracking' ),
					human_time_diff( $created_at, time() )
				)
			);
		}
		echo '</span>';
		echo '</div>';

		echo '<span class="utmatra-lead-badge utmatra-lead-badge--' . esc_attr( $result['status'] ) . '">' . esc_html( $result['message'] ) . '</span>';

		echo '</li>';
	}

	/**
	 * Enqueue the widget's stylesheet on wp-admin → Dashboard.
	 *
	 * The widget renders on the core Dashboard, which never loads the plugin
	 * page's own assets/css/dashboard.css, so it has a small stylesheet of
	 * its own. Hooked on `admin_enqueue_scripts`; loads only for users who
	 * can see the widget.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin page hook suffix.
	 *
	 * @return void
	 */
	public static function enqueue_styles( string $hook ): void {
		if ( 'index.php' !== $hook || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'utmatra-dashboard-widget',
			UTMATRA_URL . 'assets/css/dashboard-widget.css',
			array(),
			UTMATRA_VERSION
		);
	}
}
