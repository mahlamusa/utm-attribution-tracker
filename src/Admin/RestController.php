<?php
/**
 * REST Controller — exposes stats and links data to the dashboard.
 *
 * Routes:
 *   GET    utmat/v1/stats       — aggregated click data
 *   GET    utmat/v1/links       — managed links with per-link click counts
 *   POST   utmat/v1/links       — create a managed link
 *   PUT    utmat/v1/links/<id>  — update a managed link
 *   DELETE utmat/v1/links/<id>  — delete a managed link
 *   GET    utmat/v1/export      — raw event rows for CSV export
 *   POST   utmat/v1/settings    — save retention days + weekly email toggle
 *   GET    utmat/v1/diagnostics — Tracking Doctor diagnostic check results
 *
 * All routes require the `manage_options` capability.
 *
 * @since 1.0.0 Stats report channels and exclude untagged landing visits; settings carry the visit-tracking switch.
 *
 * @package UTMAT\Admin
 */

namespace UTMAT\Admin;

use DateTimeImmutable;
use DateTimeZone;
use UTMAT\DB\Schema;
use UTMAT\Diagnostics\Checks;
use UTMAT\Reports\ConversionWebhook;
use UTMAT\Reports\WeeklyEmail;
use UTMAT\Tracking\Attribution;
use UTMAT\Tracking\LinkManager;
use UTMAT\Tracking\TrafficSource;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * RestController
 *
 * @since 1.0.0 The settings route accepts the consent category.
 * @since 1.0.0 Added `by_channel`, the export `include_visits` and `channel` column, the `track_visits` setting and the reserved `visit` slug.
 */
class RestController {

	/**
	 * Register REST routes.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Added the consent_category argument to the settings route.
	 * @since 1.0.0 Added the track_visits settings argument and the export include_visits argument.
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'utmat/v1',
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_stats' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'period'   => array(
						'default'           => 30,
						'sanitize_callback' => 'absint',
					),
					'campaign' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'since'    => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'until'    => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'utmat/v1',
			'/links',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'get_links' ),
					'permission_callback' => array( self::class, 'check_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'create_link' ),
					'permission_callback' => array( self::class, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			'utmat/v1',
			'/links/(?P<id>[a-z0-9_\-]+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( self::class, 'update_link' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( self::class, 'delete_link' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			'utmat/v1',
			'/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'export_events' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'period'         => array(
						'default'           => 30,
						'sanitize_callback' => 'absint',
					),
					'campaign'       => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'since'          => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'until'          => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'include_visits' => array(
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			'utmat/v1',
			'/presets',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create_preset' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);

		register_rest_route(
			'utmat/v1',
			'/presets/(?P<id>[a-z0-9_\-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'delete_preset' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'utmat/v1',
			'/test-email',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'send_test_email' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);

		register_rest_route(
			'utmat/v1',
			'/test-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'send_test_webhook' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);

		register_rest_route(
			'utmat/v1',
			'/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'save_settings' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'retention_days'   => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'weekly_email'     => array(
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'cookie_days'      => array(
						'default'           => 90,
						'sanitize_callback' => 'absint',
					),
					'webhook_url'      => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'consent_category' => array(
						'default'           => 'marketing',
						'sanitize_callback' => 'sanitize_key',
					),
					'track_visits'     => array(
						'default'           => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			'utmat/v1',
			'/diagnostics',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_diagnostics' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);
	}

	/**
	 * Permission check — admin only.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET utmat/v1/stats
	 *
	 * Rows are stored in UTC; day grouping and the period boundary use the
	 * site timezone by shifting `clicked_at` with the site's current UTC
	 * offset. Known limitation: rows recorded under a different DST offset
	 * than today's can shift by an hour — acceptable for daily aggregation.
	 *
	 * Click-oriented queries exclude conversion rows (`is_conversion = 1`,
	 * written by the WooCommerce integration) so a converting visit is not
	 * double-counted as a click, and untagged landing visits (`link_id =
	 * 'visit'`), which only the `by_channel` breakdown reports.
	 *
	 * @since 1.0.0 Table name passed through esc_sql() before interpolation.
	 * @since 1.0.0 Click metrics exclude untagged landing visits; `by_channel` replaces `by_traffic_source`.
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_stats( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table    = esc_sql( Schema::table() );
		$campaign = (string) $request->get_param( 'campaign' );

		$timezone  = wp_timezone();
		$now_local = self::resolve_now_local( $request, $timezone );
		$period    = self::resolve_period( $request, $now_local );
		$offset    = (int) $timezone->getOffset( $now_local );

		$where = self::build_where( $period, $campaign, $now_local );
		// Untagged landing visits are not clicks: every click metric
		// excludes them; only the Channels breakdown below includes them.
		$click_where = $where . " AND is_conversion = 0 AND link_id != 'visit'";

		// Clicks by day, grouped on the site-local date.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- real-time analytics table, no cache layer by design; WHERE clauses are built by build_where() through $wpdb->prepare()
		$by_day_raw = $wpdb->get_results(
			"SELECT DATE( DATE_ADD( clicked_at, INTERVAL {$offset} SECOND ) ) AS day, COUNT(*) AS count
			 FROM {$table}
			 WHERE {$click_where}
			 GROUP BY DATE( DATE_ADD( clicked_at, INTERVAL {$offset} SECOND ) )
			 ORDER BY day ASC",
			ARRAY_A
		);
		// phpcs:enable

		// Conversions by day (WooCommerce order rows), same local-date grouping.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$conv_day_raw = $wpdb->get_results(
			"SELECT DATE( DATE_ADD( clicked_at, INTERVAL {$offset} SECOND ) ) AS day, COUNT(*) AS count
			 FROM {$table}
			 WHERE {$where} AND is_conversion = 1
			 GROUP BY DATE( DATE_ADD( clicked_at, INTERVAL {$offset} SECOND ) )
			 ORDER BY day ASC",
			ARRAY_A
		);
		// phpcs:enable

		// Build a full date scaffold so days with zero clicks still appear.
		$by_day   = array();
		$conv_day = array();
		for ( $i = $period; $i >= 0; $i-- ) {
			$day              = $now_local->modify( "-{$i} days" )->format( 'Y-m-d' );
			$by_day[ $day ]   = 0;
			$conv_day[ $day ] = 0;
		}
		foreach ( (array) $by_day_raw as $row ) {
			if ( isset( $row['day'] ) ) {
				$by_day[ $row['day'] ] = (int) $row['count'];
			}
		}
		foreach ( (array) $conv_day_raw as $row ) {
			if ( isset( $row['day'], $conv_day[ $row['day'] ] ) ) {
				$conv_day[ $row['day'] ] = (int) $row['count'];
			}
		}

		// Previous-period clicks by day, for the chart overlay — a full
		// calendar-day window exactly `$period` days earlier than the current
		// one (distinct from build_previous_where()'s elapsed-duration window
		// used by the trend indicator, which needs an equal-duration
		// comparison rather than day-aligned buckets). Positions align with
		// $by_day (both oldest-to-newest, $period + 1 entries) rather than
		// actual dates, since the two windows cover different calendar days.
		$prev_day_where = self::build_previous_day_where( $period, $campaign, $now_local ) . " AND is_conversion = 0 AND link_id != 'visit'";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$prev_day_raw = $wpdb->get_results(
			"SELECT DATE( DATE_ADD( clicked_at, INTERVAL {$offset} SECOND ) ) AS day, COUNT(*) AS count
			 FROM {$table}
			 WHERE {$prev_day_where}
			 GROUP BY DATE( DATE_ADD( clicked_at, INTERVAL {$offset} SECOND ) )
			 ORDER BY day ASC",
			ARRAY_A
		);
		// phpcs:enable

		$prev_day = array();
		for ( $i = $period; $i >= 0; $i-- ) {
			$prev_day[ $now_local->modify( '-' . ( $i + $period + 1 ) . ' days' )->format( 'Y-m-d' ) ] = 0;
		}
		foreach ( (array) $prev_day_raw as $row ) {
			if ( isset( $row['day'], $prev_day[ $row['day'] ] ) ) {
				$prev_day[ $row['day'] ] = (int) $row['count'];
			}
		}
		$previous_clicks_by_day = array_values( $prev_day );

		// By source.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$by_source = $wpdb->get_results(
			"SELECT utm_source AS label, COUNT(*) AS count
			 FROM {$table}
			 WHERE {$click_where} AND utm_source != ''
			 GROUP BY utm_source
			 ORDER BY count DESC
			 LIMIT 10",
			ARRAY_A
		);
		// phpcs:enable

		// By campaign.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$by_campaign = $wpdb->get_results(
			"SELECT utm_campaign AS label, COUNT(*) AS count
			 FROM {$table}
			 WHERE {$click_where} AND utm_campaign != ''
			 GROUP BY utm_campaign
			 ORDER BY count DESC
			 LIMIT 10",
			ARRAY_A
		);
		// phpcs:enable

		// By medium.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$by_medium = $wpdb->get_results(
			"SELECT utm_medium AS label, COUNT(*) AS count
			 FROM {$table}
			 WHERE {$click_where} AND utm_medium != ''
			 GROUP BY utm_medium
			 ORDER BY count DESC
			 LIMIT 10",
			ARRAY_A
		);
		// phpcs:enable

		// By device type — computed once at insert time (Tracker::device_type),
		// so this is a plain GROUP BY like every other breakdown.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$by_device = $wpdb->get_results(
			"SELECT device AS label, COUNT(*) AS count
			 FROM {$table}
			 WHERE {$click_where} AND device != ''
			 GROUP BY device
			 ORDER BY count DESC",
			ARRAY_A
		);
		// phpcs:enable

		// By channel — the stored write-time channel, with rows written before
		// the column existed mapped through the legacy rule set. This is the
		// one breakdown that includes untagged landing visits, so it uses the
		// plain period WHERE rather than $click_where.
		$channel_case = TrafficSource::sql_channel_expression();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- CASE expression built only from class constants passed through esc_sql()
		$by_channel = $wpdb->get_results(
			"SELECT {$channel_case} AS label, COUNT(*) AS count
			 FROM {$table}
			 WHERE {$where} AND is_conversion = 0
			 GROUP BY label
			 ORDER BY count DESC",
			ARRAY_A
		);
		// phpcs:enable

		// Top landing pages — passive incoming visits only (redirect-link rows
		// store the outbound destination, not a landing page). The query string
		// is stripped so UTM variants of one URL group into a single row.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$by_landing = $wpdb->get_results(
			"SELECT SUBSTRING_INDEX( destination, '?', 1 ) AS label, COUNT(*) AS count
			 FROM {$table}
			 WHERE {$click_where} AND link_id = 'incoming' AND destination != ''
			 GROUP BY SUBSTRING_INDEX( destination, '?', 1 )
			 ORDER BY count DESC
			 LIMIT 10",
			ARRAY_A
		);
		// phpcs:enable

		// Top referrers — grouped by the referrer's host so every path on a
		// referring site collapses into one row; self-referrals (internal
		// navigation) are excluded.
		$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$by_referrer = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT SUBSTRING_INDEX( SUBSTRING_INDEX( referrer, '/', 3 ), '//', -1 ) AS label, COUNT(*) AS count
				 FROM {$table}
				 WHERE {$click_where} AND referrer != ''
				   AND SUBSTRING_INDEX( SUBSTRING_INDEX( referrer, '/', 3 ), '//', -1 ) != %s
				 GROUP BY label
				 ORDER BY count DESC
				 LIMIT 10",
				$site_host
			),
			ARRAY_A
		);
		// phpcs:enable

		// Ad-platform breakdown — derived from which ad click ID a click
		// carried. Rows without any click ID (organic/UTM-only traffic) are
		// excluded; the dashboard maps the keys to display names.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$by_platform = $wpdb->get_results(
			"SELECT CASE
				WHEN gclid != '' THEN 'google'
				WHEN fbclid != '' THEN 'facebook'
				WHEN msclkid != '' THEN 'microsoft'
				WHEN ttclid != '' THEN 'tiktok'
				WHEN li_fat_id != '' THEN 'linkedin'
				WHEN twclid != '' THEN 'twitter'
			 END AS label, COUNT(*) AS count
			 FROM {$table}
			 WHERE {$click_where} AND ( gclid != '' OR fbclid != '' OR msclkid != '' OR ttclid != '' OR li_fat_id != '' OR twclid != '' )
			 GROUP BY label
			 ORDER BY count DESC",
			ARRAY_A
		);
		// phpcs:enable

		// Revenue by campaign — conversion rows only (WooCommerce orders).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$revenue_raw = $wpdb->get_results(
			"SELECT utm_campaign AS label, COUNT(*) AS orders, SUM(order_total) AS revenue
			 FROM {$table}
			 WHERE {$where} AND is_conversion = 1 AND utm_campaign != ''
			 GROUP BY utm_campaign
			 ORDER BY revenue DESC
			 LIMIT 10",
			ARRAY_A
		);
		// phpcs:enable

		$revenue_by_campaign = array();
		foreach ( (array) $revenue_raw as $row ) {
			$revenue_by_campaign[] = array(
				'label'   => (string) $row['label'],
				'orders'  => (int) $row['orders'],
				'revenue' => round( (float) $row['revenue'], 2 ),
			);
		}

		// Total.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$click_where}" );

		// All-time event count — drives the dashboard's first-run onboarding.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_time_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		// Conversions in the period (WooCommerce order rows) for the totals row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$conversions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where} AND is_conversion = 1" );

		// Previous-period total — the same-length window immediately before
		// this one, for the trend indicator next to the total.
		$prev_where = self::build_previous_where( $period, $campaign, $now_local ) . " AND is_conversion = 0 AND link_id != 'visit'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$previous_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$prev_where}" );

		// Distinct campaigns for filter dropdown.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		$campaigns = $wpdb->get_col(
			"SELECT DISTINCT utm_campaign FROM {$table} WHERE is_conversion = 0 AND utm_campaign != '' ORDER BY utm_campaign"
		);
		// phpcs:enable

		$clicks_by_day = array();
		foreach ( $by_day as $date => $count ) {
			$clicks_by_day[] = array(
				'date'        => $date,
				'count'       => $count,
				'conversions' => $conv_day[ $date ] ?? 0,
			);
		}

		return new WP_REST_Response(
			array(
				'clicks_by_day'          => $clicks_by_day,
				'by_source'              => array_values( (array) $by_source ),
				'by_campaign'            => array_values( (array) $by_campaign ),
				'by_medium'              => array_values( (array) $by_medium ),
				'by_device'              => array_values( (array) $by_device ),
				'by_channel'             => array_values( (array) $by_channel ),
				'by_landing'             => array_values( (array) $by_landing ),
				'by_platform'            => array_values( (array) $by_platform ),
				'by_referrer'            => array_values( (array) $by_referrer ),
				'revenue_by_campaign'    => $revenue_by_campaign,
				'total'                  => $total,
				'previous_total'         => $previous_total,
				'previous_clicks_by_day' => $previous_clicks_by_day,
				'conversions'            => $conversions,
				'all_time_total'         => $all_time_total,
				'campaigns'              => array_values( (array) $campaigns ),
			),
			200
		);
	}

	/**
	 * Resolve the reference "now" instant a stats/export request anchors to.
	 *
	 * Normally the actual current moment. When a valid `until` date (Y-m-d,
	 * not in the future) is supplied for a custom range, the reference point
	 * becomes 23:59:59 site-local on that date instead — every date-relative
	 * calculation downstream (`build_where()`'s upper bound, the day
	 * scaffolds, the previous-period comparisons) is written in terms of this
	 * single anchor, so a custom range reuses the exact same logic a preset
	 * period does. An invalid or future `until` is ignored and real "now" is
	 * used, so malformed input degrades to normal preset behaviour rather
	 * than erroring.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request  REST request.
	 * @param DateTimeZone    $timezone Site timezone.
	 * @return DateTimeImmutable
	 */
	private static function resolve_now_local( WP_REST_Request $request, DateTimeZone $timezone ): DateTimeImmutable {
		$now_local = new DateTimeImmutable( 'now', $timezone );
		$until_raw = (string) $request->get_param( 'until' );

		if ( '' === $until_raw || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until_raw ) ) {
			return $now_local;
		}

		$until_local = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $until_raw . ' 23:59:59', $timezone );
		if ( false === $until_local || $until_local > $now_local ) {
			return $now_local;
		}

		return $until_local;
	}

	/**
	 * Resolve the period length in days for a stats/export request.
	 *
	 * When valid `since` and `until` dates (Y-m-d, since <= until) are both
	 * supplied, the period is the inclusive day-count between them; otherwise
	 * falls back to the `period` integer parameter, clamped to [1, 365] as
	 * before. `$now_local` must already reflect {@see resolve_now_local()}
	 * (its date is the effective `until` when a custom range is in play).
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request   $request   REST request.
	 * @param DateTimeImmutable $now_local Resolved reference instant.
	 * @return int
	 */
	private static function resolve_period( WP_REST_Request $request, DateTimeImmutable $now_local ): int {
		$since_raw = (string) $request->get_param( 'since' );
		$until_raw = (string) $request->get_param( 'until' );

		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since_raw ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until_raw ) ) {
			$since_date = DateTimeImmutable::createFromFormat( 'Y-m-d', $since_raw, $now_local->getTimezone() );
			if ( false !== $since_date && $since_date->format( 'Y-m-d' ) <= $now_local->format( 'Y-m-d' ) ) {
				$days = (int) $since_date->setTime( 0, 0, 0 )->diff( $now_local->setTime( 0, 0, 0 ) )->days;
				if ( $days >= 0 ) {
					return max( 1, min( 365, $days ) );
				}
			}
		}

		return max( 1, min( 365, (int) $request->get_param( 'period' ) ) );
	}

	/**
	 * Build the prepared WHERE clause shared by the stats and export queries.
	 *
	 * The period boundary is midnight (site-local) `$period` days before
	 * `$now_local`, converted to UTC to match the stored `clicked_at` values.
	 * An upper bound of `$now_local` itself is also applied — for a live
	 * (non-custom-range) request this is a no-op, since no row's `clicked_at`
	 * can be later than the actual current moment, but it is what correctly
	 * bounds a custom past date range instead of leaking events after it. An
	 * optional campaign filter is appended.
	 *
	 * @since 1.0.0 Added the upper bound so a custom `until` date is respected.
	 * @param int               $period    Period length in days.
	 * @param string            $campaign  Optional utm_campaign filter; empty for all.
	 * @param DateTimeImmutable $now_local Reference instant (real "now", or a resolved custom `until`).
	 * @return string Prepared SQL WHERE clause (without the WHERE keyword).
	 */
	private static function build_where( int $period, string $campaign, DateTimeImmutable $now_local ): string {
		global $wpdb;

		$utc         = new DateTimeZone( 'UTC' );
		$since_local = $now_local->modify( "-{$period} days" )->setTime( 0, 0, 0 );
		$since       = $since_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$until       = $now_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

		$where = $wpdb->prepare( 'clicked_at >= %s AND clicked_at <= %s', $since, $until );
		if ( ! empty( $campaign ) ) {
			$where .= $wpdb->prepare( ' AND utm_campaign = %s', $campaign );
		}

		return $where;
	}

	/**
	 * Build the prepared WHERE clause for the calendar-day window that lines
	 * up with the chart's previous-period overlay.
	 *
	 * Covers the `$period + 1` site-local calendar days immediately before
	 * the current window's days — a day-aligned window, distinct from
	 * {@see build_previous_where()}'s equal-elapsed-duration window used by
	 * the trend indicator (that window can end mid-day; this one always ends
	 * at a local midnight so it lines up with $by_day's date buckets one-for-one).
	 *
	 * @since 1.0.0 Now takes the caller-resolved $now_local instead of the live instant.
	 * @param int               $period    Period length in days.
	 * @param string            $campaign  Optional utm_campaign filter; empty for all.
	 * @param DateTimeImmutable $now_local Reference instant (real "now", or a resolved custom `until`).
	 * @return string Prepared SQL WHERE clause (without the WHERE keyword).
	 */
	private static function build_previous_day_where( int $period, string $campaign, DateTimeImmutable $now_local ): string {
		global $wpdb;

		$utc = new DateTimeZone( 'UTC' );

		$since_local = $now_local->modify( '-' . ( 2 * $period + 1 ) . ' days' )->setTime( 0, 0, 0 );
		$until_local = $now_local->modify( "-{$period} days" )->setTime( 0, 0, 0 );

		$where = $wpdb->prepare(
			'clicked_at >= %s AND clicked_at < %s',
			$since_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$until_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' )
		);
		if ( ! empty( $campaign ) ) {
			$where .= $wpdb->prepare( ' AND utm_campaign = %s', $campaign );
		}

		return $where;
	}

	/**
	 * Build the prepared WHERE clause for the period immediately before the
	 * requested one.
	 *
	 * Starts at site-local midnight `2 × $period` days ago and spans exactly
	 * the same duration as the current window has elapsed so far (period days
	 * plus the partial current day). Comparing equal durations keeps the
	 * trend percentage fair early in the day, when the current window has
	 * only a few hours of today in it. Same optional campaign filter as
	 * {@see build_where()}.
	 *
	 * @since 1.0.0 Previous window now matches the current window's elapsed duration, and takes the caller-resolved $now_local.
	 * @param int               $period    Period length in days.
	 * @param string            $campaign  Optional utm_campaign filter; empty for all.
	 * @param DateTimeImmutable $now_local Reference instant (real "now", or a resolved custom `until`).
	 * @return string Prepared SQL WHERE clause (without the WHERE keyword).
	 */
	private static function build_previous_where( int $period, string $campaign, DateTimeImmutable $now_local ): string {
		global $wpdb;

		$utc    = new DateTimeZone( 'UTC' );
		$double = 2 * $period;

		$since_current = $now_local->modify( "-{$period} days" )->setTime( 0, 0, 0 );
		$since_prev    = $now_local->modify( "-{$double} days" )->setTime( 0, 0, 0 );
		$elapsed       = $now_local->getTimestamp() - $since_current->getTimestamp();
		$prev_end      = $since_prev->modify( '+' . $elapsed . ' seconds' );

		$where = $wpdb->prepare(
			'clicked_at >= %s AND clicked_at < %s',
			$since_prev->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$prev_end->setTimezone( $utc )->format( 'Y-m-d H:i:s' )
		);
		if ( ! empty( $campaign ) ) {
			$where .= $wpdb->prepare( ' AND utm_campaign = %s', $campaign );
		}

		return $where;
	}

	/**
	 * GET utmat/v1/export
	 *
	 * Returns raw event rows for the period as JSON; the dashboard assembles
	 * the CSV client-side. Exports are privacy-light by design: `ip_hash` and
	 * `user_agent` are never included. Capped at the newest 10,000 rows.
	 * Conversion rows are included and flagged via the `is_conversion` column.
	 * Untagged landing visits are left out unless `include_visits` is set;
	 * every row carries its `channel`.
	 *
	 * @since 1.0.0 Table name passed through esc_sql() before interpolation.
	 * @since 1.0.0 Added the `channel` column and the `include_visits` opt-in.
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function export_events( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table    = esc_sql( Schema::table() );
		$campaign = (string) $request->get_param( 'campaign' );

		$now_local = self::resolve_now_local( $request, wp_timezone() );
		$period    = self::resolve_period( $request, $now_local );
		$where     = self::build_where( $period, $campaign, $now_local );
		if ( ! $request->get_param( 'include_visits' ) ) {
			$where .= " AND link_id != 'visit'";
		}
		$channel_case = TrafficSource::sql_channel_expression();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			"SELECT clicked_at, link_id, {$channel_case} AS channel, utm_source, utm_medium, utm_campaign, utm_id, utm_term, utm_content,
			        gclid, fbclid, msclkid, ttclid, li_fat_id, twclid, destination, referrer, is_conversion, order_total
			 FROM {$table}
			 WHERE {$where}
			 ORDER BY clicked_at DESC
			 LIMIT 10000",
			ARRAY_A
		);
		// phpcs:enable

		return new WP_REST_Response( array( 'rows' => array_values( (array) $rows ) ), 200 );
	}

	/**
	 * POST utmat/v1/test-email — send the weekly summary now.
	 *
	 * Sends the report regardless of the weekly toggle so admins can preview
	 * the email and confirm delivery works before enabling the schedule.
	 *
	 * @since 1.0.0
	 * @return WP_REST_Response
	 */
	public static function send_test_email(): WP_REST_Response {
		return new WP_REST_Response( array( 'sent' => WeeklyEmail::send( true ) ), 200 );
	}

	/**
	 * POST utmat/v1/test-webhook — send a synthetic conversion payload now.
	 *
	 * Lets the admin verify their receiver and HMAC signature checking work
	 * before relying on the webhook for real orders. No-ops (returns
	 * sent:false) when no URL is configured.
	 *
	 * @since 1.0.0
	 * @return WP_REST_Response
	 */
	public static function send_test_webhook(): WP_REST_Response {
		return new WP_REST_Response( array( 'sent' => ConversionWebhook::send_test() ), 200 );
	}

	/**
	 * GET utmat/v1/diagnostics — run the Tracking Doctor checks.
	 *
	 * Computed fresh on every request; every check is a cheap local read
	 * (option/meta reads, a bounded orders query), never cached.
	 *
	 * @since 1.0.0
	 * @return WP_REST_Response
	 */
	public static function get_diagnostics(): WP_REST_Response {
		return new WP_REST_Response( array( 'checks' => Checks::run_all() ), 200 );
	}

	/**
	 * POST utmat/v1/settings
	 *
	 * Saves the retention window (0 = keep forever), the weekly email
	 * toggle, the conversion webhook URL (empty disables it), the consent
	 * category that gates the attribution cookie (allow-listed, anything
	 * else falls back to marketing) and the untagged-visit tracking switch.
	 * The weekly report cron is scheduled or unscheduled here, at save
	 * time, so no scheduling work happens on normal page loads.
	 *
	 * @since 1.0.0 Added the webhook_url setting.
	 * @since 1.0.0 Added the consent_category setting.
	 * @since 1.0.0 Added the track_visits setting.
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$retention = absint( $request->get_param( 'retention_days' ) );
		$weekly    = (bool) $request->get_param( 'weekly_email' );
		$cookie    = absint( $request->get_param( 'cookie_days' ) );
		$cookie    = min( 730, max( 1, $cookie ? $cookie : 90 ) );
		$webhook   = esc_url_raw( self::param_string( $request, 'webhook_url' ), array( 'http', 'https' ) );
		$consent   = self::param_string( $request, 'consent_category' );
		if ( ! in_array( $consent, Attribution::CONSENT_CATEGORIES, true ) ) {
			$consent = 'marketing';
		}
		$visits = (bool) $request->get_param( 'track_visits' );

		update_option( 'utmat_retention_days', $retention );
		update_option( WeeklyEmail::OPTION, $weekly ? 1 : 0 );
		update_option( 'utmat_cookie_days', $cookie );
		update_option( ConversionWebhook::OPTION_URL, $webhook );
		update_option( Attribution::OPTION_CONSENT_CATEGORY, $consent );
		update_option( 'utmat_track_visits', $visits ? 1 : 0 );

		if ( $weekly ) {
			WeeklyEmail::schedule();
		} else {
			WeeklyEmail::unschedule();
		}

		return new WP_REST_Response(
			array(
				'retention_days'   => $retention,
				'weekly_email'     => $weekly,
				'cookie_days'      => $cookie,
				'webhook_url'      => $webhook,
				'consent_category' => $consent,
				'track_visits'     => $visits,
			),
			200
		);
	}

	/**
	 * GET utmat/v1/links
	 *
	 * Click counts for all links come from a single GROUP BY query instead
	 * of one COUNT query per link. The response also carries a `suggestions`
	 * map of previously used source/medium/campaign values for the add-link
	 * form datalists.
	 *
	 * @since 1.0.0 Response shape changed from a bare array to `{ links, suggestions }`.
	 * @since 1.0.0 Table name passed through esc_sql() before interpolation.
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_links( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table  = esc_sql( Schema::table() );
		$links  = LinkManager::all();
		$result = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		$counts_raw = $wpdb->get_results(
			"SELECT link_id, COUNT(*) AS count FROM {$table} WHERE link_id != '' GROUP BY link_id",
			ARRAY_A
		);
		// phpcs:enable

		$counts = array();
		foreach ( (array) $counts_raw as $row ) {
			$counts[ (string) $row['link_id'] ] = (int) $row['count'];
		}

		foreach ( $links as $id => $link ) {
			$result[] = self::format_link( $id, $link, $counts[ $id ] ?? 0 );
		}

		return new WP_REST_Response(
			array(
				'links'       => $result,
				'suggestions' => self::suggestions( $links ),
				'presets'     => self::presets(),
			),
			200
		);
	}

	/**
	 * Return the saved UTM presets for the add-link form's preset dropdown.
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, string>>
	 */
	private static function presets(): array {
		$out = array();
		foreach ( (array) get_option( 'utmat_presets', array() ) as $id => $preset ) {
			if ( ! is_array( $preset ) ) {
				continue;
			}
			$out[] = array(
				'id'          => (string) $id,
				'name'        => (string) ( $preset['name'] ?? $id ),
				'utm_source'  => (string) ( $preset['utm_source'] ?? '' ),
				'utm_medium'  => (string) ( $preset['utm_medium'] ?? '' ),
				'utm_term'    => (string) ( $preset['utm_term'] ?? '' ),
				'utm_content' => (string) ( $preset['utm_content'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * POST utmat/v1/presets — save a named UTM preset.
	 *
	 * A preset is a reusable source/medium/term/content combination (e.g.
	 * "Instagram Story") the admin can apply to the add-link form instead of
	 * retyping the same values for every new campaign link. Saving a name
	 * that already exists overwrites that preset — this is an explicit,
	 * low-stakes admin action, not data with history worth preserving.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function create_preset( WP_REST_Request $request ): WP_REST_Response {
		$name = sanitize_text_field( self::param_string( $request, 'name' ) );
		$id   = sanitize_title( $name );

		if ( '' === $id ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Please provide a preset name.', 'utm-attribution-tracker' ) ),
				400
			);
		}

		$presets        = (array) get_option( 'utmat_presets', array() );
		$presets[ $id ] = array(
			'name'        => $name,
			'utm_source'  => self::normalize_utm( sanitize_text_field( self::param_string( $request, 'utm_source' ) ) ),
			'utm_medium'  => self::normalize_utm( sanitize_text_field( self::param_string( $request, 'utm_medium' ) ) ),
			'utm_term'    => self::normalize_utm( sanitize_text_field( self::param_string( $request, 'utm_term' ) ) ),
			'utm_content' => self::normalize_utm( sanitize_text_field( self::param_string( $request, 'utm_content' ) ) ),
		);
		update_option( 'utmat_presets', $presets );

		return new WP_REST_Response( array_merge( array( 'id' => $id ), $presets[ $id ] ), 201 );
	}

	/**
	 * DELETE utmat/v1/presets/<id> — delete a saved UTM preset.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function delete_preset( WP_REST_Request $request ): WP_REST_Response {
		$id      = (string) $request->get_param( 'id' );
		$presets = (array) get_option( 'utmat_presets', array() );

		if ( ! isset( $presets[ $id ] ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Preset not found.', 'utm-attribution-tracker' ) ),
				404
			);
		}

		unset( $presets[ $id ] );
		update_option( 'utmat_presets', $presets );

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Collect previously used UTM values for the add-link form datalists.
	 *
	 * Merges distinct values from recorded events (any row type — a value is
	 * a useful suggestion regardless of whether it came from a click or a
	 * conversion) with the values stored on managed links, deduped and capped
	 * at 20 per field.
	 *
	 * @since 1.0.0 Table name passed through esc_sql() before interpolation.
	 * @param array<string, array<string, string>> $links Managed links keyed by slug.
	 * @return array<string, string[]> Keys `sources`, `mediums`, `campaigns`.
	 */
	private static function suggestions( array $links ): array {
		global $wpdb;
		$table = esc_sql( Schema::table() );

		$columns = array(
			'sources'   => 'utm_source',
			'mediums'   => 'utm_medium',
			'campaigns' => 'utm_campaign',
		);

		$out = array();
		foreach ( $columns as $key => $column ) {
			// $column comes from the fixed map above, never from user input.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
			$values = (array) $wpdb->get_col(
				"SELECT DISTINCT {$column} FROM {$table} WHERE {$column} != '' ORDER BY {$column} LIMIT 20"
			);
			// phpcs:enable

			foreach ( $links as $link ) {
				if ( ! empty( $link[ $column ] ) ) {
					$values[] = (string) $link[ $column ];
				}
			}

			$out[ $key ] = array_slice( array_values( array_unique( array_map( 'strval', $values ) ) ), 0, 20 );
		}

		return $out;
	}

	/**
	 * Format a managed link for a REST response.
	 *
	 * Includes both the built destination (UTM parameters appended) and the
	 * raw stored fields so the dashboard edit form can pre-fill correctly.
	 *
	 * @since 1.0.0
	 * @param string                $id     Link slug.
	 * @param array<string, string> $link   Stored link fields.
	 * @param int                   $clicks Recorded click count for the slug.
	 * @return array<string, int|string>
	 */
	private static function format_link( string $id, array $link, int $clicks ): array {
		return array(
			'id'              => $id,
			'label'           => $link['label'] ?? $id,
			'destination'     => LinkManager::build_url( $link ),
			'destination_raw' => $link['destination'] ?? '',
			'redirect_url'    => LinkManager::redirect_url( $id ),
			'clicks'          => $clicks,
			'utm_campaign'    => $link['utm_campaign'] ?? '',
			'utm_source'      => $link['utm_source'] ?? '',
			'utm_medium'      => $link['utm_medium'] ?? '',
			'utm_id'          => $link['utm_id'] ?? '',
			'utm_term'        => $link['utm_term'] ?? '',
			'utm_content'     => $link['utm_content'] ?? '',
		);
	}

	/**
	 * Read a request parameter as a string, rejecting non-scalar values.
	 *
	 * A JSON body can carry arrays/objects for any parameter; casting those
	 * with `(string)` raises an Array-to-string warning and stores the
	 * literal `Array`. Non-scalar values are treated as absent instead.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @param string          $key     Parameter name.
	 * @return string
	 */
	private static function param_string( WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Validate and sanitize the link fields shared by create and update.
	 *
	 * Sanitizes the label and the six UTM fields and validates the
	 * destination as an http(s) URL. UTM values are normalized for
	 * consistent reporting (lowercase, whitespace collapsed to `-`) — the
	 * dashboard JS applies the same normalization for immediate feedback,
	 * but this server-side pass is authoritative.
	 *
	 * @since 1.0.0 Added UTM value normalization.
	 * @param WP_REST_Request $request REST request.
	 * @return array<string, string>|WP_REST_Response Sanitized link fields, or a 400 response when the destination is invalid.
	 */
	private static function validate_link_input( WP_REST_Request $request ) {
		$destination = esc_url_raw( self::param_string( $request, 'destination' ), array( 'http', 'https' ) );

		if ( '' === $destination ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Please provide a valid destination URL (http or https).', 'utm-attribution-tracker' ) ),
				400
			);
		}

		$link = array(
			'label'       => sanitize_text_field( self::param_string( $request, 'label' ) ),
			'destination' => $destination,
		);
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_term', 'utm_content' ) as $key ) {
			$link[ $key ] = self::normalize_utm( sanitize_text_field( self::param_string( $request, $key ) ) );
		}

		return $link;
	}

	/**
	 * Normalize a UTM value for consistent reporting.
	 *
	 * Lowercases the value and replaces whitespace runs with `-` so
	 * `My Newsletter` and `my-newsletter` never fragment into two report
	 * rows. Mirrors the normalization in assets/js/dashboard.js — keep in
	 * sync.
	 *
	 * @since 1.0.0
	 * @param string $value Sanitized UTM value.
	 * @return string
	 */
	private static function normalize_utm( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$value = (string) preg_replace( '/\s+/u', '-', $value );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	/**
	 * POST utmat/v1/links — create a managed link.
	 *
	 * The slug is derived from the submitted label when no explicit id is
	 * given. Fails when the slug is empty, reserved (`incoming` and `visit`
	 * name event kinds in the events table), already in use, or the
	 * destination is not a valid http(s) URL.
	 *
	 * @since 1.0.0 Also reserves the `visit` slug.
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function create_link( WP_REST_Request $request ): WP_REST_Response {
		$label    = sanitize_text_field( self::param_string( $request, 'label' ) );
		$id_param = self::param_string( $request, 'id' );
		$id       = sanitize_key( '' !== $id_param ? $id_param : sanitize_title( $label ) );

		if ( '' === $id || in_array( $id, array( 'incoming', 'visit' ), true ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Please provide a valid link slug (the slugs "incoming" and "visit" are reserved).', 'utm-attribution-tracker' ) ),
				400
			);
		}

		$link = self::validate_link_input( $request );
		if ( $link instanceof WP_REST_Response ) {
			return $link;
		}

		if ( null !== LinkManager::get( $id ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'A link with this slug already exists.', 'utm-attribution-tracker' ) ),
				409
			);
		}

		if ( '' === $link['label'] ) {
			$link['label'] = $id;
		}

		LinkManager::save( $id, $link );

		return new WP_REST_Response( self::format_link( $id, $link, 0 ), 201 );
	}

	/**
	 * PUT utmat/v1/links/<id> — update a managed link.
	 *
	 * Updates the label, destination, and the six UTM fields of an existing
	 * link. The slug is immutable — renaming would orphan recorded events.
	 * Returns 404 for an unknown slug and 400 for an invalid destination.
	 *
	 * @since 1.0.0 Table name passed through esc_sql() before interpolation.
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function update_link( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$id = (string) $request->get_param( 'id' );

		if ( null === LinkManager::get( $id ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Link not found.', 'utm-attribution-tracker' ) ),
				404
			);
		}

		$link = self::validate_link_input( $request );
		if ( $link instanceof WP_REST_Response ) {
			return $link;
		}

		if ( '' === $link['label'] ) {
			$link['label'] = $id;
		}

		LinkManager::save( $id, $link );

		$table = esc_sql( Schema::table() );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		$clicks = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE link_id = %s", $id )
		);
		// phpcs:enable

		return new WP_REST_Response( self::format_link( $id, $link, $clicks ), 200 );
	}

	/**
	 * DELETE utmat/v1/links/<id> — delete a managed link.
	 *
	 * Recorded click events are kept for historical stats; only the managed
	 * link definition is removed.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function delete_link( WP_REST_Request $request ): WP_REST_Response {
		$id = (string) $request->get_param( 'id' );

		if ( null === LinkManager::get( $id ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Link not found.', 'utm-attribution-tracker' ) ),
				404
			);
		}

		LinkManager::delete( $id );

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}
}
