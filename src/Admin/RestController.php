<?php
/**
 * REST Controller — exposes stats and links data to the dashboard.
 *
 * Routes:
 *   GET    utmat/v1/stats       — aggregated click data
 *   GET    utmat/v1/links       — managed links with per-link click counts
 *   POST   utmat/v1/links       — create a managed link
 *   DELETE utmat/v1/links/<id>  — delete a managed link
 *
 * All routes require the `manage_options` capability.
 *
 * @package UTMAT\Admin
 */

namespace UTMAT\Admin;

use UTMAT\DB\Schema;
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
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'delete_link' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'sanitize_key',
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
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_stats( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table    = Schema::table();
		$period   = max( 1, min( 365, (int) $request->get_param( 'period' ) ) );
		$campaign = (string) $request->get_param( 'campaign' );
		$since    = gmdate( 'Y-m-d H:i:s', strtotime( "-{$period} days" ) );

		$where = $wpdb->prepare( 'clicked_at >= %s', $since );
		if ( ! empty( $campaign ) ) {
			$where .= $wpdb->prepare( ' AND utm_campaign = %s', $campaign );
		}

		// Clicks by day.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_day_raw = $wpdb->get_results(
			"SELECT DATE(clicked_at) AS day, COUNT(*) AS count
			 FROM {$table}
			 WHERE {$where}
			 GROUP BY DATE(clicked_at)
			 ORDER BY day ASC",
			ARRAY_A
		);
		// phpcs:enable

		// Build a full date scaffold so days with zero clicks still appear.
		$by_day = array();
		for ( $i = $period; $i >= 0; $i-- ) {
			$by_day[ gmdate( 'Y-m-d', strtotime( "-{$i} days" ) ) ] = 0;
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
			 WHERE {$where} AND utm_source != ''
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
			 WHERE {$where} AND utm_campaign != ''
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
			 WHERE {$where} AND utm_medium != ''
			 GROUP BY utm_medium
			 ORDER BY count DESC
			 LIMIT 10",
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
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );

		// Distinct campaigns for filter dropdown.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$campaigns = $wpdb->get_col(
			"SELECT DISTINCT utm_campaign FROM {$table} WHERE utm_campaign != '' ORDER BY utm_campaign"
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
				'revenue_by_campaign' => $revenue_by_campaign,
				'total'               => $total,
				'campaigns'           => array_values( (array) $campaigns ),
			),
			200
		);
	}

	/**
	 * GET utmat/v1/links
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_links( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table  = Schema::table();
		$links  = LinkManager::all();
		$result = array();

		foreach ( $links as $id => $link ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$clicks = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE link_id = %s", $id )
			);
			// phpcs:enable
			$result[] = array(
				'id'           => $id,
				'label'        => $link['label'] ?? $id,
				'destination'  => LinkManager::build_url( $link ),
				'redirect_url' => LinkManager::redirect_url( $id ),
				'clicks'       => $clicks,
				'utm_campaign' => $link['utm_campaign'] ?? '',
				'utm_source'   => $link['utm_source'] ?? '',
				'utm_medium'   => $link['utm_medium'] ?? '',
			);
		}

		return new WP_REST_Response( $result, 200 );
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
		$label       = sanitize_text_field( (string) $request->get_param( 'label' ) );
		$id_param    = (string) $request->get_param( 'id' );
		$id          = sanitize_key( '' !== $id_param ? $id_param : sanitize_title( $label ) );
		$destination = esc_url_raw( (string) $request->get_param( 'destination' ), array( 'http', 'https' ) );

		if ( '' === $id || 'incoming' === $id ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Please provide a valid link slug (the slug "incoming" is reserved).', 'utm-attribution-tracker' ) ),
				400
			);
		}

		if ( '' === $destination ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Please provide a valid destination URL (http or https).', 'utm-attribution-tracker' ) ),
				400
			);
		}

		if ( null !== LinkManager::get( $id ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'A link with this slug already exists.', 'utm-attribution-tracker' ) ),
				409
			);
		}

		$link = array(
			'label'       => ( '' !== $label ) ? $label : $id,
			'destination' => $destination,
		);
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_term', 'utm_content' ) as $key ) {
			$link[ $key ] = sanitize_text_field( (string) $request->get_param( $key ) );
		}

		LinkManager::save( $id, $link );

		return new WP_REST_Response(
			array(
				'id'           => $id,
				'label'        => $link['label'],
				'destination'  => LinkManager::build_url( $link ),
				'redirect_url' => LinkManager::redirect_url( $id ),
				'clicks'       => 0,
				'utm_campaign' => $link['utm_campaign'],
				'utm_source'   => $link['utm_source'],
				'utm_medium'   => $link['utm_medium'],
			),
			201
		);
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
