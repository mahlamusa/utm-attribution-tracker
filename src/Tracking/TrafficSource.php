<?php
/**
 * TrafficSource — shared traffic-source classification rule set.
 *
 * Single source of truth for the Direct / Organic-Referral / Paid / Google
 * Ads / Tagged bucketing rule order, consumed two ways: sql_case_expression()
 * for the dashboard's aggregate `by_traffic_source` breakdown, and classify()
 * for per-lead classification (e.g. the recent-leads dashboard widget). Both
 * must stay derived from the same rule order and the same PAID_MEDIA
 * whitelist so the two evaluation paths cannot silently diverge.
 *
 * @package UTMAT\Tracking
 */

namespace UTMAT\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * TrafficSource
 *
 * @since 1.0.0
 */
class TrafficSource {

	/**
	 * The utm_medium values treated as paid media (case-insensitive).
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	public const PAID_MEDIA = array( 'cpc', 'ppc', 'paid', 'paidsearch', 'cpm', 'display' );

	/**
	 * Machine-readable bucket: Google Ads.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const GOOGLE_ADS = 'google_ads';

	/**
	 * Machine-readable bucket: paid media (non-Google Ads).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const PAID = 'paid';

	/**
	 * Machine-readable bucket: direct (no UTM, no click ID, no referrer).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const DIRECT = 'direct';

	/**
	 * Machine-readable bucket: organic/referral (no UTM, no click ID, has referrer).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const ORGANIC_REFERRAL = 'organic_referral';

	/**
	 * Machine-readable bucket: tagged fallback (UTM present, no other rule matched).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const TAGGED = 'tagged';

	/**
	 * Chart-facing display label per machine-readable bucket.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string, string>
	 */
	public const DISPLAY_LABELS = array(
		self::GOOGLE_ADS       => 'Google Ads',
		self::PAID             => 'Paid',
		self::DIRECT           => 'Direct',
		self::ORGANIC_REFERRAL => 'Organic / Referral',
		self::TAGGED           => 'Tagged',
	);

	/**
	 * Build the R1 rule order as a single SQL `CASE WHEN ... END` fragment.
	 *
	 * Column names are hardcoded to the `events` table's actual columns; the
	 * whitelist values are fixed class constants (never user input), so they
	 * are inlined directly rather than passed through `$wpdb->prepare()`.
	 * Callers alias the expression (`{$expression} AS label`) and GROUP BY it.
	 *
	 * @since 1.0.0
	 * @return string SQL `CASE WHEN ... END` expression (without an alias).
	 */
	public static function sql_case_expression(): string {
		$paid_media_list = "'" . implode( "','", array_map( 'esc_sql', self::PAID_MEDIA ) ) . "'";

		$no_utm      = "utm_source = '' AND utm_medium = '' AND utm_campaign = ''";
		$no_click_id = "gclid = '' AND fbclid = '' AND msclkid = '' AND ttclid = '' AND li_fat_id = '' AND twclid = ''";

		return "CASE
			WHEN ( gclid != '' AND utm_campaign != '' ) OR ( LOWER( utm_source ) LIKE '%google%' AND LOWER( utm_medium ) IN ({$paid_media_list}) ) THEN '" . esc_sql( self::DISPLAY_LABELS[ self::GOOGLE_ADS ] ) . "'
			WHEN LOWER( utm_medium ) IN ({$paid_media_list}) THEN '" . esc_sql( self::DISPLAY_LABELS[ self::PAID ] ) . "'
			WHEN {$no_utm} AND {$no_click_id} AND referrer = '' THEN '" . esc_sql( self::DISPLAY_LABELS[ self::DIRECT ] ) . "'
			WHEN {$no_utm} AND {$no_click_id} AND referrer != '' THEN '" . esc_sql( self::DISPLAY_LABELS[ self::ORGANIC_REFERRAL ] ) . "'
			ELSE '" . esc_sql( self::DISPLAY_LABELS[ self::TAGGED ] ) . "'
		END";
	}

	/**
	 * Classify a single event row using the identical R1 rule order.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $event Associative event row; keys match the `events` table columns. Missing keys are treated as empty strings.
	 * @return string One of the machine-readable bucket constants (GOOGLE_ADS, PAID, DIRECT, ORGANIC_REFERRAL, TAGGED).
	 */
	public static function classify( array $event ): string {
		$get = static function ( array $event, string $key ): string {
			return isset( $event[ $key ] ) ? (string) $event[ $key ] : '';
		};

		$utm_source   = $get( $event, 'utm_source' );
		$utm_medium   = strtolower( $get( $event, 'utm_medium' ) );
		$utm_campaign = $get( $event, 'utm_campaign' );
		$gclid        = $get( $event, 'gclid' );
		$referrer     = $get( $event, 'referrer' );

		$no_utm = '' === $utm_source && '' === $utm_medium && '' === $utm_campaign;

		$no_click_id = '' === $gclid
			&& '' === $get( $event, 'fbclid' )
			&& '' === $get( $event, 'msclkid' )
			&& '' === $get( $event, 'ttclid' )
			&& '' === $get( $event, 'li_fat_id' )
			&& '' === $get( $event, 'twclid' );

		$is_paid_medium = in_array( $utm_medium, self::PAID_MEDIA, true );

		if ( ( '' !== $gclid && '' !== $utm_campaign ) || ( false !== stripos( $utm_source, 'google' ) && $is_paid_medium ) ) {
			return self::GOOGLE_ADS;
		}

		if ( $is_paid_medium ) {
			return self::PAID;
		}

		if ( $no_utm && $no_click_id && '' === $referrer ) {
			return self::DIRECT;
		}

		if ( $no_utm && $no_click_id && '' !== $referrer ) {
			return self::ORGANIC_REFERRAL;
		}

		return self::TAGGED;
	}
}
