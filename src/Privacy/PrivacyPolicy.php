<?php
/**
 * PrivacyPolicy — registers suggested privacy policy text.
 *
 * @package UTMAT\Privacy
 */

namespace UTMAT\Privacy;

use UTMAT\DB\Retention;
use UTMAT\Reports\ConversionWebhook;

defined( 'ABSPATH' ) || exit;

/**
 * PrivacyPolicy
 *
 * @since 1.0.0
 */
class PrivacyPolicy {

	/**
	 * Register suggested privacy policy text with WordPress.
	 *
	 * Hooked on `admin_init`. Describes what the plugin stores (salted IP
	 * hash, user agent, referrer, UTM values), why, and the retention period.
	 * The opening sentence depends on the untagged-visit tracking setting:
	 * on (the default) it says the first page of every visit is recorded,
	 * off it covers campaign links only. The closing sentence depends on the
	 * webhook configuration: when a webhook URL is set, attributed orders
	 * and form submissions (including the submitted form field values) are
	 * forwarded to that external service.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$days = Retention::days();
		if ( $days > 0 ) {
			$retention = sprintf(
				/* translators: %d: retention window in days. */
				__( 'Recorded events are automatically deleted after %d days.', 'utm-attribution-tracker' ),
				$days
			);
		} else {
			$retention = __( 'Recorded events are kept until manually deleted.', 'utm-attribution-tracker' );
		}

		if ( 1 === (int) get_option( 'utmat_track_visits', 1 ) ) {
			$opening = __( 'This site uses UTM & Attribution Tracker to measure where visitors come from and how marketing campaigns perform. When you arrive on the site, the plugin records the first page of your visit, the address of the site you came from (referrer), any campaign parameters (UTM values, advertising click IDs and any additional URL parameters the site owner has configured) in the URL, your browser identification string (user agent), and a salted one-way hash of your IP address.', 'utm-attribution-tracker' );
		} else {
			$opening = __( 'This site uses UTM & Attribution Tracker to measure the performance of marketing campaigns. When you visit a page through a campaign link, the plugin records the campaign parameters (UTM values, advertising click IDs and any additional URL parameters the site owner has configured) from the URL, the address of the page you came from (referrer), your browser identification string (user agent), and a salted one-way hash of your IP address.', 'utm-attribution-tracker' );
		}

		$content  = '<p>' . $opening . ' ' . __( 'The same data is recorded when you submit a form after arriving through a campaign link.', 'utm-attribution-tracker' ) . ' ' . __( 'The raw IP address is never stored, and the hash cannot be linked to you across other websites.', 'utm-attribution-tracker' ) . '</p>';
		$content .= '<p>' . __( 'This data is used for aggregate campaign statistics.', 'utm-attribution-tracker' ) . ' ';

		if ( '' !== ConversionWebhook::url() ) {
			$content .= __( 'Attributed orders and form submissions, including the values you entered in the form, are forwarded to an external service configured by the site owner.', 'utm-attribution-tracker' );
		} else {
			$content .= __( 'It is not shared with third parties.', 'utm-attribution-tracker' );
		}

		$content .= ' ' . $retention . '</p>';

		wp_add_privacy_policy_content( __( 'UTM & Attribution Tracker', 'utm-attribution-tracker' ), $content );
	}
}
