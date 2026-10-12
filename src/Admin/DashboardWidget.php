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
 * @package UTMAT\Admin
 */

namespace UTMAT\Admin;

use UTMAT\Insights\Insights;
use UTMAT\Insights\Recorder;

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
	 * @since 1.0.0
	 * @var array<string, string>
	 */
	private const INTEGRATION_LABELS = array(
		'contact-form-7' => 'Contact Form 7',
		'gravity-forms'  => 'Gravity Forms',
		'ninja-forms'    => 'Ninja Forms',
		'elementor'      => 'Elementor',
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
			'utmat-recent-leads',
			__( 'Recent leads (UTM & Attribution)', 'utm-attribution-tracker' ),
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
			echo '<p>' . esc_html__( 'No recent leads yet.', 'utm-attribution-tracker' ) . '</p>';
			return;
		}

		self::render_styles();

		echo '<ul class="utmat-lead-list">';
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

		echo '<li class="utmat-lead-row">';

		echo '<div class="utmat-lead-heading">';
		echo '<span class="utmat-lead-title">' . esc_html( '' !== $form_title ? $form_title : $label ) . '</span>';
		echo '<span class="utmat-lead-meta">' . esc_html( $label );
		if ( $created_at > 0 ) {
			echo ' &middot; ' . esc_html(
				sprintf(
					/* translators: %s: human-readable relative time, e.g. "2 hours". */
					__( '%s ago', 'utm-attribution-tracker' ),
					human_time_diff( $created_at, time() )
				)
			);
		}
		echo '</span>';
		echo '</div>';

		echo '<span class="utmat-lead-badge utmat-lead-badge--' . esc_attr( $result['status'] ) . '">' . esc_html( $result['message'] ) . '</span>';

		echo '</li>';
	}

	/**
	 * Echo the widget's self-contained styles.
	 *
	 * The widget renders on wp-admin → Dashboard, which never loads this
	 * plugin's own assets/css/dashboard.css (that stylesheet is enqueued
	 * only on the plugin's own admin page) — so, matching HandL's approach,
	 * the styles ship inline instead. Colours reuse the same green/amber/
	 * blue scheme as the Tracking Doctor diagnostics badges for visual
	 * consistency across the plugin.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function render_styles(): void {
		echo '<style>
			.utmat-lead-list { list-style: none; margin: 0; padding: 0; }
			.utmat-lead-row { padding: 10px 0; border-bottom: 1px solid #dcdcde; }
			.utmat-lead-row:last-child { border-bottom: none; padding-bottom: 0; }
			.utmat-lead-heading { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px; margin-bottom: 4px; }
			.utmat-lead-title { font-weight: 600; color: #1d2327; }
			.utmat-lead-meta { color: #646970; font-size: 12px; }
			.utmat-lead-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 12px; color: #fff; }
			.utmat-lead-badge--google_ads { background: #007017; }
			.utmat-lead-badge--paid { background: #0d5ea3; }
			.utmat-lead-badge--organic_referral { background: #2271b1; }
			.utmat-lead-badge--tagged { background: #996800; }
			.utmat-lead-badge--direct { background: #646970; }
		</style>';
	}
}
