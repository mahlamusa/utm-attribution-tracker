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
 *   GET    utmat/v1/events      — paginated, filterable event rows
 *   GET    utmat/v1/journeys    — conversions with their preceding touches
 *   GET    utmat/v1/journeys/<visitor_id> — one visitor's full event list
 *
 * All routes require the `manage_options` capability.
 *
 * @since 1.0.0
 *
 * @package UTMAT\Admin
 */

namespace UTMAT\Admin;

use DateTimeImmutable;
use DateTimeZone;
use stdClass;
use UTMAT\DB\Schema;
use UTMAT\Diagnostics\Checks;
use UTMAT\Reports\ConversionWebhook;
use UTMAT\Reports\WeeklyEmail;
use UTMAT\Tracking\Attribution;
use UTMAT\Tracking\LinkManager;
use UTMAT\Tracking\Tracker;
use UTMAT\Tracking\TrafficSource;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * RestController
 *
 * @since 1.0.0
 */
class RestController {

	/**
	 * The event columns the read routes select and return.
	 *
	 * The privacy-light set: `ip_hash` and `user_agent` are never included.
	 * Order matches the events table and is shared by /events, /journeys
	 * and /journeys/{visitor_id}.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const EVENT_COLUMNS = 'id, link_id, visitor_id, clicked_at, utm_source, utm_medium, utm_campaign, utm_id, utm_term, utm_content, gclid, fbclid, msclkid, ttclid, li_fat_id, twclid, destination, referrer, is_conversion, touch, order_total, device, channel, custom, meta';

	/**
	 * Register REST routes.
	 *
	 * @since 1.0.0
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
			'/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_events' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array_merge(
					self::pagination_args(),
					array(
						'period'     => array(
							'default'           => 30,
							'sanitize_callback' => 'absint',
						),
						'campaign'   => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'since'      => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'until'      => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'channel'    => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
						'kind'       => array(
							'type'              => 'string',
							'enum'              => array( 'click', 'visit', 'order', 'lead' ),
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'visitor_id' => array(
							'type'              => 'string',
							'format'            => 'uuid',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
					)
				),
			)
		);

		register_rest_route(
			'utmat/v1',
			'/journeys',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_journeys' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array_merge(
					self::pagination_args(),
					array(
						'period' => array(
							'default'           => 30,
							'sanitize_callback' => 'absint',
						),
						'since'  => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'until'  => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					)
				),
			)
		);

		register_rest_route(
			'utmat/v1',
			'/journeys/(?P<visitor_id>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_journey' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'visitor_id' => array(
						'type'              => 'string',
						'format'            => 'uuid',
						'validate_callback' => 'rest_validate_request_arg',
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
				'args'                => array(
					'preset' => array(
						'sanitize_callback' => 'sanitize_key',
					),
					'leads'  => array(
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
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
					'webhook_preset'   => array(
						'default'           => 'generic',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static function ( $value ) {
							return in_array( $value, ConversionWebhook::PRESETS, true );
						},
					),
					'webhook_orders'   => array(
						'default'           => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'webhook_leads'    => array(
						'default'           => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'consent_category' => array(
						'default'           => 'marketing',
						'sanitize_callback' => 'sanitize_key',
					),
					'track_visits'     => array(
						'default'           => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'custom_params'    => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
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
	 * written by the WooCommerce integration and the lead recorder) so a
	 * converting visit is not double-counted as a click, and untagged
	 * landing visits (`link_id =
	 * 'visit'`), which only the `by_channel` breakdown reports.
	 *
	 * @since 1.0.0
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

		// Conversions by day (WooCommerce order rows plus form lead rows), same local-date grouping.
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

		// Revenue by campaign — order conversion rows only (WooCommerce).
		// Lead conversions carry no order total and must not inflate the
		// order counts; legacy conversion rows seeded with other link ids
		// keep counting, matching the Journeys tab's kind derivation.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$revenue_raw = $wpdb->get_results(
			"SELECT utm_campaign AS label, COUNT(*) AS orders, SUM(order_total) AS revenue
			 FROM {$table}
			 WHERE {$where} AND is_conversion = 1 AND link_id != 'lead' AND utm_campaign != ''
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

		// Conversions in the period (WooCommerce order rows plus form lead
		// rows) for the totals row.
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * every row carries its `channel`, its `visitor_id` (directly after
	 * `order_total`) and one key per configured custom parameter (empty
	 * string when the row has no value; the raw `custom` JSON column is
	 * never returned).
	 *
	 * @since 1.0.0
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
			        gclid, fbclid, msclkid, ttclid, li_fat_id, twclid, destination, referrer, is_conversion, order_total, visitor_id, custom
			 FROM {$table}
			 WHERE {$where}
			 ORDER BY clicked_at DESC
			 LIMIT 10000",
			ARRAY_A
		);
		// phpcs:enable

		// Spread the `custom` JSON object into one plain key per configured
		// custom parameter name (empty string when the row carries no value),
		// so the client-side CSV gains a column per custom parameter. Plain
		// names are safe as keys: every events-table column name is reserved.
		$custom_params = Tracker::custom_params();
		foreach ( (array) $rows as $index => $row ) {
			$decoded = json_decode( (string) ( $row['custom'] ?? '' ), true );
			unset( $rows[ $index ]['custom'] );
			foreach ( $custom_params as $name ) {
				$rows[ $index ][ $name ] = isset( $decoded[ $name ] ) ? (string) $decoded[ $name ] : '';
			}
		}

		return new WP_REST_Response( array( 'rows' => array_values( (array) $rows ) ), 200 );
	}

	/**
	 * Shared page/per_page argument definitions for the paginated read routes.
	 *
	 * The handlers clamp the values (1..200 for /events, 1..100 for
	 * /journeys) and force page >= 1, so an out-of-range request degrades
	 * instead of erroring.
	 *
	 * @since 1.0.0
	 * @return array<string, array<string, mixed>>
	 */
	private static function pagination_args(): array {
		return array(
			'page'     => array(
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'default'           => 50,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * GET utmat/v1/events
	 *
	 * Paginated, filterable event rows, newest first. The date range matches
	 * /stats (resolve_now_local/resolve_period/build_where), so the two
	 * partition the same rows; `channel` filters the stored column only
	 * (legacy rows hold '' and never match); `kind` filters on the derived
	 * kind (see event_kind()); `visitor_id` accepts any UUID version, so a
	 * non-v4 value simply matches no rows.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_events( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table    = esc_sql( Schema::table() );
		$columns  = self::EVENT_COLUMNS;
		$campaign = (string) $request->get_param( 'campaign' );

		$now_local = self::resolve_now_local( $request, wp_timezone() );
		$period    = self::resolve_period( $request, $now_local );

		$where = self::build_where( $period, $campaign, $now_local );

		$channel = (string) $request->get_param( 'channel' );
		if ( '' !== $channel ) {
			$where .= $wpdb->prepare( ' AND channel = %s', $channel );
		}

		$visitor_id = (string) $request->get_param( 'visitor_id' );
		if ( '' !== $visitor_id ) {
			$where .= $wpdb->prepare( ' AND visitor_id = %s', $visitor_id );
		}

		switch ( (string) $request->get_param( 'kind' ) ) {
			case 'lead':
				$where .= " AND link_id = 'lead'";
				break;
			case 'order':
				$where .= " AND is_conversion = 1 AND link_id != 'lead'";
				break;
			case 'visit':
				$where .= " AND link_id = 'visit'";
				break;
			case 'click':
				$where .= " AND is_conversion = 0 AND link_id != 'visit'";
				break;
		}

		$per_page = min( 200, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- real-time analytics table; $columns is a class constant, $table/$where are built above through esc_sql()/$wpdb->prepare()
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT {$columns} FROM {$table} WHERE {$where} ORDER BY clicked_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		// phpcs:enable

		$events = array();
		foreach ( (array) $rows as $row ) {
			$events[] = self::format_event( (array) $row );
		}

		return new WP_REST_Response(
			array(
				'events'      => $events,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			),
			200
		);
	}

	/**
	 * GET utmat/v1/journeys
	 *
	 * One row per conversion that carries a visitor id, newest first, with
	 * the visitor's preceding events as touches. The touches are fetched one
	 * visitor at a time (indexed on visitor_id, LIMIT 1001, newest first and
	 * reversed in PHP) so one heavy visitor can never starve the others, as a
	 * single IN query with a global limit would. first_touch/last_touch/
	 * touch_count cover the whole fetched predecessor set (clicks and visits
	 * only — earlier conversions stay in the list but are not counted); the
	 * touches array is capped at the most recent 100 with a truncation flag.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_journeys( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table   = esc_sql( Schema::table() );
		$columns = self::EVENT_COLUMNS;

		$now_local = self::resolve_now_local( $request, wp_timezone() );
		$period    = self::resolve_period( $request, $now_local );
		$where     = self::build_where( $period, '', $now_local );

		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$conv_where = "is_conversion = 1 AND visitor_id != '' AND {$where}";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- real-time analytics table; see get_events()
		$conversions     = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT {$columns} FROM {$table} WHERE {$conv_where} ORDER BY clicked_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ),
			ARRAY_A
		);
		$total           = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$conv_where}" );
		$without_visitor = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_conversion = 1 AND visitor_id = '' AND {$where}" );
		// phpcs:enable

		$by_visitor = array();
		$journeys   = array();

		foreach ( $conversions as $conversion ) {
			$visitor_id = (string) $conversion['visitor_id'];

			// One fetch per distinct visitor on the page, bound to their
			// newest conversion here (the page is ordered newest first).
			if ( ! isset( $by_visitor[ $visitor_id ] ) ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- see get_events()
				$fetched = (array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT {$columns} FROM {$table} WHERE visitor_id = %s AND ( clicked_at < %s OR ( clicked_at = %s AND id <= %d ) ) ORDER BY clicked_at DESC, id DESC LIMIT 1001",
						$visitor_id,
						(string) $conversion['clicked_at'],
						(string) $conversion['clicked_at'],
						(int) $conversion['id']
					),
					ARRAY_A
				);
				// phpcs:enable
				$visitor_truncated = ( 1001 === count( $fetched ) );
				if ( $visitor_truncated ) {
					array_pop( $fetched );
				}
				// Reverse to ascending: the fetch cap cut the oldest rows,
				// which is what keeps the conversion and its nearest touches.
				$by_visitor[ $visitor_id ] = array(
					'rows'      => array_reverse( $fetched ),
					'truncated' => $visitor_truncated,
				);
			}

			$visitor = $by_visitor[ $visitor_id ];

			$predecessors = array();
			foreach ( $visitor['rows'] as $row ) {
				if ( (string) $row['clicked_at'] < (string) $conversion['clicked_at']
					|| ( (string) $row['clicked_at'] === (string) $conversion['clicked_at'] && (int) $row['id'] < (int) $conversion['id'] ) ) {
					$predecessors[] = $row;
				}
			}

			$first_touch = null;
			$last_touch  = null;
			$touch_count = 0;
			foreach ( $predecessors as $row ) {
				$kind = self::event_kind( $row );
				if ( 'click' !== $kind && 'visit' !== $kind ) {
					continue;
				}
				++$touch_count;
				if ( null === $first_touch ) {
					$first_touch = $row;
				}
				$last_touch = $row;
			}

			$touches = count( $predecessors ) > 100 ? array_slice( $predecessors, -100 ) : $predecessors;

			$journey = array(
				'conversion'        => self::format_event( $conversion ),
				'visitor_id'        => $visitor_id,
				'first_touch'       => null === $first_touch ? null : self::format_event( $first_touch ),
				'last_touch'        => null === $last_touch ? null : self::format_event( $last_touch ),
				'touch_count'       => $touch_count,
				'touches'           => array(),
				'touches_truncated' => count( $predecessors ) > 100 || $visitor['truncated'],
			);
			foreach ( $touches as $touch ) {
				$journey['touches'][] = self::format_event( $touch );
			}
			$journeys[] = $journey;
		}

		return new WP_REST_Response(
			array(
				'journeys'        => $journeys,
				'total'           => $total,
				'page'            => $page,
				'per_page'        => $per_page,
				'total_pages'     => (int) ceil( $total / $per_page ),
				'without_visitor' => $without_visitor,
			),
			200
		);
	}

	/**
	 * GET utmat/v1/journeys/<visitor_id>
	 *
	 * The visitor's full event list, ascending. Fetched newest-first with a
	 * LIMIT of 501 and reversed in PHP so a cut drops the oldest rows, never
	 * the newest. `conversions` counts the returned rows only.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_journey( WP_REST_Request $request ) {
		global $wpdb;
		$table      = esc_sql( Schema::table() );
		$columns    = self::EVENT_COLUMNS;
		$visitor_id = (string) $request->get_param( 'visitor_id' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- see get_events()
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT {$columns} FROM {$table} WHERE visitor_id = %s ORDER BY clicked_at DESC, id DESC LIMIT 501", $visitor_id ),
			ARRAY_A
		);
		// phpcs:enable

		if ( array() === $rows ) {
			return new WP_Error(
				'utmat_not_found',
				__( 'No events recorded for this visitor.', 'utm-attribution-tracker' ),
				array( 'status' => 404 )
			);
		}

		$truncated = ( 501 === count( $rows ) );
		if ( $truncated ) {
			array_pop( $rows );
		}
		$rows = array_reverse( $rows );

		$events      = array();
		$conversions = 0;
		$first_seen  = '';
		$last_seen   = '';
		foreach ( $rows as $row ) {
			if ( ! empty( $row['is_conversion'] ) ) {
				++$conversions;
			}
			if ( '' === $first_seen ) {
				$first_seen = (string) $row['clicked_at'];
			}
			$last_seen = (string) $row['clicked_at'];
			$events[]  = self::format_event( $row );
		}

		return new WP_REST_Response(
			array(
				'visitor_id'  => $visitor_id,
				'events'      => $events,
				'conversions' => $conversions,
				'first_seen'  => $first_seen,
				'last_seen'   => $last_seen,
				'truncated'   => $truncated,
			),
			200
		);
	}

	/**
	 * Derive an event's kind from its row.
	 *
	 * Never stored: `lead` rows are form submissions, `order` covers the
	 * WooCommerce rows plus legacy seeded conversions (which were only ever
	 * orders), `visit` is an untagged landing, and everything else —
	 * `incoming` tagged visits and short-link slugs — is a click, matching
	 * how every existing click metric counts them.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $row Event row (ARRAY_A).
	 * @return string One of 'click', 'visit', 'order', 'lead'.
	 */
	private static function event_kind( array $row ): string {
		$link_id = (string) ( $row['link_id'] ?? '' );

		if ( 'lead' === $link_id ) {
			return 'lead';
		}
		if ( 'order' === $link_id || ! empty( $row['is_conversion'] ) ) {
			return 'order';
		}
		if ( 'visit' === $link_id ) {
			return 'visit';
		}
		return 'click';
	}

	/**
	 * Serialise one event row for the read routes.
	 *
	 * Casts id/is_conversion/order_total, leaves clicked_at exactly as stored
	 * (UTC `Y-m-d H:i:s`) and emits `channel` as stored ('' on legacy rows).
	 * The two JSON columns are always objects: decoded without `assoc` and
	 * replaced with an empty stdClass when NULL, empty or undecodable, so the
	 * response encodes `{}` and never `[]`. `ip_hash` and `user_agent` are
	 * never selected (see EVENT_COLUMNS).
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $row Event row (ARRAY_A).
	 * @return array<string, mixed>
	 */
	private static function format_event( array $row ): array {
		$custom = json_decode( (string) ( $row['custom'] ?? '' ) );
		$meta   = json_decode( (string) ( $row['meta'] ?? '' ) );

		return array(
			'id'            => (int) $row['id'],
			'link_id'       => (string) ( $row['link_id'] ?? '' ),
			'visitor_id'    => (string) ( $row['visitor_id'] ?? '' ),
			'clicked_at'    => (string) ( $row['clicked_at'] ?? '' ),
			'utm_source'    => (string) ( $row['utm_source'] ?? '' ),
			'utm_medium'    => (string) ( $row['utm_medium'] ?? '' ),
			'utm_campaign'  => (string) ( $row['utm_campaign'] ?? '' ),
			'utm_id'        => (string) ( $row['utm_id'] ?? '' ),
			'utm_term'      => (string) ( $row['utm_term'] ?? '' ),
			'utm_content'   => (string) ( $row['utm_content'] ?? '' ),
			'gclid'         => (string) ( $row['gclid'] ?? '' ),
			'fbclid'        => (string) ( $row['fbclid'] ?? '' ),
			'msclkid'       => (string) ( $row['msclkid'] ?? '' ),
			'ttclid'        => (string) ( $row['ttclid'] ?? '' ),
			'li_fat_id'     => (string) ( $row['li_fat_id'] ?? '' ),
			'twclid'        => (string) ( $row['twclid'] ?? '' ),
			'destination'   => (string) ( $row['destination'] ?? '' ),
			'referrer'      => (string) ( $row['referrer'] ?? '' ),
			'is_conversion' => (bool) (int) ( $row['is_conversion'] ?? 0 ),
			'touch'         => (string) ( $row['touch'] ?? '' ),
			'order_total'   => ( isset( $row['order_total'] ) && null !== $row['order_total'] && '' !== $row['order_total'] ) ? (float) $row['order_total'] : null,
			'device'        => (string) ( $row['device'] ?? '' ),
			'channel'       => (string) ( $row['channel'] ?? '' ),
			'custom'        => ( $custom instanceof stdClass ) ? $custom : new stdClass(),
			'meta'          => ( $meta instanceof stdClass ) ? $meta : new stdClass(),
			'kind'          => self::event_kind( $row ),
		);
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
	 * POST utmat/v1/test-webhook — send a synthetic payload now.
	 *
	 * Lets the admin verify their receiver and HMAC signature checking work
	 * before relying on it for real events. The optional `preset` and
	 * `leads` args forward the live settings-form values so a preset can be
	 * tested before it is saved. No-ops (returns sent:false) when no URL is
	 * configured or no event is enabled.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function send_test_webhook( WP_REST_Request $request ): WP_REST_Response {
		$sent = ConversionWebhook::send_test(
			(string) $request->get_param( 'preset' ),
			$request->has_param( 'leads' ) ? (bool) $request->get_param( 'leads' ) : null
		);

		return new WP_REST_Response( array( 'sent' => $sent ), 200 );
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
	 * toggle, the webhook settings (URL — empty disables it; preset; the
	 * order/lead event toggles), the consent category that gates the
	 * attribution cookie (allow-listed, anything else falls back to
	 * marketing), the untagged-visit tracking switch and the custom
	 * parameter names (comma/space separated here; stored as a validated
	 * array). The custom names are validated — shape, reserved names
	 * including the public query variables, and the maximum count — before
	 * any option is written, so a rejected list leaves every setting at its
	 * previous value. The weekly report cron is scheduled or unscheduled
	 * here, at save time, so no scheduling work happens on normal page
	 * loads.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$custom_names = self::validate_custom_params( self::param_string( $request, 'custom_params' ) );
		if ( $custom_names instanceof WP_REST_Response ) {
			return $custom_names;
		}

		$retention = absint( $request->get_param( 'retention_days' ) );
		$weekly    = (bool) $request->get_param( 'weekly_email' );
		$cookie    = absint( $request->get_param( 'cookie_days' ) );
		$cookie    = min( 730, max( 1, $cookie ? $cookie : 90 ) );
		$webhook   = esc_url_raw( self::param_string( $request, 'webhook_url' ), array( 'http', 'https' ) );
		$preset    = self::param_string( $request, 'webhook_preset' );
		if ( ! in_array( $preset, ConversionWebhook::PRESETS, true ) ) {
			$preset = 'generic';
		}
		$orders  = (bool) $request->get_param( 'webhook_orders' );
		$leads   = (bool) $request->get_param( 'webhook_leads' );
		$consent = self::param_string( $request, 'consent_category' );
		if ( ! in_array( $consent, Attribution::CONSENT_CATEGORIES, true ) ) {
			$consent = 'marketing';
		}
		$visits = (bool) $request->get_param( 'track_visits' );

		update_option( 'utmat_retention_days', $retention );
		update_option( WeeklyEmail::OPTION, $weekly ? 1 : 0 );
		update_option( 'utmat_cookie_days', $cookie );
		update_option( ConversionWebhook::OPTION_URL, $webhook );
		update_option( ConversionWebhook::OPTION_PRESET, $preset );
		update_option(
			ConversionWebhook::OPTION_EVENTS,
			array(
				'orders' => $orders,
				'leads'  => $leads,
			)
		);
		update_option( Attribution::OPTION_CONSENT_CATEGORY, $consent );
		update_option( 'utmat_track_visits', $visits ? 1 : 0 );
		update_option( 'utmat_custom_params', $custom_names );

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
				'webhook_preset'   => $preset,
				'webhook_orders'   => $orders,
				'webhook_leads'    => $leads,
				'consent_category' => $consent,
				'track_visits'     => $visits,
				'custom_params'    => $custom_names,
			),
			200
		);
	}

	/**
	 * Parse and validate the custom parameter names from the settings form.
	 *
	 * The raw input is a comma/space separated list; input is lowercased and
	 * trimmed, duplicates collapse silently. Every name must pass
	 * Tracker::is_valid_custom_param() with the query-var check on (save
	 * time), and a list longer than Tracker::MAX_CUSTOM_PARAMS is
	 * rejected. The first rejected name is named in the
	 * error message; an empty input saves an empty list (all custom
	 * parameters removed).
	 *
	 * @since 1.0.0
	 * @param string $raw Comma/space separated names, e.g. "Coupon, aff, promo2".
	 * @return string[]|WP_REST_Response Validated names, or a 400 response naming the rejected entry.
	 */
	private static function validate_custom_params( string $raw ) {
		$names = preg_split( '/[\s,]+/', strtolower( trim( $raw ) ), -1, PREG_SPLIT_NO_EMPTY );
		$names = array_values( array_unique( is_array( $names ) ? $names : array() ) );

		foreach ( $names as $name ) {
			if ( Tracker::is_valid_custom_param( $name, true ) ) {
				continue;
			}

			$reserved = in_array( $name, Tracker::reserved_param_names( true ), true )
				|| str_starts_with( $name, 'utm_' )
				|| str_starts_with( $name, 'utmat_' )
				|| str_starts_with( $name, 'first_' )
				|| str_ends_with( $name, '_first' );

			return new WP_REST_Response(
				array(
					'message' => $reserved
						? sprintf(
							/* translators: %s: the rejected custom parameter name. */
							__( '%s is reserved and cannot be used as a custom parameter.', 'utm-attribution-tracker' ),
							$name
						)
						: sprintf(
							/* translators: %s: the rejected custom parameter name. */
							__( 'Custom parameter names must start with a letter and may only use lowercase letters, digits and underscores, up to 32 characters: %s', 'utm-attribution-tracker' ),
							$name
						),
				),
				400
			);
		}

		if ( count( $names ) > Tracker::MAX_CUSTOM_PARAMS ) {
			return new WP_REST_Response(
				array(
					'message' => sprintf(
						/* translators: %d: maximum number of custom parameters. */
						__( 'You can define up to %d custom parameters.', 'utm-attribution-tracker' ),
						Tracker::MAX_CUSTOM_PARAMS
					),
				),
				400
			);
		}

		return $names;
	}

	/**
	 * GET utmat/v1/links
	 *
	 * Click counts for all links come from a single GROUP BY query instead
	 * of one COUNT query per link. The response also carries a `suggestions`
	 * map of previously used source/medium/campaign values for the add-link
	 * form datalists.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * Includes both the built destination (UTM parameters and link-level
	 * custom parameter values appended) and the raw stored fields so the
	 * dashboard edit form can pre-fill correctly.
	 *
	 * @since 1.0.0
	 * @param string               $id     Link slug.
	 * @param array<string, mixed> $link   Stored link fields.
	 * @param int                  $clicks Recorded click count for the slug.
	 * @return array<string, int|string|array<string, string>|stdClass> The custom entry is a stdClass (JSON object over REST).
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
			'custom'          => (object) ( isset( $link['custom'] ) && is_array( $link['custom'] ) ? $link['custom'] : array() ),
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
	 * but this server-side pass is authoritative. Custom parameter values
	 * from the `custom` object are kept only for currently configured
	 * names, sanitized and capped at 255 characters but deliberately not
	 * normalized (coupon and affiliate codes are often case-sensitive);
	 * unknown keys are dropped silently.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return array<string, mixed>|WP_REST_Response Sanitized link fields, or a 400 response when the destination is invalid.
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

		$custom_in = $request->get_param( 'custom' );
		if ( is_array( $custom_in ) ) {
			$custom = array();
			foreach ( Tracker::custom_params() as $name ) {
				$value = $custom_in[ $name ] ?? '';
				if ( is_scalar( $value ) ) {
					$value = substr( sanitize_text_field( (string) $value ), 0, 255 );
					if ( '' !== $value ) {
						$custom[ $name ] = $value;
					}
				}
			}
			if ( array() !== $custom ) {
				$link['custom'] = $custom;
			}
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
	 * given. Fails when the slug is empty, reserved (`incoming`, `visit`,
	 * `order` and `lead` name event kinds in the events table), already in
	 * use, or the destination is not a valid http(s) URL.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function create_link( WP_REST_Request $request ): WP_REST_Response {
		$label    = sanitize_text_field( self::param_string( $request, 'label' ) );
		$id_param = self::param_string( $request, 'id' );
		$id       = sanitize_key( '' !== $id_param ? $id_param : sanitize_title( $label ) );

		if ( '' === $id || in_array( $id, array( 'incoming', 'visit', 'order', 'lead' ), true ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Please provide a valid link slug (incoming, visit, order and lead are reserved).', 'utm-attribution-tracker' ) ),
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
	 * @since 1.0.0
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
