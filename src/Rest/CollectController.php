<?php
/**
 * Collect Controller — public REST endpoint for the front-end beacon.
 *
 * Route:
 *   POST utmat/v1/collect — record an incoming UTM/click-ID page view.
 *
 * The route is public by design: the beacon fires from cached pages where a
 * nonce baked into the HTML would be stale, so no nonce is used. Instead the
 * endpoint carries its own defenses — strict param whitelist, same-origin
 * check, per-IP rate limiting, bot filtering, and deduplication.
 *
 * @package UTMAT\Rest
 */

namespace UTMAT\Rest;

use UTMAT\Tracking\Tracker;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * CollectController
 */
class CollectController {

	/**
	 * Maximum collect requests per minute per hashed client IP.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private const RATE_LIMIT = 15;

	/**
	 * Register the collect REST route.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_routes(): void {
		$args = array();
		foreach ( Tracker::PARAM_WHITELIST as $key ) {
			$args[ $key ] = array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			);
		}
		$args['page_url'] = array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'esc_url_raw',
		);
		$args['referrer'] = array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'esc_url_raw',
		);

		register_rest_route(
			'utmat/v1',
			'/collect',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'collect' ),
				'permission_callback' => '__return_true',
				'args'                => $args,
			)
		);
	}

	/**
	 * POST utmat/v1/collect — record a beacon-reported page view.
	 *
	 * Rejects cross-origin requests (403), rate-limited IPs (429), and
	 * requests with no UTM param or click ID (400). Bot user agents get a
	 * 200 with `recorded: false` so they learn nothing; duplicates within
	 * the dedup window respond the same way.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function collect( WP_REST_Request $request ): WP_REST_Response {
		if ( ! self::origin_allowed( $request ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Cross-origin requests are not allowed.', 'utm-attribution-tracker' ) ),
				403
			);
		}

		if ( self::is_rate_limited() ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Too many requests.', 'utm-attribution-tracker' ) ),
				429
			);
		}

		if ( Tracker::is_bot( (string) $request->get_header( 'user_agent' ) ) ) {
			return new WP_REST_Response( array( 'recorded' => false ), 200 );
		}

		$params = array();
		foreach ( Tracker::PARAM_WHITELIST as $key ) {
			$value = (string) $request->get_param( $key );
			if ( '' !== $value ) {
				$params[ $key ] = $value;
			}
		}

		if ( empty( $params ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'No UTM parameter or click ID present.', 'utm-attribution-tracker' ) ),
				400
			);
		}

		$data = array_merge(
			$params,
			array(
				'link_id'     => 'incoming',
				'destination' => (string) $request->get_param( 'page_url' ),
				'referrer'    => (string) $request->get_param( 'referrer' ),
			)
		);

		if ( Tracker::is_duplicate( $data ) ) {
			return new WP_REST_Response( array( 'recorded' => false ), 200 );
		}

		Tracker::record( $data );

		return new WP_REST_Response( array( 'recorded' => true ), 200 );
	}

	/**
	 * Same-origin sanity check for the collect request.
	 *
	 * The Origin header's scheme and host (Referer as fallback) must match
	 * the site's. Browsers always send at least one of the two for a
	 * same-origin POST/sendBeacon, so requests with neither header are
	 * rejected, as are downgraded-scheme origins (http on an https site).
	 *
	 * @since 1.0.0 Scheme is now compared in addition to the host.
	 * @param WP_REST_Request $request REST request.
	 * @return bool
	 */
	private static function origin_allowed( WP_REST_Request $request ): bool {
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin ) {
			$origin = (string) $request->get_header( 'referer' );
		}
		if ( '' === $origin ) {
			return false;
		}

		$origin_host   = (string) wp_parse_url( $origin, PHP_URL_HOST );
		$origin_scheme = (string) wp_parse_url( $origin, PHP_URL_SCHEME );
		$site_host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$site_scheme   = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );

		return '' !== $origin_host
			&& strtolower( $origin_host ) === strtolower( $site_host )
			&& strtolower( $origin_scheme ) === strtolower( $site_scheme );
	}

	/**
	 * The collect rate limit in requests per minute per hashed client IP.
	 *
	 * Sites where many visitors share one IP — behind a CDN or SSL proxy
	 * without the `utmat_client_ip` filter configured, or with heavy
	 * NAT/CGNAT audiences — can raise the ceiling via the
	 * `utmat_rate_limit` filter.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	private static function rate_limit(): int {
		/**
		 * Filter the collect endpoint rate limit (requests/minute/IP).
		 *
		 * @var int $limit
		 */
		return max( 1, (int) apply_filters( 'utmat_rate_limit', self::RATE_LIMIT ) );
	}

	/**
	 * Per-IP rate limiting via a transient counter.
	 *
	 * Allows rate_limit() requests per rolling minute per hashed client IP
	 * (`utmat_rl_{ip_hash}`, 60 s TTL); the counter is only
	 * incremented for requests that pass.
	 *
	 * @since 1.0.0 Limit is now filterable via utmat_rate_limit.
	 * @return bool True when the current request exceeds the limit.
	 */
	private static function is_rate_limited(): bool {
		$key   = 'utmat_rl_' . Tracker::hash_ip( Tracker::client_ip() );
		$count = (int) get_transient( $key );

		if ( $count >= self::rate_limit() ) {
			return true;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return false;
	}
}
