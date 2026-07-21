<?php
/**
 * ConversionWebhook — outbound POST to a configurable URL on new conversion.
 *
 * Disabled by default (the `utmat_webhook_url` option is empty). When
 * a WooCommerce order is attributed (see Integrations\WooCommerce), the
 * conversion payload — order total, currency, and the first/last-touch
 * attribution sets — is POSTed to the configured URL, signed with an
 * HMAC-SHA256 signature over a per-site secret so the receiver can verify
 * the request actually came from this site.
 *
 * @package UTMAT\Reports
 */

namespace UTMAT\Reports;

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
	 * Timeout in seconds for the outbound request.
	 *
	 * Kept short because delivery is a blocking call made during checkout
	 * (see attach_attribution() in the WooCommerce integration) — a slow or
	 * unreachable receiver must not stall order placement for long.
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
	 * Send a conversion payload to the configured webhook URL.
	 *
	 * No-ops when no URL is configured. Records the outcome via
	 * {@see self::record_result()} regardless of success so the settings
	 * card can show the admin whether delivery is actually working.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $payload Conversion payload (see build_payload()).
	 * @return bool Whether the request was sent and received a 2xx response.
	 */
	private static function deliver( array $payload ): bool {
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
					'Content-Type'       => 'application/json',
					'X-UTMAT-Signature' => 'sha256=' . $signature,
					'X-UTMAT-Event'     => (string) ( $payload['event'] ?? '' ),
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
		// translators: %d is the HTTP status code returned by the webhook receiver.
		self::record_result( $ok, sprintf( __( 'HTTP %d', 'utm-attribution-tracker' ), $code ) );

		return $ok;
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
	 * @since 1.0.0
	 * @param int                  $order_id Order ID.
	 * @param float                $total    Order total.
	 * @param string               $currency Order currency code.
	 * @param array<string, mixed> $first    First-touch attribution set.
	 * @param array<string, mixed> $last     Last-touch attribution set.
	 * @return bool
	 */
	public static function send_conversion( int $order_id, float $total, string $currency, array $first, array $last ): bool {
		return self::deliver(
			array(
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
			)
		);
	}

	/**
	 * Send a synthetic test payload — used by the settings card's "Send test
	 * webhook" button so the admin can verify their endpoint and signature
	 * verification before relying on it for real orders.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function send_test(): bool {
		return self::deliver(
			array(
				'event'       => 'test',
				'site'        => home_url( '/' ),
				'order_id'    => 0,
				'order_total' => number_format( 99.99, 2, '.', '' ),
				'currency'    => 'USD',
				'first_touch' => array(
					'utm_source'   => 'test',
					'utm_medium'   => 'test',
					'utm_campaign' => 'utm-attribution-tracker-test',
				),
				'last_touch'  => array(
					'utm_source'   => 'test',
					'utm_medium'   => 'test',
					'utm_campaign' => 'utm-attribution-tracker-test',
				),
				'timestamp'   => time(),
			)
		);
	}
}
