<?php
/**
 * ConversionWebhook — outbound POST to a configurable URL on new conversion
 * (WooCommerce order or form lead).
 *
 * Disabled by default (the `utmat_webhook_url` option is empty). When an
 * attributed WooCommerce order is placed (see Integrations\WooCommerce) or
 * an attributed form submission is recorded (the `utmat_lead_recorded`
 * action fired by Insights\Recorder), the payload — order total, currency
 * and the first/last-touch attribution sets for orders; the form's field
 * values and both attribution sets for leads — is POSTed to the configured
 * URL, signed with an HMAC-SHA256 signature over a per-site secret so the
 * receiver can verify the request actually came from this site.
 *
 * The `utmat_webhook_preset` option selects the body shape: the generic
 * JSON payload (also used by the Zapier and Make presets, whose receivers
 * parse nested JSON themselves), the HubSpot Forms API v3 submission body
 * (leads only), or GoHighLevel's flat snake_case object. The
 * `utmat_webhook_events` option toggles order and lead events
 * independently; both default to on.
 *
 * @package UTMAT\Reports
 */

namespace UTMAT\Reports;

use UTMAT\Tracking\Attribution;
use UTMAT\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

/**
 * ConversionWebhook
 */
class ConversionWebhook {

	/**
	 * Option name holding the configured webhook URL. Empty disables sending.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const OPTION_URL = 'utmat_webhook_url';

	/**
	 * Option name holding the per-site HMAC signing secret.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const OPTION_SECRET = 'utmat_webhook_secret';

	/**
	 * Option name holding the result of the most recent delivery attempt.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const OPTION_LAST = 'utmat_webhook_last';

	/**
	 * Option name holding the selected webhook preset.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const OPTION_PRESET = 'utmat_webhook_preset';

	/**
	 * Option name holding the per-event toggles (orders / leads).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const OPTION_EVENTS = 'utmat_webhook_events';

	/**
	 * The accepted webhook presets.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	public const PRESETS = array( 'generic', 'zapier', 'make', 'hubspot', 'gohighlevel' );

	/**
	 * E-mail address used for the synthetic HubSpot test contact.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const TEST_EMAIL = 'utmat-test@example.com';

	/**
	 * Timeout in seconds for the outbound request.
	 *
	 * Kept short because delivery is a blocking call made during checkout
	 * and form submission (see attach_attribution() in the WooCommerce
	 * integration and send_lead()) — a slow or unreachable receiver must
	 * not stall the visitor for long.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private const TIMEOUT = 3;

	/**
	 * The configured webhook URL, or '' when disabled.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function url(): string {
		return (string) get_option( self::OPTION_URL, '' );
	}

	/**
	 * The per-site HMAC signing secret, lazily created if missing.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function secret(): string {
		$secret = (string) get_option( self::OPTION_SECRET, '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 64, false );
			add_option( self::OPTION_SECRET, $secret );
		}
		return $secret;
	}

	/**
	 * The outcome of the most recent delivery attempt, for the settings card.
	 *
	 * @since 1.0.0
	 * @return array{time: int, ok: bool, detail: string}|null
	 */
	public static function last_delivery(): ?array {
		$last = get_option( self::OPTION_LAST, null );
		return is_array( $last ) ? $last : null;
	}

	/**
	 * The selected webhook preset, or 'generic' for anything invalid.
	 *
	 * @since 1.0.0
	 * @return string One of PRESETS.
	 */
	public static function preset(): string {
		$preset = (string) get_option( self::OPTION_PRESET, 'generic' );
		return in_array( $preset, self::PRESETS, true ) ? $preset : 'generic';
	}

	/**
	 * The per-event delivery toggles.
	 *
	 * Missing keys (an option written before a toggle existed, or a
	 * hand-edited value) fall back to on: a configured URL should deliver
	 * everything until the admin narrows it.
	 *
	 * @since 1.0.0
	 * @return array{orders: bool, leads: bool}
	 */
	public static function events(): array {
		$stored = get_option( self::OPTION_EVENTS, array() );
		$events = is_array( $stored ) ? $stored : array();

		return array(
			'orders' => ! isset( $events['orders'] ) || (bool) $events['orders'],
			'leads'  => ! isset( $events['leads'] ) || (bool) $events['leads'],
		);
	}

	/**
	 * POST a payload to the configured webhook URL.
	 *
	 * No-ops when no URL is configured. The generic event name is passed
	 * explicitly because the HubSpot body carries no `event` key of its own;
	 * the HMAC signature and X-UTMAT-Event headers are sent for every
	 * preset (harmless to receivers that ignore them). Records the outcome
	 * via {@see self::record_result()} regardless of success so the settings
	 * card can show the admin whether delivery is actually working; a non-2xx
	 * response includes a short excerpt of the receiver's body so HubSpot's
	 * validation errors are readable under "Last delivery".
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $payload Shaped payload.
	 * @param string               $event   Generic event name: 'conversion', 'lead' or 'test'.
	 * @return bool Whether the request was sent and received a 2xx response.
	 */
	private static function deliver( array $payload, string $event ): bool {
		$url = self::url();
		if ( '' === $url ) {
			return false;
		}

		$body      = (string) wp_json_encode( $payload );
		$signature = hash_hmac( 'sha256', $body, self::secret() );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'X-UTMAT-Signature' => 'sha256=' . $signature,
					'X-UTMAT-Event'     => $event,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::record_result( false, $response->get_error_message() );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$ok   = $code >= 200 && $code < 300;

		if ( $ok ) {
			// translators: %d is the HTTP status code returned by the webhook receiver.
			self::record_result( true, sprintf( __( 'HTTP %d', 'utm-attribution-tracker' ), $code ) );
			return true;
		}

		$excerpt = trim( mb_substr( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ), 0, 120 ) );

		// translators: 1: HTTP status code returned by the webhook receiver, 2: excerpt of the receiver's response body.
		self::record_result( false, sprintf( __( 'HTTP %1$d: %2$s', 'utm-attribution-tracker' ), $code, $excerpt ) );

		return false;
	}

	/**
	 * Store the outcome of the most recent delivery attempt.
	 *
	 * @since 1.0.0
	 * @param bool   $ok     Whether delivery succeeded (2xx response).
	 * @param string $detail A short human-readable detail (status code or error message).
	 * @return void
	 */
	private static function record_result( bool $ok, string $detail ): void {
		update_option(
			self::OPTION_LAST,
			array(
				'time'   => time(),
				'ok'     => $ok,
				'detail' => substr( $detail, 0, 200 ),
			)
		);
	}

	/**
	 * Build and send the payload for a real WooCommerce conversion.
	 *
	 * Skipped without sending (and without touching the last-delivery
	 * record) when order events are toggled off, or when the HubSpot preset
	 * is selected — an order has no form fields to submit to a HubSpot form.
	 *
	 * @since 1.0.0
	 * @param int                  $order_id Order ID.
	 * @param float                $total    Order total.
	 * @param string               $currency Order currency code.
	 * @param array<string, mixed> $first    First-touch attribution set.
	 * @param array<string, mixed> $last     Last-touch attribution set.
	 * @return bool
	 */
	public static function send_conversion( int $order_id, float $total, string $currency, array $first, array $last ): bool {
		if ( ! self::events()['orders'] || 'hubspot' === self::preset() ) {
			return false;
		}

		$payload = array(
			'event'       => 'conversion',
			'site'        => home_url( '/' ),
			'order_id'    => $order_id,
			// A fixed-decimal string, not a JSON number: PHP floats like
			// 99.99 aren't exactly representable in binary, and on sites
			// where serialize_precision is set to a fixed value (rather
			// than -1) that shows up as literal "99.989999999999995" in
			// the payload. A string sidesteps the issue for every
			// receiver regardless of the sending site's php.ini.
			'order_total' => number_format( $total, 2, '.', '' ),
			'currency'    => $currency,
			'first_touch' => $first,
			'last_touch'  => $last,
			'timestamp'   => time(),
		);

		$shaped = self::shape_for_preset( $payload, self::preset() );
		if ( null === $shaped ) {
			return false;
		}

		return self::deliver( $shaped, 'conversion' );
	}

	/**
	 * Build and send the payload for a recorded form lead.
	 *
	 * Hooked on `utmat_lead_recorded`. Skipped without sending (and without
	 * touching the last-delivery record) when lead events are toggled off or
	 * no URL is configured.
	 *
	 * @since 1.0.0
	 * @param string               $integration Integration slug, e.g. 'contact-form-7'.
	 * @param array<string, mixed> $record     The recorded lead (form_id, form_title, posted, created_at, page_url, fields).
	 * @return bool
	 */
	public static function send_lead( string $integration, array $record ): bool {
		if ( '' === self::url() || ! self::events()['leads'] ) {
			return false;
		}

		$shaped = self::shape_for_preset( self::build_lead_payload( $integration, $record ), self::preset() );
		if ( null === $shaped ) {
			return false;
		}

		return self::deliver( $shaped, 'lead' );
	}

	/**
	 * Build the generic lead payload (also the Zapier and Make body).
	 *
	 * The touch arrays are embedded exactly as Attribution returns them, so
	 * a receiver already handling `event: conversion` sees the same touch
	 * keys; `fields` is the map Recorder::clean_fields() already capped (50
	 * entries of 1,000 characters) — no second truncation happens here.
	 *
	 * @since 1.0.0
	 * @param string               $integration Integration slug.
	 * @param array<string, mixed> $record     The recorded lead.
	 * @return array<string, mixed>
	 */
	private static function build_lead_payload( string $integration, array $record ): array {
		$form_id    = $record['form_id'] ?? null;
		$form_title = $record['form_title'] ?? null;
		$page_url   = $record['page_url'] ?? null;

		return array(
			'event'       => 'lead',
			'site'        => home_url( '/' ),
			'integration' => $integration,
			'form_id'     => is_scalar( $form_id ) ? (string) $form_id : '',
			'form_title'  => is_scalar( $form_title ) ? (string) $form_title : '',
			'fields'      => isset( $record['fields'] ) && is_array( $record['fields'] ) ? $record['fields'] : array(),
			'first_touch' => Attribution::get_first(),
			'last_touch'  => isset( $record['posted'] ) && is_array( $record['posted'] ) ? $record['posted'] : array(),
			'page_url'    => is_scalar( $page_url ) ? (string) $page_url : '',
			'visitor_id'  => Attribution::visitor_id(),
			'timestamp'   => isset( $record['created_at'] ) && is_numeric( $record['created_at'] ) ? (int) $record['created_at'] : time(),
		);
	}

	/**
	 * Send a synthetic test payload — used by the settings card's "Send test
	 * webhook" button so the admin can verify their endpoint and signature
	 * verification before relying on it for real events.
	 *
	 * The lead-shaped test is sent while lead events are (effectively) on,
	 * so the preset the admin selected is exercised exactly as a real form
	 * submission would deliver it; with leads off and orders on, the
	 * conversion-shaped test is sent instead. Both off sends nothing.
	 *
	 * @since 1.0.0
	 * @param string    $preset Overrides the saved preset when it is one of PRESETS.
	 * @param bool|null $leads  Overrides the saved leads toggle when not null.
	 * @return bool
	 */
	public static function send_test( string $preset = '', ?bool $leads = null ): bool {
		if ( ! in_array( $preset, self::PRESETS, true ) ) {
			$preset = self::preset();
		}

		$events = self::events();
		if ( null !== $leads ) {
			$events['leads'] = $leads;
		}

		if ( $events['leads'] ) {
			$touch = array(
				'utm_source'   => 'test',
				'utm_medium'   => 'test',
				'utm_campaign' => 'utm-attribution-tracker-test',
			);

			$payload                = self::build_lead_payload(
				'test',
				array(
					'form_id'    => 0,
					'form_title' => __( 'Test form', 'utm-attribution-tracker' ),
					'posted'     => $touch,
					'created_at' => time(),
					'fields'     => array(
						'Email'      => self::TEST_EMAIL,
						'First name' => 'Test',
						'Last name'  => 'Lead',
						'Phone'      => '+15555550123',
						'Message'    => __( 'Sent from UTM & Attribution Tracker', 'utm-attribution-tracker' ),
					),
				)
			);
			$payload['event']       = 'test';
			$payload['first_touch'] = $touch;

			$shaped = self::shape_for_preset( $payload, $preset );
			if ( null === $shaped ) {
				return false;
			}

			return self::deliver( $shaped, 'test' );
		}

		if ( ! $events['orders'] ) {
			self::record_result( false, __( 'Enable at least one event to send a test', 'utm-attribution-tracker' ) );
			return false;
		}

		if ( 'hubspot' === $preset ) {
			self::record_result( false, __( 'Enable form leads to test the HubSpot preset', 'utm-attribution-tracker' ) );
			return false;
		}

		$touch = array(
			'utm_source'   => 'test',
			'utm_medium'   => 'test',
			'utm_campaign' => 'utm-attribution-tracker-test',
		);

		$shaped = self::shape_for_preset(
			array(
				'event'       => 'test',
				'site'        => home_url( '/' ),
				'order_id'    => 0,
				'order_total' => number_format( 99.99, 2, '.', '' ),
				'currency'    => 'USD',
				'first_touch' => $touch,
				'last_touch'  => $touch,
				'timestamp'   => time(),
			),
			$preset
		);
		if ( null === $shaped ) {
			return false;
		}

		return self::deliver( $shaped, 'test' );
	}

	/**
	 * Shape a generic payload for the selected preset.
	 *
	 * `zapier` and `make` receive the generic payload untouched (their
	 * receivers parse nested JSON themselves); `gohighlevel` receives the
	 * flattened snake_case object; `hubspot` receives the Forms API v3
	 * submission body — or null when the payload cannot be sent under that
	 * preset (no e-mail field detected for HubSpot, which cannot create a
	 * contact without one).
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $payload Generic payload.
	 * @param string               $preset  One of PRESETS.
	 * @return array<string, mixed>|null The body to deliver, or null to send nothing.
	 */
	private static function shape_for_preset( array $payload, string $preset ): ?array {
		if ( 'gohighlevel' === $preset ) {
			return self::flatten( $payload );
		}

		if ( 'hubspot' === $preset ) {
			return self::hubspot_body( $payload );
		}

		return $payload;
	}

	/**
	 * Build the HubSpot Forms API v3 submission body for a lead payload.
	 *
	 * Identity fields (email, firstname, lastname, phone) come from the
	 * mapping heuristic; the five conventional utm_* contact properties come
	 * from the last touch and are only sent when non-empty, because HubSpot
	 * rejects a submission whose field is not on the form with HTTP 400 —
	 * the help text tells the admin which hidden fields to add. The
	 * visitor's own hubspotutk tracking cookie is forwarded when present so
	 * HubSpot can link the submission to its visitor record; the IP address
	 * is deliberately omitted (the plugin only ever holds a salted hash).
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $payload Generic lead or test payload.
	 * @return array<string, mixed>|null The submission body, or null when no e-mail was detected.
	 */
	private static function hubspot_body( array $payload ): ?array {
		$fields   = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
		$identity = self::identity_fields( $fields );

		if ( '' === $identity['email'] ) {
			self::record_result( false, __( 'No email field detected', 'utm-attribution-tracker' ) );
			return null;
		}

		$identity_map = array(
			'email'      => 'email',
			'first_name' => 'firstname',
			'last_name'  => 'lastname',
			'phone'      => 'phone',
		);

		$body = array(
			'submittedAt' => (int) ( $payload['timestamp'] ?? time() ) * 1000,
			'fields'      => array(),
		);

		foreach ( $identity_map as $key => $name ) {
			if ( '' !== $identity[ $key ] ) {
				$body['fields'][] = array(
					'objectTypeId' => '0-1',
					'name'         => $name,
					'value'        => $identity[ $key ],
				);
			}
		}

		$last = isset( $payload['last_touch'] ) && is_array( $payload['last_touch'] ) ? $payload['last_touch'] : array();
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $key ) {
			$value = isset( $last[ $key ] ) && is_scalar( $last[ $key ] ) ? (string) $last[ $key ] : '';
			if ( '' !== $value ) {
				$body['fields'][] = array(
					'objectTypeId' => '0-1',
					'name'         => $key,
					'value'        => $value,
				);
			}
		}

		$context = array(
			'pageUri'  => (string) ( $payload['page_url'] ?? '' ),
			'pageName' => (string) ( $payload['form_title'] ?? '' ),
		);

		$hutk = isset( $_COOKIE['hubspotutk'] ) && is_string( $_COOKIE['hubspotutk'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['hubspotutk'] ) )
			: '';
		if ( 1 === preg_match( '/^[a-f0-9]{32}$/i', $hutk ) ) {
			$context['hutk'] = $hutk;
		}

		$body['context'] = $context;

		return $body;
	}

	/**
	 * Flatten a payload into GoHighLevel's single-level snake_case object.
	 *
	 * Every top-level scalar passes through; each touch key passes through
	 * under its own flat name (first-touch keys prefixed `first_`), with
	 * `lp` renamed to `landing_page`, `ch` to `channel` and `rh` to
	 * `referrer_host` (Attribution::field_name()'s mapping) and `ts`
	 * dropped — so custom parameters and later touch keys need no change
	 * here. The twelve built-in whitelist keys plus `landing_page` are
	 * always present for both touches (empty values sent as an empty
	 * string) so the key set is stable between the sample request a
	 * workflow is mapped from and a real lead; `channel`,
	 * `referrer_host` and custom keys appear only when present. Detected
	 * identity fields (empty strings when none match) and one
	 * `field_<slug>` entry per submitted form field are added to every
	 * payload.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $payload Generic payload.
	 * @return array<string, string|int|float>
	 */
	private static function flatten( array $payload ): array {
		$out = array();

		foreach ( array( 'event', 'site', 'integration', 'form_id', 'form_title', 'page_url', 'visitor_id', 'timestamp', 'order_id', 'order_total', 'currency' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) ) {
				$out[ $key ] = $payload[ $key ];
			}
		}

		$last  = isset( $payload['last_touch'] ) && is_array( $payload['last_touch'] ) ? $payload['last_touch'] : array();
		$first = isset( $payload['first_touch'] ) && is_array( $payload['first_touch'] ) ? $payload['first_touch'] : array();

		self::flatten_touch( $last, '', $out );
		self::flatten_touch( $first, 'first_', $out );

		$fields   = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
		$identity = self::identity_fields( $fields );

		$out['email']      = $identity['email'];
		$out['phone']      = $identity['phone'];
		$out['first_name'] = $identity['first_name'];
		$out['last_name']  = $identity['last_name'];

		$position = 0;
		foreach ( $fields as $label => $value ) {
			++$position;
			$slug = str_replace( '-', '_', sanitize_key( remove_accents( (string) $label ) ) );
			$key  = '' !== $slug && ! isset( $out[ 'field_' . $slug ] ) ? 'field_' . $slug : 'field_' . $position;
			while ( isset( $out[ $key ] ) ) {
				++$position;
				$key = 'field_' . $position;
			}

			$out[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $out;
	}

	/**
	 * Flatten one attribution touch into the GoHighLevel body.
	 *
	 * Writes into `$out` by reference so the last touch and the first touch
	 * (whose keys carry the `first_` prefix) share one key namespace.
	 *
	 * @since 1.0.0
	 * @param array<string, string|int>       $touch  The attribution set.
	 * @param string                          $prefix '' for the last touch, 'first_' for the first touch.
	 * @param array<string, string|int|float> $out  The body being built (modified by reference).
	 * @return void
	 */
	private static function flatten_touch( array $touch, string $prefix, array &$out ): void {
		// The stable key set: the twelve built-in parameters plus the
		// landing page (`lp` in the touch, `landing_page` when flattened),
		// always present so the structure never changes between the sample
		// request and real events.
		foreach ( array_merge( Tracker::PARAM_WHITELIST, array( 'lp' ) ) as $key ) {
			$flat_key                   = Attribution::field_name( $key );
			$out[ $prefix . $flat_key ] = isset( $touch[ $key ] ) && is_scalar( $touch[ $key ] ) ? (string) $touch[ $key ] : '';
		}

		// Present-only keys: channel, referrer host and custom parameters.
		foreach ( $touch as $key => $value ) {
			if ( 'ts' === $key || ! is_string( $key ) || ! is_scalar( $value ) ) {
				continue;
			}
			$flat_key = Attribution::field_name( $key );
			if ( ! isset( $out[ $prefix . $flat_key ] ) ) {
				$out[ $prefix . $flat_key ] = (string) $value;
			}
		}
	}

	/**
	 * Detect the identity fields (email, phone, first/last name) in a lead's
	 * submitted field values, keyed by label.
	 *
	 * First match wins per identity, in this order: e-mail — the first value
	 * that is a valid address, else the first non-empty value on a field
	 * labelled e-mail; phone — the first phone-labelled field, else the
	 * first phone-shaped value; names — `first`/`given`/`last`/`sur`/`family`
	 * name labels before looser `first`/`last`/`surname` matches. When
	 * neither name matched, a label ending in (full) `name` splits its value
	 * on the first space into first and last name.
	 *
	 * @since 1.0.0
	 * @param array<string, string> $fields Cleaned field values keyed by label.
	 * @return array{email: string, phone: string, first_name: string, last_name: string}
	 */
	private static function identity_fields( array $fields ): array {
		$identity = array(
			'email'      => '',
			'phone'      => '',
			'first_name' => '',
			'last_name'  => '',
		);

		$values = array();
		foreach ( $fields as $label => $value ) {
			$values[ (string) $label ] = mb_substr( trim( is_scalar( $value ) ? (string) $value : '' ), 0, 255 );
		}

		foreach ( $values as $value ) {
			if ( '' !== $value && is_email( $value ) ) {
				$identity['email'] = $value;
				break;
			}
		}
		if ( '' === $identity['email'] ) {
			foreach ( $values as $label => $value ) {
				if ( '' !== $value && 1 === preg_match( '/e-?mail/i', $label ) ) {
					$identity['email'] = $value;
					break;
				}
			}
		}

		foreach ( $values as $label => $value ) {
			if ( '' !== $value && 1 === preg_match( '/\b(phone|tel|telephone|mobile|cell)\b/i', $label ) ) {
				$identity['phone'] = $value;
				break;
			}
		}
		if ( '' === $identity['phone'] ) {
			foreach ( $values as $value ) {
				if ( '' !== $value && 1 === preg_match( '/^\+?[0-9\s().-]{7,20}$/', $value ) && preg_match_all( '/[0-9]/', $value ) >= 7 ) {
					$identity['phone'] = $value;
					break;
				}
			}
		}

		foreach ( $values as $label => $value ) {
			if ( 1 === preg_match( '/^(first|given)[\s_-]*name$/i', $label ) ) {
				$identity['first_name'] = $value;
				break;
			}
		}
		if ( '' === $identity['first_name'] ) {
			foreach ( $values as $label => $value ) {
				if ( 1 === preg_match( '/\bfirst\b/i', $label ) ) {
					$identity['first_name'] = $value;
					break;
				}
			}
		}

		foreach ( $values as $label => $value ) {
			if ( 1 === preg_match( '/^(last|sur|family)[\s_-]*name$/i', $label ) ) {
				$identity['last_name'] = $value;
				break;
			}
		}
		if ( '' === $identity['last_name'] ) {
			foreach ( $values as $label => $value ) {
				if ( 1 === preg_match( '/\b(last|surname)\b/i', $label ) ) {
					$identity['last_name'] = $value;
					break;
				}
			}
		}

		// Neither name rule matched: any label ending in "(full) name" is
		// a full-name candidate — split it on the first space.
		if ( '' === $identity['first_name'] && '' === $identity['last_name'] ) {
			foreach ( $values as $label => $value ) {
				if ( 1 !== preg_match( '/(^|[\s_-])(full[\s_-]*)?name$/i', $label ) ) {
					continue;
				}
				$parts = explode( ' ', trim( preg_replace( '/\s+/u', ' ', $value ) ), 2 );
				if ( isset( $parts[0] ) && '' !== $parts[0] ) {
					$identity['first_name'] = $parts[0];
				}
				if ( ! empty( $parts[1] ) ) {
					$identity['last_name'] = $parts[1];
				}
				break;
			}
		}

		return $identity;
	}
}
