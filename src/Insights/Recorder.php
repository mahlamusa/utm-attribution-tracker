<?php
/**
 * Recorder — ring-buffer storage for the most recent leads/conversions.
 *
 * Backs the recent-leads dashboard widget. Stores one option,
 * `utmat_recent_leads` (autoload=false — only ever read on the
 * wp-admin Dashboard screen), structured as
 * `array<string integration_slug, array<record>>`, capped at
 * MAX_PER_INTEGRATION records per slug (oldest evicted first). Mirrors
 * HandL's `handl_recent_leads` / `MAX_PER_PLUGIN` pattern.
 *
 * @package UTMAT\Insights
 */

namespace UTMAT\Insights;

defined( 'ABSPATH' ) || exit;

/**
 * Recorder
 *
 * @since 1.0.0
 */
class Recorder {

	/**
	 * Option name holding the ring buffer of recent leads, keyed by integration slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const OPTION = 'utmat_recent_leads';

	/**
	 * Maximum records retained per integration slug (oldest evicted first).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const MAX_PER_INTEGRATION = 5;

	/**
	 * Record a new lead/conversion for an integration slug.
	 *
	 * Appends to the ring buffer for $integration, evicting the oldest
	 * record once the slug exceeds MAX_PER_INTEGRATION entries. Other slugs
	 * are untouched by the cap (it is per-slug, not global).
	 *
	 * @since 1.0.0
	 *
	 * @param string               $integration Integration slug, e.g. 'contact-form-7', 'gravity-forms', 'ninja-forms', 'elementor', 'woocommerce'.
	 * @param array<string, mixed> $record_data Record data. Expected keys: form_id (int|string), form_title (string), posted (array<string,string> attribution data — same shape TrafficSource::classify() expects), created_at (int, Unix timestamp).
	 *
	 * @return void
	 */
	public static function record( string $integration, array $record_data ): void {
		$all = self::all();

		if ( ! isset( $all[ $integration ] ) || ! is_array( $all[ $integration ] ) ) {
			$all[ $integration ] = array();
		}

		$all[ $integration ][] = $record_data;

		if ( count( $all[ $integration ] ) > self::MAX_PER_INTEGRATION ) {
			$all[ $integration ] = array_slice( $all[ $integration ], -self::MAX_PER_INTEGRATION );
		}

		self::save( $all );
	}

	/**
	 * The most recent leads across all integrations, capped per slug.
	 *
	 * @since 1.0.0
	 *
	 * @param int $per_integration Maximum records returned per integration slug, most recent first.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Integration slug => list of records, most recent first. Empty when no leads have been recorded.
	 */
	public static function recent( int $per_integration = self::MAX_PER_INTEGRATION ): array {
		$all = self::all();
		$out = array();

		foreach ( $all as $integration => $records ) {
			if ( ! is_array( $records ) || empty( $records ) ) {
				continue;
			}
			$out[ $integration ] = array_slice( array_reverse( $records ), 0, $per_integration );
		}

		return $out;
	}

	/**
	 * Read the raw ring-buffer option.
	 *
	 * @since 1.0.0
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function all(): array {
		$data = get_option( self::OPTION, array() );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Persist the ring-buffer option with autoload disabled.
	 *
	 * The first write uses add_option() so autoload=false is set at creation
	 * time (update_option()'s autoload param is not honoured for an
	 * already-existing option on every supported WP version) — subsequent
	 * writes go through update_option(), which preserves that autoload value.
	 *
	 * @since 1.0.0
	 * @param array<string, array<int, array<string, mixed>>> $data Full ring-buffer data to persist.
	 * @return void
	 */
	private static function save( array $data ): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $data, '', false );
		} else {
			update_option( self::OPTION, $data, false );
		}
	}
}
