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
 * A record may carry the submitted form field values under `fields`; they
 * are cleaned, handed to the `utmat_lead_recorded` action and dropped
 * before the option is written, so no submitted names, emails or messages
 * ever rest in wp_options.
 *
 * @package UTMAT\Insights
 */

namespace UTMAT\Insights;

use UTMAT\Tracking\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * Recorder
 *
 * @since 1.0.0
 */
class Recorder {

	/**
	 * Maximum submitted fields kept per lead.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const MAX_FIELDS = 50;

	/**
	 * Maximum length of a submitted field label.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const MAX_LABEL_LENGTH = 200;

	/**
	 * Maximum length of a submitted field value.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const MAX_VALUE_LENGTH = 1000;

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
	 * The optional `fields` key (the form's own submitted values keyed by
	 * label) is normalised through clean_fields() and handed to the
	 * `utmat_lead_recorded` action, but stripped from the stored copy: the
	 * widget never reads it and submitted personal data has no place in
	 * wp_options. Callers that pass no `fields` are unaffected (the action
	 * receives an empty array).
	 *
	 * @since 1.0.0
	 *
	 * @param string               $integration Integration slug, e.g. 'contact-form-7', 'gravity-forms', 'ninja-forms', 'elementor', 'woocommerce'.
	 * @param array<string, mixed> $record_data Record data. Expected keys: form_id (int|string), form_title (string), posted (array<string,string> attribution data — same shape TrafficSource::classify() expects), created_at (int, Unix timestamp), fields (optional array<string, string|array> submitted values keyed by label; never stored).
	 *
	 * @return void
	 */
	public static function record( string $integration, array $record_data ): void {
		$fields = isset( $record_data['fields'] ) && is_array( $record_data['fields'] ) ? $record_data['fields'] : array();

		$record_data['fields'] = self::clean_fields( $fields );

		$stored = $record_data;
		unset( $stored['fields'] );

		$all = self::all();

		if ( ! isset( $all[ $integration ] ) || ! is_array( $all[ $integration ] ) ) {
			$all[ $integration ] = array();
		}

		$all[ $integration ][] = $stored;

		if ( count( $all[ $integration ] ) > self::MAX_PER_INTEGRATION ) {
			$all[ $integration ] = array_slice( $all[ $integration ], -self::MAX_PER_INTEGRATION );
		}

		self::save( $all );

		/**
		 * Fires after a lead or conversion has been recorded.
		 *
		 * The record carries the cleaned submitted `fields`, which are not
		 * stored anywhere by the plugin itself.
		 *
		 * @since 1.0.0
		 *
		 * @param string               $integration Integration slug.
		 * @param array<string, mixed> $record_data The record: form_id, form_title, posted, created_at and fields (always an array).
		 */
		do_action( 'utmat_lead_recorded', $integration, $record_data );
	}

	/**
	 * Normalise submitted form field values for the lead pipeline.
	 *
	 * Keeps the first MAX_FIELDS entries. Labels are sanitised and capped at
	 * MAX_LABEL_LENGTH; empty labels are dropped and duplicates suffixed
	 * ` (2)`, ` (3)`. Values: scalars are cast to string, one-level arrays
	 * joined with `, `, nested arrays JSON-encoded, then sanitised and capped
	 * at MAX_VALUE_LENGTH; empty values are kept. Entries whose label is one
	 * of the attribution field names are dropped, because attribution
	 * travels in `posted` and must never appear twice.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $fields Submitted values keyed by label.
	 *
	 * @return array<string, string>
	 */
	public static function clean_fields( array $fields ): array {
		$attribution = Attribution::field_names();
		$out         = array();

		foreach ( array_slice( $fields, 0, self::MAX_FIELDS, true ) as $label => $value ) {
			$label = mb_substr( sanitize_text_field( (string) $label ), 0, self::MAX_LABEL_LENGTH );

			if ( '' === $label || in_array( $label, $attribution, true ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$nested = false;
				foreach ( $value as $item ) {
					if ( is_array( $item ) || is_object( $item ) ) {
						$nested = true;
						break;
					}
				}
				$value = $nested ? (string) wp_json_encode( $value ) : implode( ', ', array_map( 'strval', $value ) );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$value = (string) $value;
			} else {
				$value = (string) wp_json_encode( $value );
			}

			$value = mb_substr( sanitize_text_field( $value ), 0, self::MAX_VALUE_LENGTH );

			$unique = $label;
			$n      = 1;
			while ( isset( $out[ $unique ] ) ) {
				++$n;
				$suffix = ' (' . $n . ')';
				// Shorten the label so the suffix always fits inside the cap.
				$unique = mb_substr( $label, 0, self::MAX_LABEL_LENGTH - strlen( $suffix ) ) . $suffix;
			}

			$out[ $unique ] = $value;
		}

		return $out;
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
