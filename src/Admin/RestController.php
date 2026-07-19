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
 *
 * All routes require the `manage_options` capability.
 *
 * @package UTMAT\Admin
 */

namespace UTMAT\Admin;

use DateTimeImmutable;
use DateTimeZone;
use UTMAT\DB\Schema;
use UTMAT\Reports\WeeklyEmail;
use UTMAT\Tracking\LinkManager;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * RestController
 */
class RestController {

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
					'period'   => array(
						'default'           => 30,
						'sanitize_callback' => 'absint',
					),
					'campaign' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
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
					'retention_days' => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'weekly_email'   => array(
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
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
	 * double-counted as a click.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_stats( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table    = Schema::table();
		$period   = max( 1, min( 365, (int) $request->get_param( 'period' ) ) );
		$campaign = (string) $request->get_param( 'campaign' );

		$timezone  = wp_timezone();
		$now_local = new DateTimeImmutable( 'now', $timezone );
		$offset    = (int) $timezone->getOffset( $now_local );

		$where       = self::build_where( $period, $campaign );
		$click_where = $where . ' AND is_conversion = 0';

		// Clicks by day, grouped on the site-local date.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_day_raw = $wpdb->get_results(
			"SELECT DATE( DATE_ADD( clicked_at, INTERVAL {$offset} SECOND ) ) AS day, COUNT(*) AS count
			 FROM {$table}
			 WHERE {$click_where}
			 GROUP BY DATE( DATE_ADD( clicked_at, INTERVAL {$offset} SECOND ) )
			 ORDER BY day ASC",
			ARRAY_A
		);
		// phpcs:enable

		// Build a full date scaffold so days with zero clicks still appear.
		$by_day = array();
		for ( $i = $period; $i >= 0; $i-- ) {
			$by_day[ $now_local->modify( "-{$i} days" )->format( 'Y-m-d' ) ] = 0;
		}
		foreach ( (array) $by_day_raw as $row ) {
			if ( isset( $row['day'] ) ) {
				$by_day[ $row['day'] ] = (int) $row['count'];
			}
		}

		// By source.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

		// Top landing pages — passive incoming visits only (redirect-link rows
		// store the outbound destination, not a landing page). The query string
		// is stripped so UTM variants of one URL group into a single row.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

		// Ad-platform breakdown — derived from which ad click ID a click
		// carried. Rows without any click ID (organic/UTM-only traffic) are
		// excluded; the dashboard maps the keys to display names.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_platform = $wpdb->get_results(
			"SELECT CASE
				WHEN gclid != '' THEN 'google'
				WHEN fbclid != '' THEN 'facebook'
				WHEN msclkid != '' THEN 'microsoft'
				WHEN ttclid != '' THEN 'tiktok'
			 END AS label, COUNT(*) AS count
			 FROM {$table}
			 WHERE {$click_where} AND ( gclid != '' OR fbclid != '' OR msclkid != '' OR ttclid != '' )
			 GROUP BY label
			 ORDER BY count DESC",
			ARRAY_A
		);
		// phpcs:enable

		// Revenue by campaign — conversion rows only (WooCommerce orders).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$click_where}" );

		// Previous-period total — the same-length window immediately before
		// this one, for the trend indicator next to the total.
		$prev_where = self::build_previous_where( $period, $campaign ) . ' AND is_conversion = 0';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$previous_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$prev_where}" );

		// Distinct campaigns for filter dropdown.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$campaigns = $wpdb->get_col(
			"SELECT DISTINCT utm_campaign FROM {$table} WHERE is_conversion = 0 AND utm_campaign != '' ORDER BY utm_campaign"
		);
		// phpcs:enable

		$clicks_by_day = array();
		foreach ( $by_day as $date => $count ) {
			$clicks_by_day[] = array(
				'date'  => $date,
				'count' => $count,
			);
		}

		return new WP_REST_Response(
			array(
				'clicks_by_day'       => $clicks_by_day,
				'by_source'           => array_values( (array) $by_source ),
				'by_campaign'         => array_values( (array) $by_campaign ),
				'by_medium'           => array_values( (array) $by_medium ),
				'by_landing'          => array_values( (array) $by_landing ),
				'by_platform'         => array_values( (array) $by_platform ),
				'revenue_by_campaign' => $revenue_by_campaign,
				'total'               => $total,
				'previous_total'      => $previous_total,
				'campaigns'           => array_values( (array) $campaigns ),
			),
			200
		);
	}

	/**
	 * Build the prepared WHERE clause shared by the stats and export queries.
	 *
	 * The period boundary is midnight (site-local) `$period` days ago,
	 * converted to UTC to match the stored `clicked_at` values. An optional
	 * campaign filter is appended.
	 *
	 * @since 1.0.0
	 * @param int    $period   Period length in days.
	 * @param string $campaign Optional utm_campaign filter; empty for all.
	 * @return string Prepared SQL WHERE clause (without the WHERE keyword).
	 */
	private static function build_where( int $period, string $campaign ): string {
		global $wpdb;

		$timezone    = wp_timezone();
		$now_local   = new DateTimeImmutable( 'now', $timezone );
		$since_local = $now_local->modify( "-{$period} days" )->setTime( 0, 0, 0 );
		$since       = $since_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		$where = $wpdb->prepare( 'clicked_at >= %s', $since );
		if ( ! empty( $campaign ) ) {
			$where .= $wpdb->prepare( ' AND utm_campaign = %s', $campaign );
		}

		return $where;
	}

	/**
	 * Build the prepared WHERE clause for the period immediately before the
	 * requested one.
	 *
	 * Covers site-local midnight `2 × $period` days ago up to (exclusive) the
	 * current period's start, with the same optional campaign filter as
	 * {@see build_where()}.
	 *
	 * @since 1.0.0
	 * @param int    $period   Period length in days.
	 * @param string $campaign Optional utm_campaign filter; empty for all.
	 * @return string Prepared SQL WHERE clause (without the WHERE keyword).
	 */
	private static function build_previous_where( int $period, string $campaign ): string {
		global $wpdb;

		$timezone  = wp_timezone();
		$utc       = new DateTimeZone( 'UTC' );
		$now_local = new DateTimeImmutable( 'now', $timezone );
		$double    = 2 * $period;

		$since_current = $now_local->modify( "-{$period} days" )->setTime( 0, 0, 0 );
		$since_prev    = $now_local->modify( "-{$double} days" )->setTime( 0, 0, 0 );

		$where = $wpdb->prepare(
			'clicked_at >= %s AND clicked_at < %s',
			$since_prev->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$since_current->setTimezone( $utc )->format( 'Y-m-d H:i:s' )
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
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function export_events( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table    = Schema::table();
		$period   = max( 1, min( 365, (int) $request->get_param( 'period' ) ) );
		$campaign = (string) $request->get_param( 'campaign' );
		$where    = self::build_where( $period, $campaign );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT clicked_at, link_id, utm_source, utm_medium, utm_campaign, utm_id, utm_term, utm_content,
			        gclid, fbclid, msclkid, ttclid, destination, referrer, is_conversion, order_total
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
	 * POST utmat/v1/settings
	 *
	 * Saves the retention window (0 = keep forever) and the weekly email
	 * toggle. The weekly report cron is scheduled or unscheduled here, at
	 * save time, so no scheduling work happens on normal page loads.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$retention = absint( $request->get_param( 'retention_days' ) );
		$weekly    = (bool) $request->get_param( 'weekly_email' );

		update_option( 'utmat_retention_days', $retention );
		update_option( WeeklyEmail::OPTION, $weekly ? 1 : 0 );

		if ( $weekly ) {
			WeeklyEmail::schedule();
		} else {
			WeeklyEmail::unschedule();
		}

		return new WP_REST_Response(
			array(
				'retention_days' => $retention,
				'weekly_email'   => $weekly,
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
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_links( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table  = Schema::table();
		$links  = LinkManager::all();
		$result = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			),
			200
		);
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
		$table = Schema::table();

		$columns = array(
			'sources'   => 'utm_source',
			'mediums'   => 'utm_medium',
			'campaigns' => 'utm_campaign',
		);

		$out = array();
		foreach ( $columns as $key => $column ) {
			// $column comes from the fixed map above, never from user input.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * given. Fails when the slug is empty, reserved, already in use, or the
	 * destination is not a valid http(s) URL.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function create_link( WP_REST_Request $request ): WP_REST_Response {
		$label    = sanitize_text_field( self::param_string( $request, 'label' ) );
		$id_param = self::param_string( $request, 'id' );
		$id       = sanitize_key( '' !== $id_param ? $id_param : sanitize_title( $label ) );

		if ( '' === $id || 'incoming' === $id ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Please provide a valid link slug (the slug "incoming" is reserved).', 'utm-attribution-tracker' ) ),
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

		$table = Schema::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
