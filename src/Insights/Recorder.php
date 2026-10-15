<?php
/**
 * Recorder — lead recording: ring buffer plus conversion event row.
 *
 * Backs the recent-leads dashboard widget. Stores one option,
 * `utmatra_recent_leads` (autoload=false — only ever read on the
 * wp-admin Dashboard screen), structured as
 * `array<string integration_slug, array<record>>`, capped at
 * MAX_PER_INTEGRATION records per slug (oldest evicted first). Mirrors
 * HandL's `handl_recent_leads` / `MAX_PER_PLUGIN` pattern.
 *
 * Every attributed form submission is also written to the events table as
 * a `link_id = 'lead'` conversion row (WooCommerce orders excepted — they
 * carry their own `order` row) and announced on the
 * `utmatra_lead_recorded` action for the lead webhook.
 *
 * A record may carry the submitted form field values under `fields`; they
 * are cleaned, handed to the `utmatra_lead_recorded` action and dropped
 * before the option is written, so no submitted names, emails or messages
 * ever rest in wp_options.
 *
 * @package Utmatra\Insights
 */

namespace Utmatra\Insights;

use Utmatra\Tracking\Attribution;
use Utmatra\Tracking\Tracker;

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
	public const OPTION = 'utmatra_recent_leads';

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
	 * `utmatra_lead_recorded` action, but stripped from the stored copy: the
	 * widget never reads it and submitted personal data has no place in
	 * wp_options. Callers that pass no `fields` are unaffected (the action
	 * receives an empty array).
	 *
	 * Unless the caller passes `'is_lead' => false` (the WooCommerce
	 * integration does, because it writes its own `order` conversion row and
	 * sends its own webhook), the record also becomes a first-class
	 * conversion: a `link_id = 'lead'` event row is inserted through
	 * Tracker::record() and the `utmatra_lead_recorded` action fires after it,
	 * carrying the record with its sanitised `page_url` so listeners see the
	 * same value the row stored.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $integration Integration slug, e.g. 'contact-form-7', 'gravity-forms', 'ninja-forms', 'elementor', 'woocommerce'.
	 * @param array<string, mixed> $record_data Record data. Expected keys: form_id (int|string), form_title (string), posted (array<string,string> attribution data — same shape TrafficSource::classify() expects), created_at (int, Unix timestamp), fields (optional array<string, string|array> submitted values keyed by label; never stored), is_lead (optional bool, default true), page_url (optional string; resolved from the referer when absent).
	 *
	 * @return void
	 */
	public static function record( string $integration, array $record_data ): void {
		$fields = isset( $record_data['fields'] ) && is_array( $record_data['fields'] ) ? $record_data['fields'] : array();

		$record_data['fields'] = self::clean_fields( $fields );

		$is_lead = ! isset( $record_data['is_lead'] ) || false !== $record_data['is_lead'];

		$page_url = self::resolve_page_url( $record_data );

		$record_data['page_url'] = $page_url;

		// Whitelist the ring-buffer copy: only these keys ever reach
		// wp_options, whatever a caller passes.
		$stored = array_intersect_key( $record_data, array_flip( array( 'form_id', 'form_title', 'posted', 'created_at' ) ) );

		$all = self::all();

		if ( ! isset( $all[ $integration ] ) || ! is_array( $all[ $integration ] ) ) {
			$all[ $integration ] = array();
		}

		$all[ $integration ][] = $stored;

		if ( count( $all[ $integration ] ) > self::MAX_PER_INTEGRATION ) {
			$all[ $integration ] = array_slice( $all[ $integration ], -self::MAX_PER_INTEGRATION );
		}

		self::save( $all );

		if ( $is_lead ) {
			self::record_lead_event( $integration, $record_data, $page_url );

			/**
			 * Fires after an attributed form submission has been recorded as a
			 * lead conversion event.
			 *
			 * The record carries the cleaned submitted `fields` and the
			 * sanitised `page_url` stored on the event row; the field values
			 * are not stored anywhere by the plugin itself.
			 *
			 * @since 1.0.0
			 *
			 * @param string               $integration Integration slug.
			 * @param array<string, mixed> $record_data The record: form_id, form_title, posted, created_at, page_url and fields (always an array).
			 */
			do_action( 'utmatra_lead_recorded', $integration, $record_data );
		}
	}

	/**
	 * Insert the `link_id = 'lead'` conversion event row for a recorded lead.
	 *
	 * The row mirrors the WooCommerce order conversion's shape: built from
	 * the last touch (`posted`), no order total, and the channel passed
	 * explicitly because the submission request's own referrer is the site
	 * (or absent for admin-ajax/REST submissions), never the campaign that
	 * brought the visitor. Form metadata travels in the `meta` JSON column;
	 * the submitted field values never reach the table.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $integration Integration slug.
	 * @param array<string, mixed> $record_data The record (cleaned `fields` included; unused here).
	 * @param string               $page_url    Sanitised page URL stored as the row's destination.
	 * @return void
	 */
	private static function record_lead_event( string $integration, array $record_data, string $page_url ): void {
		$posted = isset( $record_data['posted'] ) && is_array( $record_data['posted'] ) ? $record_data['posted'] : array();

		$event = array(
			'link_id'       => 'lead',
			'is_conversion' => 1,
			'touch'         => 'last',
			'destination'   => $page_url,
			// The submission's request-time referrer is the form page itself,
			// so the row inherits the last touch's channel instead of being
			// classified fresh.
			'channel'       => isset( $posted['ch'] ) && is_scalar( $posted['ch'] ) ? (string) $posted['ch'] : '',
			'meta'          => array(
				'integration' => $integration,
				'form_id'     => is_scalar( $record_data['form_id'] ?? '' ) ? (string) $record_data['form_id'] : '',
				'form_title'  => is_scalar( $record_data['form_title'] ?? '' ) ? (string) $record_data['form_title'] : '',
			),
			'visitor_id'    => Attribution::visitor_id(),
		);

		foreach ( Tracker::param_whitelist() as $key ) {
			$event[ $key ] = isset( $posted[ $key ] ) && is_scalar( $posted[ $key ] ) ? (string) $posted[ $key ] : '';
		}

		// Conversion rows never set or honour the dedup transient, matching
		// the WooCommerce order path.
		Tracker::record( $event );
	}

	/**
	 * Resolve the page URL to store as a lead event's destination.
	 *
	 * The record's own `page_url` when it carries one; otherwise the request
	 * referer — REST and admin-ajax form submissions (Contact Form 7, Ninja
	 * Forms) send the form page as their Referer, and wp_get_referer() only
	 * returns same-site URLs, so an external referer correctly falls through
	 * — and finally the current request URL, mirroring how
	 * Tracker::handle_incoming_request() builds it.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $record_data The record being recorded.
	 * @return string An esc_url_raw()'d URL, or '' on a CLI request with no referer.
	 */
	private static function resolve_page_url( array $record_data ): string {
		$given    = $record_data['page_url'] ?? null;
		$page_url = esc_url_raw( is_scalar( $given ) ? (string) $given : '' );

		if ( '' !== $page_url ) {
			return $page_url;
		}

		$page_url = esc_url_raw( (string) wp_get_referer() );

		if ( '' !== $page_url ) {
			return $page_url;
		}

		if ( ! isset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		return esc_url_raw( ( is_ssl() ? 'https' : 'http' ) . '://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
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
