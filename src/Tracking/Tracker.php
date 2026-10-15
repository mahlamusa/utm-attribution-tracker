<?php
/**
 * Tracker — records click events on init.
 *
 * Two tracking modes:
 *  1. Outbound redirect: /?utmat_track=<slug> → records event then wp_redirect().
 *  2. Passive incoming: any page load with a whitelisted UTM param or ad
 *     click ID present is recorded (no-JS fallback to the front-end beacon).
 *
 * The passive path also resolves the per-browser visitor id: when the cookie
 * gate is open, maybe_set_visitor_cookie() issues or refreshes the `utmat_vid`
 * cookie (the beacon writes it for JS visitors) and record() stamps the id on
 * the row, so conversions can be shown with the touches that preceded them.
 *
 * Filter `utmat_track_logged_in_users` (bool, default false) controls whether
 * administrator clicks are recorded.
 *
 * @since 1.0.0
 *
 * @package UTMAT\Tracking
 */

namespace UTMAT\Tracking;

use UTMAT\DB\Schema;
use WP;

defined( 'ABSPATH' ) || exit;

/**
 * Tracker
 *
 * @since 1.0.0
 */
class Tracker {

	/**
	 * Built-in query parameters that trigger and populate tracking events.
	 *
	 * This is the fixed list only. Runtime readers (the passive PHP path, the
	 * collect endpoint, the attribution cookie, the form adapters and the
	 * front-end beacon via `utmatBeacon.params`) use param_whitelist(), which
	 * merges in the admin-defined custom parameters.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	public const PARAM_WHITELIST = array(
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_id',
		'utm_term',
		'utm_content',
		'gclid',
		'fbclid',
		'msclkid',
		'ttclid',
		'li_fat_id',
		'twclid',
	);

	/**
	 * Maximum number of admin-defined custom parameters.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	public const MAX_CUSTOM_PARAMS = 10;

	/**
	 * Names a custom parameter may never take, beyond the built-in whitelist.
	 *
	 * Grouped by the collision each entry prevents: attribution form-field
	 * names (`landing_page`, the Ninja Forms `landing` tag param, `channel`
	 * and `referrer_host`), cookie touch keys (`lp`, `ts`, `ch`, `rh`), the
	 * collect endpoint's own arguments (`page_url`, `referrer`, `visit`,
	 * `vid`), events-table column names that record() reads straight from
	 * `$data`, and common form or credential field names that the beacon
	 * would otherwise pre-fill on every page. Names starting with `utm_` or
	 * `utmat_` and names ending in `_first` are rejected by rule rather than
	 * listed here. `ref` is deliberately absent: the referrer-host touch key
	 * is `rh`, so `ref` stays free for affiliate-style parameters.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private const RESERVED_PARAM_NAMES = array(
		// Attribution form-field names.
		'landing_page',
		'landing',
		'channel',
		'referrer_host',
		// Cookie touch keys.
		'lp',
		'ts',
		'ch',
		'rh',
		// Collect endpoint arguments.
		'page_url',
		'referrer',
		'visit',
		'vid',
		// Events-table columns.
		'id',
		'link_id',
		'destination',
		'is_conversion',
		'touch',
		'order_total',
		'device',
		'ip_hash',
		'user_agent',
		'clicked_at',
		'custom',
		'visitor_id',
		'meta',
		// Common form and credential field names.
		'email',
		'name',
		'phone',
		'subject',
		'message',
		'author',
		'url',
		'comment',
		'log',
		'pwd',
		'password',
		'user_login',
		'user_email',
		'user_pass',
		// The beacon configuration key and the settings input id.
		'params',
	);

	/**
	 * The admin-defined custom parameter names, validated on every read.
	 *
	 * Reads the `utmat_custom_params` option and re-applies the cheap
	 * validation rules (shape, reserved names, uniqueness, the cap) so a
	 * hand-edited option can never inject an unsafe key. The query-var check
	 * is save-time only. Not memoised: the option comes from the alloptions
	 * cache and the rules are a regex over at most ten strings.
	 *
	 * @since 1.0.0
	 * @return string[] Validated names in saved order, at most MAX_CUSTOM_PARAMS.
	 */
	public static function custom_params(): array {
		$stored = get_option( 'utmat_custom_params', array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$names = array();
		foreach ( $stored as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			$name = strtolower( trim( $name ) );
			if ( in_array( $name, $names, true ) || ! self::is_valid_custom_param( $name ) ) {
				continue;
			}
			$names[] = $name;
			if ( count( $names ) >= self::MAX_CUSTOM_PARAMS ) {
				break;
			}
		}

		return $names;
	}

	/**
	 * The full list of tracked parameters: built-ins plus custom parameters.
	 *
	 * Every runtime reader of the whitelist uses this accessor so an
	 * admin-defined parameter is captured exactly like a built-in one.
	 *
	 * @since 1.0.0
	 * @return string[] Built-in names first, then custom names in saved order.
	 */
	public static function param_whitelist(): array {
		return array_values( array_unique( array_merge( self::PARAM_WHITELIST, self::custom_params() ) ) );
	}

	/**
	 * The names a custom parameter may not take.
	 *
	 * The built-in whitelist plus RESERVED_PARAM_NAMES and, by default, the
	 * public WordPress query variables (as filtered by `query_vars`, so
	 * plugin-registered ones count). That is the save-time set; the read
	 * path passes false because it runs on every request and the query-var
	 * list instantiates WP when no request has been parsed, as under WP-CLI.
	 *
	 * @since 1.0.0
	 * @param bool $include_query_vars Whether to add the public query variables.
	 * @return string[]
	 */
	public static function reserved_param_names( bool $include_query_vars = true ): array {
		$reserved = array_merge( self::PARAM_WHITELIST, self::RESERVED_PARAM_NAMES );

		if ( $include_query_vars ) {
			$wp = ( isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof WP ) ? $GLOBALS['wp'] : new WP();
			/** This filter is documented in wp-includes/class-wp.php */
			$query_vars = apply_filters( 'query_vars', $wp->public_query_vars );
			$reserved   = array_merge( $reserved, is_array( $query_vars ) ? $query_vars : array() );
		}

		return array_values( array_unique( $reserved ) );
	}

	/**
	 * Whether a (lowercased, trimmed) name is acceptable as a custom parameter.
	 *
	 * A valid name starts with a letter and is 1 to 32 characters of
	 * `[a-z0-9_]`, does not start with `utm_` or `utmat_`, does not end in
	 * `_first` (the first-touch field suffix) and is not reserved. The
	 * leading letter keeps underscore-prefixed names away from the REST
	 * API's own `_fields`-style arguments and keeps all-digit names from
	 * turning the stored JSON object into a list.
	 *
	 * @since 1.0.0
	 * @param string $name             Candidate name, already lowercased and trimmed.
	 * @param bool   $check_query_vars Also reject public WordPress query variables (save time only).
	 * @return bool
	 */
	public static function is_valid_custom_param( string $name, bool $check_query_vars = false ): bool {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,31}$/D', $name ) ) {
			return false;
		}
		if ( str_starts_with( $name, 'utm_' ) || str_starts_with( $name, 'utmat_' ) || str_starts_with( $name, 'first_' ) || str_ends_with( $name, '_first' ) ) {
			return false;
		}

		return ! in_array( $name, self::reserved_param_names( $check_query_vars ), true );
	}

	/**
	 * Register the utmat_track query variable so WordPress parses it.
	 *
	 * @since 1.0.0
	 * @param string[] $vars Existing public query vars.
	 * @return string[]
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = 'utmat_track';
		return $vars;
	}

	/**
	 * Main entry-point hooked on `init` (priority 1).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function handle_incoming_request(): void {
		if ( ! self::should_track() ) {
			return;
		}

		// Bots are redirected (so social link-preview unfurls still resolve)
		// but their clicks are never recorded. Detected once, applied at each
		// record() call site below.
		$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$is_bot = self::is_bot( $ua );

		// --- 1. Outbound redirect ---
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$track_id = isset( $_GET['utmat_track'] ) ? sanitize_key( wp_unslash( $_GET['utmat_track'] ) ) : '';

		if ( ! empty( $track_id ) ) {
			$link = LinkManager::get( $track_id );
			if ( $link ) {
				$destination = LinkManager::build_url( $link );
				$data        = array(
					'link_id'      => $track_id,
					'utm_source'   => $link['utm_source'] ?? '',
					'utm_medium'   => $link['utm_medium'] ?? '',
					'utm_campaign' => $link['utm_campaign'] ?? '',
					'utm_id'       => $link['utm_id'] ?? '',
					'utm_term'     => $link['utm_term'] ?? '',
					'utm_content'  => $link['utm_content'] ?? '',
					'custom'       => (array) ( $link['custom'] ?? array() ),
					'destination'  => $destination,
				);
				if ( ! $is_bot && ! self::is_duplicate( $data ) ) {
					self::record( $data );
				}
				wp_redirect( $destination, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}
		}

		// --- 2. Passive incoming UTM tracking (any whitelisted param) ---
		// A /go/{slug}/ pretty-link request is resolved by the outbound redirect
		// path at parse_request (which records the click and 302s). At this early
		// `init` pass that request still carries its UTM query params but no
		// utmat_track $_GET value, so without this guard it would also be
		// logged here as a passive "incoming" row — double-counting the click.
		$request_path = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH );
		if ( empty( $track_id ) && 1 === preg_match( '#^/go/[a-z0-9_\-]+/?$#', $request_path ) ) {
			return;
		}

		$params = array();
		foreach ( self::param_whitelist() as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$value = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
			if ( '' !== $value ) {
				$params[ $key ] = $value;
			}
		}

		if ( ! $is_bot && ! empty( $params ) ) {
			$data = array_merge(
				$params,
				array(
					'link_id'     => 'incoming',
					'destination' => esc_url_raw( ( is_ssl() ? 'https' : 'http' ) . '://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ) ),
				)
			);
			// Resolve the visitor id BEFORE the record call so a first visit's
			// row already carries the id this same response is about to set.
			// Runs regardless of dedup, like the cookie write below, so repeat
			// visits still refresh the cookie expiry; a closed gate returns ''.
			$data['visitor_id'] = self::maybe_set_visitor_cookie();
			// The event gate is off by default (an event carries no cookie);
			// when a site opts in, the passive record waits for consent too.
			if ( ( ! Attribution::gate_events() || Attribution::consented() ) && ! self::is_duplicate( $data ) ) {
				self::record( $data );
			}
			// Cookie logic runs regardless of dedup — a deduplicated repeat
			// visit must still refresh the last-touch set and expiry.
			self::maybe_set_attribution_cookie( $params, $data['destination'] );
		}
	}

	/**
	 * PHP fallback writer for the first/last-touch attribution cookie.
	 *
	 * The front-end beacon is the primary (cache-proof) writer; this covers
	 * no-JS visitors on uncached pages. Both writers apply the same rules so
	 * the writes are idempotent: `first` is preserved once set, `last` is
	 * overwritten with the current visit, and every value is capped at 255
	 * characters. An oversized encoded cookie (> 4000 bytes) sheds the landing
	 * page from both sets, and is skipped entirely if still over the limit so
	 * the existing cookie is preserved. Runs only for non-bot traffic (enforced
	 * at the call site), when headers are not yet sent, when the
	 * `utmat_enable_cookie` master switch allows it, and when the visitor has
	 * granted the configured consent category (always true without a WP
	 * Consent API). Those checks live in cookie_gate_open(), the single gate
	 * both server-side cookie writers share.
	 *
	 * The touch also carries the external referrer host (`rh`) when the
	 * request has one; the channel is derived by the reader and never written
	 * for a tagged touch. A channel the beacon stored for an untagged first
	 * landing is preserved verbatim when the first touch is re-serialised, so
	 * both writers stay symmetrical.
	 *
	 * @since 1.0.0
	 * @param array<string, string> $params  Sanitized whitelisted UTM/click-ID params from the current request.
	 * @param string                $landing The current request URL (already esc_url_raw'd), stored as the landing page.
	 * @return void
	 */
	private static function maybe_set_attribution_cookie( array $params, string $landing ): void {
		if ( ! self::cookie_gate_open() ) {
			return;
		}

		$touch = array();
		foreach ( $params as $key => $value ) {
			$touch[ $key ] = substr( $value, 0, 255 );
		}
		$touch['lp'] = substr( $landing, 0, 255 );

		$referrer_host = isset( $_SERVER['HTTP_REFERER'] )
			? strtolower( (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), PHP_URL_HOST ) )
			: '';
		$site_host     = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' !== $referrer_host && $referrer_host !== $site_host ) {
			$touch['rh'] = substr( $referrer_host, 0, 255 );
		}
		$touch['ts'] = time();

		$first         = Attribution::get_first();
		$first_channel = Attribution::stored_first_channel();
		// The reader derives `ch` on every parse, so the recomputed value stays
		// out of the cookie; a channel the beacon stored for an untagged first
		// landing is preserved verbatim, as the beacon preserves it.
		unset( $first['ch'] );
		if ( empty( $first ) ) {
			$first = $touch;
		} elseif ( '' !== $first_channel ) {
			$first['ch'] = $first_channel;
		}

		$value = wp_json_encode(
			array(
				'first' => $first,
				'last'  => $touch,
			)
		);
		if ( false === $value ) {
			return;
		}

		// Total-cookie-size guard: shed the landing page from both sets if the
		// encoded value is oversized, then skip the write entirely if still over
		// the limit so the existing cookie is preserved rather than truncated.
		if ( strlen( $value ) > 4000 ) {
			unset( $first['lp'], $touch['lp'] );
			$value = wp_json_encode(
				array(
					'first' => $first,
					'last'  => $touch,
				)
			);
			if ( false === $value || strlen( $value ) > 4000 ) {
				return;
			}
		}

		// setcookie() URL-encodes the value, matching the beacon's
		// encodeURIComponent, so both writers produce the same wire format.
		setcookie(
			Attribution::COOKIE_NAME,
			$value,
			array(
				'expires'  => time() + Attribution::cookie_days() * DAY_IN_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Whether a server-side cookie write is permitted right now.
	 *
	 * The single gate both server-side cookie writers share: headers must not
	 * have been sent, the `utmat_enable_cookie` master switch must allow the
	 * attribution cookies, and — when a WP Consent API implementation is
	 * present — the visitor must have granted the configured consent
	 * category. `utmat_attr` and `utmat_vid` can therefore never be issued to
	 * a visitor who has not consented.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private static function cookie_gate_open(): bool {
		return ! headers_sent() && Attribution::enabled() && Attribution::consented();
	}

	/**
	 * PHP fallback writer for the per-browser visitor id cookie.
	 *
	 * The no-JS counterpart of the beacon's write: keeps the existing
	 * `utmat_vid` when it holds a valid UUID v4 and generates one otherwise,
	 * then always re-sets the cookie with the same options as `utmat_attr` so
	 * an existing id gets a fresh expiry on every tagged visit — without the
	 * rewrite the two cookies would drift apart and split a no-JS visitor's
	 * journey in two. Returns the resolved id, or '' when the gate is closed
	 * (no id is ever generated for a visitor who has not consented).
	 *
	 * @since 1.0.0
	 * @return string The visitor id that was written, or ''.
	 */
	private static function maybe_set_visitor_cookie(): string {
		if ( ! self::cookie_gate_open() ) {
			return '';
		}

		$vid = Attribution::visitor_id();
		if ( '' === $vid ) {
			$vid = wp_generate_uuid4();
		}

		setcookie(
			Attribution::VISITOR_COOKIE_NAME,
			$vid,
			array(
				'expires'  => time() + Attribution::cookie_days() * DAY_IN_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);

		return $vid;
	}

	/**
	 * Determine whether the current request should be tracked.
	 *
	 * Cron, AJAX, XML-RPC, and REST API requests are never page views, so
	 * they are always excluded — otherwise an unrelated plugin/theme AJAX or
	 * REST call that happens to forward a UTM param in its query string would
	 * get recorded as a click, inflating campaign stats with noise. REST is
	 * detected via the pretty-permalink path prefix, the plain-permalink
	 * `rest_route` query var, and `wp_is_serving_rest_request()` (WP 6.5+).
	 *
	 * Bot/crawler user agents are NOT excluded here: bots must still receive
	 * the 302 on the redirect path (so social link-preview unfurls resolve).
	 * The bot check is applied at each record() call site in
	 * handle_incoming_request() instead, so a bot is redirected but not logged.
	 *
	 * By default logged-in admins are also excluded.
	 * Return `true` from the `utmat_track_logged_in_users` filter to override.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private static function should_track(): bool {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		if ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) {
			return false;
		}

		// Plain-permalink REST calls (/?rest_route=/...) have no path prefix.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['rest_route'] ) ) {
			return false;
		}

		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( false !== strpos( $request_uri, '/' . rest_get_url_prefix() . '/' ) ) {
			return false;
		}

		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			/**
			 * Whether to track clicks from logged-in administrators.
			 *
			 * @var bool $track_admins
			 */
			$track_admins = apply_filters( 'utmat_track_logged_in_users', false );
			if ( ! $track_admins ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Insert a tracking event row into the database.
	 *
	 * Click-ID, conversion, and revenue fields default to empty/0/NULL when
	 * absent. `order_total` is only written when present and numeric, so the
	 * column stays NULL for plain click rows.
	 *
	 * A `referrer` key in $data overrides the Referer request header even
	 * when it is empty — the collect endpoint passes the original
	 * document.referrer this way (the beacon POST's own Referer header is
	 * just the page URL), and an empty one means the visitor arrived direct.
	 * Callers that omit the key (short-link clicks, the PHP incoming path,
	 * order conversions) get the request header.
	 *
	 * After a successful insert the deduplication transient is set so an
	 * identical event within the dedup window is skipped (see is_duplicate()).
	 * Conversion rows (`is_conversion` truthy) never participate in dedup —
	 * each order is a distinct conversion — so they neither set nor honour
	 * the transient.
	 *
	 * `channel` is written from $data when given, else computed by
	 * TrafficSource::channel() from the assembled row; `visitor_id` is
	 * written from $data and, when absent, falls back to the `utmat_vid`
	 * cookie (Attribution::visitor_id()) so short-link clicks, order rows
	 * and lead rows are stamped without their call sites changing.
	 * `custom` is built from a `custom` array in $data plus any configured
	 * custom parameter passed flat (the incoming, collect and order paths
	 * pass params that way) and stored as a JSON object; `meta` is a `meta`
	 * array stored as JSON. Both columns are left NULL when there is nothing
	 * to store.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $data Event fields.
	 * @return void
	 */
	public static function record( array $data ): void {
		global $wpdb;

		$ip  = self::client_ip();
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '';
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		if ( array_key_exists( 'referrer', $data ) ) {
			$ref = esc_url_raw( (string) $data['referrer'] );
		}

		// Passed explicitly by the paths that resolved it (the passive PHP
		// path before recording, the collect endpoint from the beacon's vid
		// field); every other caller falls back to the `utmat_vid` cookie.
		$vid = (string) ( $data['visitor_id'] ?? Attribution::visitor_id() );

		$row = array(
			'link_id'       => substr( $data['link_id'] ?? '', 0, 64 ),
			'utm_source'    => substr( $data['utm_source'] ?? '', 0, 100 ),
			'utm_medium'    => substr( $data['utm_medium'] ?? '', 0, 100 ),
			'utm_campaign'  => substr( $data['utm_campaign'] ?? '', 0, 100 ),
			'utm_id'        => substr( $data['utm_id'] ?? '', 0, 100 ),
			'utm_term'      => substr( $data['utm_term'] ?? '', 0, 100 ),
			'utm_content'   => substr( $data['utm_content'] ?? '', 0, 255 ),
			'destination'   => substr( $data['destination'] ?? '', 0, 2083 ),
			'gclid'         => substr( $data['gclid'] ?? '', 0, 255 ),
			'fbclid'        => substr( $data['fbclid'] ?? '', 0, 255 ),
			'msclkid'       => substr( $data['msclkid'] ?? '', 0, 255 ),
			'ttclid'        => substr( $data['ttclid'] ?? '', 0, 255 ),
			'li_fat_id'     => substr( $data['li_fat_id'] ?? '', 0, 255 ),
			'twclid'        => substr( $data['twclid'] ?? '', 0, 255 ),
			'is_conversion' => empty( $data['is_conversion'] ) ? 0 : 1,
			'touch'         => substr( $data['touch'] ?? '', 0, 10 ),
			'device'        => self::device_type( $ua ),
			'ip_hash'       => self::hash_ip( $ip ),
			'user_agent'    => $ua,
			'referrer'      => substr( $ref, 0, 2083 ),
			'clicked_at'    => current_time( 'mysql', true ),
			'channel'       => substr( $data['channel'] ?? '', 0, 20 ),
			'visitor_id'    => substr( $vid, 0, 36 ),
		);

		// Classify after the row is assembled so the resolved referrer is
		// what gets classified. Conversions pass their touch's channel
		// explicitly because a checkout's request-time referrer is the site.
		if ( '' === $row['channel'] ) {
			$row['channel'] = TrafficSource::channel( $row );
		}

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		// $wpdb->insert() cannot pass a typed NULL, so omit the column instead.
		if ( isset( $data['order_total'] ) && is_numeric( $data['order_total'] ) ) {
			$row['order_total'] = (float) $data['order_total'];
			$formats[]          = '%f';
		}

		// Custom parameters: a `custom` array in $data, overridden by any
		// configured name passed flat; only configured names are kept.
		$given  = ( isset( $data['custom'] ) && is_array( $data['custom'] ) ) ? $data['custom'] : array();
		$custom = array();
		foreach ( self::custom_params() as $name ) {
			$value = ( isset( $data[ $name ] ) && '' !== $data[ $name ] ) ? $data[ $name ] : ( $given[ $name ] ?? '' );
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = substr( sanitize_text_field( (string) $value ), 0, 255 );
			if ( '' !== $value ) {
				$custom[ $name ] = $value;
			}
		}
		if ( array() !== $custom ) {
			$json = wp_json_encode( $custom );
			if ( false !== $json ) {
				$row['custom'] = $json;
				$formats[]     = '%s';
			}
		}

		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) && array() !== $data['meta'] ) {
			$json = wp_json_encode( $data['meta'] );
			if ( false !== $json ) {
				$row['meta'] = $json;
				$formats[]   = '%s';
			}
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Schema::table(),
			$row,
			$formats
		);

		$window = self::dedup_window();
		if ( empty( $data['is_conversion'] ) && false !== $inserted && $window > 0 ) {
			set_transient( 'utmat_dd_' . self::dedup_key( $data ), 1, $window );
		}
	}

	/**
	 * Whether an identical event was already recorded within the dedup window.
	 *
	 * Identity = same hashed client IP + same link_id + same set of
	 * UTM/click-ID values. Checked against a transient
	 * (`utmat_dd_{md5}`) so no table scan is needed; record() sets the
	 * transient after a successful insert. A window of 0 disables dedup.
	 * Conversion rows are exempt: an order is always a distinct conversion,
	 * so `is_conversion` truthy is never a duplicate.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $data Event fields (same shape as record()).
	 * @return bool
	 */
	public static function is_duplicate( array $data ): bool {
		if ( ! empty( $data['is_conversion'] ) ) {
			return false;
		}
		if ( self::dedup_window() <= 0 ) {
			return false;
		}
		return false !== get_transient( 'utmat_dd_' . self::dedup_key( $data ) );
	}

	/**
	 * Build the deduplication cache key for an event.
	 *
	 * The key is the md5 of hashed IP + link_id + the key-sorted JSON of
	 * the whitelisted UTM/click-ID values present in the event. An untagged
	 * landing visit has no such values, so its key also carries the
	 * referrer host: a Google landing and a Facebook landing from one IP
	 * within the window are two visits, reloads of the same landing are one.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $data Event fields.
	 * @return string
	 */
	private static function dedup_key( array $data ): string {
		$subset = array();
		foreach ( self::param_whitelist() as $key ) {
			if ( isset( $data[ $key ] ) && '' !== $data[ $key ] ) {
				$subset[ $key ] = (string) $data[ $key ];
			}
		}
		if ( 'visit' === ( $data['link_id'] ?? '' ) ) {
			$subset['rh'] = strtolower( (string) wp_parse_url( (string) ( $data['referrer'] ?? '' ), PHP_URL_HOST ) );
		}
		ksort( $subset );

		return md5( self::hash_ip( self::client_ip() ) . '|' . ( $data['link_id'] ?? '' ) . '|' . wp_json_encode( $subset ) );
	}

	/**
	 * The deduplication window in seconds.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	private static function dedup_window(): int {
		/**
		 * Filter the deduplication window in seconds. Return 0 to disable
		 * deduplication entirely.
		 *
		 * @var int $window
		 */
		return (int) apply_filters( 'utmat_dedup_window', 30 * MINUTE_IN_SECONDS );
	}

	/**
	 * Classify a user agent string as `mobile`, `tablet`, or `desktop`.
	 *
	 * A lightweight regex heuristic computed once at insert time and stored
	 * on the row, so the dashboard's device breakdown is a plain GROUP BY
	 * like every other chart instead of re-parsing raw user agents per
	 * request. Tablets are checked before the broader mobile pattern since
	 * "iPad" and tablet-class Android strings would otherwise match it.
	 *
	 * @since 1.0.0
	 * @param string $ua The user agent string.
	 * @return string
	 */
	public static function device_type( string $ua ): string {
		if ( '' === trim( $ua ) ) {
			return '';
		}

		if ( preg_match( '/iPad|Android(?!.*Mobile)|Tablet|Kindle|Silk/i', $ua ) ) {
			return 'tablet';
		}

		if ( preg_match( '/Mobi|iPhone|iPod|Android|BlackBerry|IEMobile|Opera Mini|Windows Phone/i', $ua ) ) {
			return 'mobile';
		}

		return 'desktop';
	}

	/**
	 * Whether a user agent string looks like a bot, crawler, or link preview.
	 *
	 * Case-insensitive keyword match; an empty user agent also counts as a
	 * bot. Override the verdict via the `utmat_is_bot` filter.
	 *
	 * @since 1.0.0
	 * @param string $ua The user agent string.
	 * @return bool
	 */
	public static function is_bot( string $ua ): bool {
		$verdict = false;

		if ( '' === trim( $ua ) ) {
			$verdict = true;
		} else {
			$keywords = array(
				'bot',
				'crawl',
				'spider',
				'slurp',
				'preview',
				'monitor',
				'curl',
				'wget',
				'python-requests',
				'headless',
				'lighthouse',
				'pingdom',
				'uptime',
				'facebookexternalhit',
				'whatsapp',
				'telegrambot',
				'twitterbot',
				'slackbot',
				'discordbot',
				'linkedinbot',
				'skypeuripreview',
				'embedly',
				'vkshare',
				'pinterest',
				'bingpreview',
			);
			$haystack = strtolower( $ua );
			foreach ( $keywords as $keyword ) {
				if ( false !== strpos( $haystack, $keyword ) ) {
					$verdict = true;
					break;
				}
			}
		}

		/**
		 * Filter the bot verdict for a user agent.
		 *
		 * @var bool $verdict
		 */
		return (bool) apply_filters( 'utmat_is_bot', $verdict, $ua );
	}

	/**
	 * Resolve the client IP address.
	 *
	 * Returns REMOTE_ADDR by default. Sites behind a trusted reverse proxy
	 * may substitute a forwarded header's value via the
	 * `utmat_client_ip` filter — but forwarded headers (X-Forwarded-For
	 * etc.) are client-spoofable, so only trust them when every request
	 * passes through a proxy that overwrites the header.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		/**
		 * Filter the resolved client IP address.
		 *
		 * @var string $ip
		 */
		return (string) apply_filters( 'utmat_client_ip', $ip );
	}

	/**
	 * Hash an IP address with the per-site secret salt.
	 *
	 * The salt is created on activation; if it is missing (sites activated
	 * before the salt existed) it is lazily created here.
	 *
	 * @since 1.0.0
	 * @param string $ip The visitor IP address.
	 * @return string The salted SHA-256 hash.
	 */
	public static function hash_ip( string $ip ): string {
		$salt = (string) get_option( 'utmat_salt', '' );
		if ( '' === $salt ) {
			$salt = wp_generate_password( 64, true, true );
			add_option( 'utmat_salt', $salt );
		}
		return hash( 'sha256', $salt . $ip );
	}
}
