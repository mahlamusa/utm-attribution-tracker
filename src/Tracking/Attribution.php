<?php
/**
 * Attribution — first/last-touch attribution cookie reader.
 *
 * The `utmat_attr` cookie is written by the front-end beacon (primary,
 * cache-proof path) and by Tracker::maybe_set_attribution_cookie() (PHP
 * fallback for no-JS visitors). This class is the read side: it parses the
 * cookie defensively — every value sanitized, unknown keys dropped, malformed
 * JSON tolerated — so later specs can attach attribution to conversions.
 *
 * @package UTMAT\Tracking
 */

namespace UTMAT\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Attribution
 */
class Attribution {

	/**
	 * Name of the first-party attribution cookie.
	 *
	 * The value is URL-encoded JSON: {"first":{...},"last":{...}} where each
	 * set holds the whitelisted UTM/click-ID params present on that visit
	 * plus `lp` (landing page URL) and `ts` (Unix seconds).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const COOKIE_NAME = 'utmat_attr';

	/**
	 * Memoized decode of the raw cookie for the current request.
	 *
	 * Holds the decoded JSON array once the cookie has been read, or null when
	 * the cookie is absent/malformed. The `$decoded_done` flag distinguishes a
	 * genuine null result from "not yet parsed" so the decode runs at most once
	 * per request across repeated get_first()/get_last() calls.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string, mixed>|null
	 */
	private static $decoded_cache = null;

	/**
	 * Whether the cookie decode has already run this request.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	private static $decoded_done = false;

	/**
	 * Return the sanitized first-touch attribution set.
	 *
	 * Values are input-sanitized (sanitize_text_field, capped at 255 chars) but
	 * are NOT output-escaped — callers must esc_html()/esc_attr() at render, and
	 * esc_url() the `lp` (landing page) value, before emitting them into markup.
	 *
	 * @since 1.0.0
	 * @return array<string, string|int> Empty array when the cookie is absent or malformed.
	 */
	public static function get_first(): array {
		return self::parse( 'first' );
	}

	/**
	 * Return the sanitized last-touch attribution set.
	 *
	 * Values are input-sanitized (sanitize_text_field, capped at 255 chars) but
	 * are NOT output-escaped — callers must esc_html()/esc_attr() at render, and
	 * esc_url() the `lp` (landing page) value, before emitting them into markup.
	 *
	 * @since 1.0.0
	 * @return array<string, string|int> Empty array when the cookie is absent or malformed.
	 */
	public static function get_last(): array {
		return self::parse( 'last' );
	}

	/**
	 * Whether the current request carries any usable attribution data.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function has_data(): bool {
		return array() !== self::get_first() || array() !== self::get_last();
	}

	/**
	 * Map an attribution param to its public form-field name.
	 *
	 * The convention (shared with the front-end beacon's fillFormFields) is
	 * the industry-standard parameter name itself: `utm_source` →
	 * `utm_source`, `gclid` → `gclid`. Only the landing page (`lp`) is
	 * renamed, to `landing_page`. First-touch variants append `_first` to
	 * the returned name (handled by callers).
	 *
	 * @since 1.0.0 Field names switched from the prefixed `utmat_*` convention to the standard parameter names.
	 *
	 * @param string $param Whitelisted param key, or 'lp' for the landing page.
	 *
	 * @return string
	 */
	public static function field_name( string $param ): string {
		return ( 'lp' === $param ) ? 'landing_page' : $param;
	}

	/**
	 * All recognised form-field names — last-touch and `_first` variants.
	 *
	 * @since 1.0.0
	 * @return string[]
	 */
	public static function field_names(): array {
		$names = array();
		foreach ( array_merge( Tracker::PARAM_WHITELIST, array( 'lp' ) ) as $param ) {
			$name    = self::field_name( $param );
			$names[] = $name;
			$names[] = $name . '_first';
		}
		return $names;
	}

	/**
	 * Field name => value map of both attribution sets, for form adapters.
	 *
	 * Last-touch values map to the plain field names, first-touch values to
	 * the `_first`-suffixed names; only non-empty values are included. Values
	 * are input-sanitized but NOT output-escaped — the form plugins receiving
	 * them (Contact Form 7, Gravity Forms) esc_attr() at render; any caller
	 * emitting markup itself must escape.
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	public static function field_map(): array {
		$map     = array();
		$touches = array(
			''       => self::get_last(),
			'_first' => self::get_first(),
		);

		foreach ( $touches as $suffix => $touch ) {
			foreach ( array_merge( Tracker::PARAM_WHITELIST, array( 'lp' ) ) as $param ) {
				$value = isset( $touch[ $param ] ) ? (string) $touch[ $param ] : '';
				if ( '' !== $value ) {
					$map[ self::field_name( $param ) . $suffix ] = $value;
				}
			}
		}

		return $map;
	}

	/**
	 * The attribution cookie lifetime in days.
	 *
	 * Reads the `utmat_cookie_days` option (set from the dashboard
	 * settings card, default 90); the filter of the same name runs last and
	 * stays authoritative for code-level overrides.
	 *
	 * @since 1.0.0 Now backed by the utmat_cookie_days option.
	 * @return int
	 */
	public static function cookie_days(): int {
		$days = (int) get_option( 'utmat_cookie_days', 90 );
		if ( $days < 1 ) {
			$days = 90;
		}

		/**
		 * Filter the attribution cookie lifetime in days.
		 *
		 * @var int $days
		 */
		return (int) apply_filters( 'utmat_cookie_days', $days );
	}

	/**
	 * Whether the attribution cookie is enabled at all.
	 *
	 * Master switch for both the JS and PHP writers. Sites that must gate
	 * non-essential cookies behind a consent manager should return false
	 * here until consent is given.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function enabled(): bool {
		$enabled = true;

		// Auto-detect the WP Consent API (github.com/rlankhorst/wp-consent-level-api),
		// the de-facto standard several consent-management plugins (Complianz,
		// Real Cookie Banner, Borlabs Cookie, Cookiebot's WP integration)
		// implement. When present, the visitor must have granted "marketing"
		// or "statistics" consent before the cookie is written; when absent,
		// the plugin falls back to its previous always-on behaviour (consent
		// integration remains the site owner's responsibility per the readme).
		if ( function_exists( 'wp_has_consent' ) ) {
			$enabled = wp_has_consent( 'marketing' ) || wp_has_consent( 'statistics' );
		}

		/**
		 * Filter whether the attribution cookie may be written.
		 *
		 * Runs after the WP Consent API auto-detection above, so a filter can
		 * still force the decision either way.
		 *
		 * @var bool $enabled
		 */
		return (bool) apply_filters( 'utmat_enable_cookie', $enabled );
	}

	/**
	 * Decode the raw attribution cookie once per request.
	 *
	 * Guards against hostile cookie shapes before decoding: a non-string raw
	 * value (e.g. a crafted array cookie like `utmat_attr[0]=x`) and an
	 * over-long value (> 8192 bytes, a cheap DoS guard) both yield null without
	 * decoding. The successful decode is memoized so repeated get_first()/
	 * get_last() calls in the same request parse the JSON only once.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>|null The decoded JSON array, or null when absent/malformed.
	 */
	private static function decoded_cookie() {
		if ( self::$decoded_done ) {
			return self::$decoded_cache;
		}
		self::$decoded_done  = true;
		self::$decoded_cache = null;

		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}

		// The raw cookie is URL-encoded JSON — sanitizing before json_decode
		// would corrupt it, so each value is sanitized individually in parse().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = wp_unslash( $_COOKIE[ self::COOKIE_NAME ] );

		// A crafted array cookie (utmat_attr[0]=x) arrives as an array;
		// bail before the string cast so no Array-to-string warning is raised.
		// An over-long value is dropped unparsed as a DoS guard.
		if ( ! is_string( $raw ) || strlen( $raw ) > 8192 ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		// PHP urldecodes $_COOKIE values automatically, but tolerate a
		// still-encoded value (e.g. double-encoded by a proxy or hand edit).
		if ( ! is_array( $decoded ) ) {
			$decoded = json_decode( rawurldecode( $raw ), true );
		}

		if ( is_array( $decoded ) ) {
			self::$decoded_cache = $decoded;
		}

		return self::$decoded_cache;
	}

	/**
	 * Parse one attribution set out of the cookie, defensively.
	 *
	 * Never throws and never trusts the JSON shape: non-array sets, unknown
	 * keys, and non-scalar values are dropped; every kept value passes
	 * through sanitize_text_field() and is capped at 255 chars (matching the
	 * writers). `ts` is cast to int.
	 *
	 * @since 1.0.0
	 * @param string $set Which set to extract: 'first' or 'last'.
	 * @return array<string, string|int>
	 */
	private static function parse( string $set ): array {
		$decoded = self::decoded_cookie();

		if ( ! is_array( $decoded ) || ! isset( $decoded[ $set ] ) || ! is_array( $decoded[ $set ] ) ) {
			return array();
		}

		$allowed = array_merge( Tracker::PARAM_WHITELIST, array( 'lp', 'ts' ) );
		$clean   = array();

		foreach ( $decoded[ $set ] as $key => $value ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) || ! is_scalar( $value ) ) {
				continue;
			}
			$value = substr( sanitize_text_field( (string) $value ), 0, 255 );
			if ( '' === $value ) {
				continue;
			}
			$clean[ $key ] = ( 'ts' === $key ) ? (int) $value : $value;
		}

		return $clean;
	}
}
