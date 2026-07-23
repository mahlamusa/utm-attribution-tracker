<?php
/**
 * Insights — per-lead traffic-source classification and message mapping.
 *
 * Pure function, framework-agnostic (no WP option/DB access), so it is
 * reusable both by the recent-leads dashboard widget and by a form-entry
 * detail view classifying a single entry's field values directly, without
 * touching the ring-buffer storage.
 *
 * @package UTMAT\Insights
 */

namespace UTMAT\Insights;

use UTMAT\Tracking\TrafficSource;

defined( 'ABSPATH' ) || exit;

/**
 * Insights
 *
 * @since 1.0.0
 */
class Insights {

	/**
	 * Classify a single lead/conversion's attribution data.
	 *
	 * Thin wrapper: bucket determination is entirely delegated to
	 * Tracking\TrafficSource::classify() (the single source of truth for the
	 * rule order and the paid-media whitelist) — this method only maps the
	 * returned bucket constant to a static, human-readable message. It must
	 * never duplicate any part of TrafficSource's rule order; if the
	 * classification logic needs to change, TrafficSource is the only place
	 * that changes.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $posted Attribution data in the same shape as TrafficSource::classify()'s $event param — e.g. utm_source, utm_medium, utm_campaign, gclid, referrer. Missing keys are treated as empty strings by TrafficSource.
	 *
	 * @return array{status: string, message: string}
	 */
	public static function classify( array $posted ): array {
		$status = TrafficSource::classify( $posted );

		if ( TrafficSource::TAGGED === $status ) {
			$campaign = isset( $posted['utm_campaign'] ) ? (string) $posted['utm_campaign'] : '';

			$message = '' !== $campaign
				? sprintf(
					/* translators: %s: campaign name from the utm_campaign parameter. */
					__( 'Tagged traffic (campaign: %s).', 'utm-attribution-tracker' ),
					$campaign
				)
				: __( 'Tagged traffic — campaign parameters present but no campaign name set.', 'utm-attribution-tracker' );

			return array(
				'status'  => $status,
				'message' => $message,
			);
		}

		$messages = array(
			TrafficSource::GOOGLE_ADS       => __( 'From a Google Ads click.', 'utm-attribution-tracker' ),
			TrafficSource::PAID             => __( 'From a paid campaign.', 'utm-attribution-tracker' ),
			TrafficSource::DIRECT           => __( 'No campaign data — this lead arrived without UTM parameters or a referrer.', 'utm-attribution-tracker' ),
			TrafficSource::ORGANIC_REFERRAL => __( 'Organic or referral traffic — no campaign tagging.', 'utm-attribution-tracker' ),
		);

		return array(
			'status'  => $status,
			'message' => $messages[ $status ] ?? '',
		);
	}
}
