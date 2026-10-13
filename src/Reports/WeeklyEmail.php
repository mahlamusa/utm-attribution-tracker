<?php
/**
 * WeeklyEmail — weekly campaign summary sent via wp_mail().
 *
 * Disabled by default (the `utmat_weekly_email` option, default OFF —
 * no unsolicited mail). When enabled from the dashboard settings card a
 * weekly cron event (`utmat_weekly_report`) emails the site admin a
 * plain-HTML summary of the past 7 days: total clicks, top 5 campaigns,
 * top 5 sources, and revenue by campaign when WooCommerce is active.
 *
 * The recipient defaults to `admin_email` and is filterable via
 * `utmat_report_recipient`.
 *
 * @package UTMAT\Reports
 */

namespace UTMAT\Reports;

use UTMAT\DB\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * WeeklyEmail
 *
 * @since 1.0.0
 */
class WeeklyEmail {

	/**
	 * Cron hook name for the weekly report event.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const HOOK = 'utmat_weekly_report';

	/**
	 * Option name holding the weekly email toggle.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const OPTION = 'utmat_weekly_email';

	/**
	 * Whether the weekly email is enabled.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function enabled(): bool {
		return (bool) get_option( self::OPTION, false );
	}

	/**
	 * Schedule the weekly report event if not already scheduled.
	 *
	 * Called from the settings endpoint when the toggle is switched on.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', self::HOOK );
		}
	}

	/**
	 * Clear the scheduled weekly report event.
	 *
	 * Called from the settings endpoint when the toggle is switched off and
	 * from Plugin::deactivate().
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Cron callback — build and send the weekly summary email.
	 *
	 * No-ops when the toggle is off (a stale cron event never mails) or when
	 * the resolved recipient is empty. Pass `$force` to send regardless of
	 * the toggle — used by the dashboard's "Send test email" button.
	 *
	 * @since 1.0.0
	 * @param bool $force Send even when the weekly toggle is off.
	 * @return bool Whether the mail was handed to wp_mail() successfully.
	 */
	public static function send( bool $force = false ): bool {
		if ( ! $force && ! self::enabled() ) {
			return false;
		}

		/**
		 * Filter the weekly report recipient address.
		 *
		 * @since 1.0.0
		 * @param string $recipient Recipient email address. Defaults to the admin email.
		 */
		$recipient = sanitize_email( (string) apply_filters( 'utmat_report_recipient', get_option( 'admin_email' ) ) );
		if ( '' === $recipient ) {
			$recipient = sanitize_email( (string) get_option( 'admin_email' ) );
		}
		if ( '' === $recipient ) {
			return false;
		}

		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Weekly UTM campaign report', 'utm-attribution-tracker' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		return wp_mail(
			$recipient,
			$subject,
			self::build_html(),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	/**
	 * Build the inline-styled HTML body for the past 7 days.
	 *
	 * Untagged landing visits (`link_id = 'visit'`) are not clicks and stay
	 * out of the totals.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function build_html(): string {
		global $wpdb;
		$table = esc_sql( Schema::table() );
		$since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time analytics table, no cache layer by design
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE clicked_at >= %s AND is_conversion = 0 AND link_id != 'visit'", $since )
		);

		// The week before, for the week-over-week comparison line.
		$prev_since = gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS );
		$prev_total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE clicked_at >= %s AND clicked_at < %s AND is_conversion = 0 AND link_id != 'visit'",
				$prev_since,
				$since
			)
		);

		$top_campaigns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT utm_campaign AS label, COUNT(*) AS count
				 FROM {$table}
				 WHERE clicked_at >= %s AND is_conversion = 0 AND utm_campaign != ''
				 GROUP BY utm_campaign
				 ORDER BY count DESC
				 LIMIT 5",
				$since
			),
			ARRAY_A
		);

		$top_sources = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT utm_source AS label, COUNT(*) AS count
				 FROM {$table}
				 WHERE clicked_at >= %s AND is_conversion = 0 AND utm_source != ''
				 GROUP BY utm_source
				 ORDER BY count DESC
				 LIMIT 5",
				$since
			),
			ARRAY_A
		);

		$revenue = array();
		if ( class_exists( 'WooCommerce' ) ) {
			$revenue = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT utm_campaign AS label, COUNT(*) AS orders, SUM(order_total) AS revenue
					 FROM {$table}
					 WHERE clicked_at >= %s AND is_conversion = 1 AND utm_campaign != ''
					 GROUP BY utm_campaign
					 ORDER BY revenue DESC
					 LIMIT 5",
					$since
				),
				ARRAY_A
			);
		}
		// phpcs:enable

		$heading_style = 'font-size:15px;color:#1d2327;margin:24px 0 8px;';

		$html  = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#1d2327;max-width:560px;">';
		$html .= '<h1 style="font-size:18px;margin:0 0 4px;">' . esc_html__( 'Weekly UTM campaign report', 'utm-attribution-tracker' ) . '</h1>';
		$html .= '<p style="color:#646970;margin:0 0 16px;">' . esc_html( get_bloginfo( 'name' ) ) . ' — ' . esc_html__( 'past 7 days', 'utm-attribution-tracker' ) . '</p>';
		$html .= '<p style="font-size:14px;">' . sprintf(
			/* translators: %s: total click count. */
			esc_html__( 'Total clicks: %s', 'utm-attribution-tracker' ),
			'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
		) . self::trend_line( $total, $prev_total ) . '</p>';

		$html .= '<h2 style="' . $heading_style . '">' . esc_html__( 'Top campaigns', 'utm-attribution-tracker' ) . '</h2>';
		$html .= self::count_table( (array) $top_campaigns, __( 'Campaign', 'utm-attribution-tracker' ) );

		$html .= '<h2 style="' . $heading_style . '">' . esc_html__( 'Top sources', 'utm-attribution-tracker' ) . '</h2>';
		$html .= self::count_table( (array) $top_sources, __( 'Source', 'utm-attribution-tracker' ) );

		if ( ! empty( $revenue ) ) {
			$html .= '<h2 style="' . $heading_style . '">' . esc_html__( 'Revenue by campaign', 'utm-attribution-tracker' ) . '</h2>';
			$html .= self::revenue_table( (array) $revenue );
		}

		$html .= '<p style="color:#646970;font-size:12px;margin-top:24px;">' . sprintf(
			/* translators: %s: plugin name. */
			esc_html__( 'Sent by %s. Disable this report from the dashboard settings card.', 'utm-attribution-tracker' ),
			'UTM & Attribution Tracker'
		) . '</p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render the week-over-week comparison fragment for the totals line.
	 *
	 * Returns an empty string when the previous week had no clicks (no
	 * meaningful percentage exists).
	 *
	 * @since 1.0.0
	 * @param int $total      Clicks in the reported week.
	 * @param int $prev_total Clicks in the week before it.
	 * @return string HTML fragment, or empty string.
	 */
	private static function trend_line( int $total, int $prev_total ): string {
		if ( $prev_total <= 0 ) {
			return '';
		}

		$pct = (int) round( ( ( $total - $prev_total ) / $prev_total ) * 100 );
		if ( $pct > 0 ) {
			$text  = '▲ ' . $pct . '%';
			$color = '#2e8540';
		} elseif ( $pct < 0 ) {
			$text  = '▼ ' . abs( $pct ) . '%';
			$color = '#b32d2e';
		} else {
			$text  = '±0%';
			$color = '#646970';
		}

		return ' <span style="color:' . esc_attr( $color ) . ';font-size:12px;font-weight:600;">'
			. esc_html( $text ) . ' ' . esc_html__( 'vs previous week', 'utm-attribution-tracker' ) . '</span>';
	}

	/**
	 * Render a two-column label/count table.
	 *
	 * @since 1.0.0
	 * @param array<int, array<string, string>> $rows         Rows with `label` and `count` keys.
	 * @param string                            $label_header Header text for the label column.
	 * @return string
	 */
	private static function count_table( array $rows, string $label_header ): string {
		if ( empty( $rows ) ) {
			return '<p style="color:#646970;">' . esc_html__( 'No data recorded this week.', 'utm-attribution-tracker' ) . '</p>';
		}

		$html = '<table style="border-collapse:collapse;width:100%;font-size:13px;">'
			. '<tr>'
			. '<th style="text-align:left;border-bottom:1px solid #dcdcde;padding:6px 8px;">' . esc_html( $label_header ) . '</th>'
			. '<th style="text-align:right;border-bottom:1px solid #dcdcde;padding:6px 8px;">' . esc_html__( 'Clicks', 'utm-attribution-tracker' ) . '</th>'
			. '</tr>';

		foreach ( $rows as $row ) {
			$html .= '<tr>'
				. '<td style="border-bottom:1px solid #f0f0f1;padding:6px 8px;">' . esc_html( (string) $row['label'] ) . '</td>'
				. '<td style="text-align:right;border-bottom:1px solid #f0f0f1;padding:6px 8px;">' . esc_html( number_format_i18n( (int) $row['count'] ) ) . '</td>'
				. '</tr>';
		}

		return $html . '</table>';
	}

	/**
	 * Render the revenue-by-campaign table (WooCommerce only).
	 *
	 * @since 1.0.0
	 * @param array<int, array<string, string>> $rows Rows with `label`, `orders`, and `revenue` keys.
	 * @return string
	 */
	private static function revenue_table( array $rows ): string {
		$html = '<table style="border-collapse:collapse;width:100%;font-size:13px;">'
			. '<tr>'
			. '<th style="text-align:left;border-bottom:1px solid #dcdcde;padding:6px 8px;">' . esc_html__( 'Campaign', 'utm-attribution-tracker' ) . '</th>'
			. '<th style="text-align:right;border-bottom:1px solid #dcdcde;padding:6px 8px;">' . esc_html__( 'Orders', 'utm-attribution-tracker' ) . '</th>'
			. '<th style="text-align:right;border-bottom:1px solid #dcdcde;padding:6px 8px;">' . esc_html__( 'Revenue', 'utm-attribution-tracker' ) . '</th>'
			. '</tr>';

		foreach ( $rows as $row ) {
			$amount = function_exists( 'wc_price' )
				? wc_price( (float) $row['revenue'] )
				: esc_html( number_format_i18n( (float) $row['revenue'], 2 ) );

			$html .= '<tr>'
				. '<td style="border-bottom:1px solid #f0f0f1;padding:6px 8px;">' . esc_html( (string) $row['label'] ) . '</td>'
				. '<td style="text-align:right;border-bottom:1px solid #f0f0f1;padding:6px 8px;">' . esc_html( number_format_i18n( (int) $row['orders'] ) ) . '</td>'
				. '<td style="text-align:right;border-bottom:1px solid #f0f0f1;padding:6px 8px;">' . wp_kses_post( $amount ) . '</td>'
				. '</tr>';
		}

		return $html . '</table>';
	}
}
