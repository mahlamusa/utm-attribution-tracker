<?php
/**
 * Tracker — records click events on init.
 *
 * Two tracking modes:
 *  1. Outbound redirect: /?utmat_track=<slug> → records event then wp_redirect().
 *  2. Passive incoming: any page load with a whitelisted UTM param or ad
 *     click ID present is recorded (no-JS fallback to the front-end beacon).
 *
 * Filter `utmat_track_logged_in_users` (bool, default false) controls whether
 * administrator clicks are recorded.
 *
 * @package UTMAT\Tracking
 */

namespace UTMAT\Tracking;

use UTMAT\DB\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Tracker
 */
class Tracker {

	/**
	 * Query parameters that trigger and populate tracking events.
	 *
	 * Shared by the passive PHP path, the public collect REST endpoint, and
	 * the front-end beacon (duplicated there as a JS const — keep in sync
	 * with assets/js/beacon.js).
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
		foreach ( self::PARAM_WHITELIST as $key ) {
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
			if ( ! self::is_duplicate( $data ) ) {
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
	 * at the call site), when headers are not yet sent, and when the
	 * `utmat_enable_cookie` master switch allows it.
	 *
	 * @since 1.0.0
	 * @param array<string, string> $params  Sanitized whitelisted UTM/click-ID params from the current request.
	 * @param string                $landing The current request URL (already esc_url_raw'd), stored as the landing page.
	 * @return void
	 */
	private static function maybe_set_attribution_cookie( array $params, string $landing ): void {
		if ( headers_sent() || ! Attribution::enabled() ) {
			return;
		}

		$touch = array();
		foreach ( $params as $key => $value ) {
			$touch[ $key ] = substr( $value, 0, 255 );
		}
		$touch['lp'] = substr( $landing, 0, 255 );
		$touch['ts'] = time();

		$first = Attribution::get_first();
		if ( empty( $first ) ) {
			$first = $touch;
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
	 * A `referrer` key in $data overrides the Referer request header — the
	 * collect endpoint passes the original document.referrer this way (the
	 * beacon POST's own Referer header is just the page URL).
	 *
	 * After a successful insert the deduplication transient is set so an
	 * identical event within the dedup window is skipped (see is_duplicate()).
	 * Conversion rows (`is_conversion` truthy) never participate in dedup —
	 * each order is a distinct conversion — so they neither set nor honour
	 * the transient.
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
		if ( isset( $data['referrer'] ) && '' !== $data['referrer'] ) {
			$ref = esc_url_raw( (string) $data['referrer'] );
		}

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
			'ip_hash'       => self::hash_ip( $ip ),
			'user_agent'    => $ua,
			'referrer'      => substr( $ref, 0, 2083 ),
			'clicked_at'    => current_time( 'mysql', true ),
		);

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' );

		// $wpdb->insert() cannot pass a typed NULL, so omit the column instead.
		if ( isset( $data['order_total'] ) && is_numeric( $data['order_total'] ) ) {
			$row['order_total'] = (float) $data['order_total'];
			$formats[]          = '%f';
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
	 * the whitelisted UTM/click-ID values present in the event.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $data Event fields.
	 * @return string
	 */
	private static function dedup_key( array $data ): string {
		$subset = array();
		foreach ( self::PARAM_WHITELIST as $key ) {
			if ( isset( $data[ $key ] ) && '' !== $data[ $key ] ) {
				$subset[ $key ] = (string) $data[ $key ];
			}
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
