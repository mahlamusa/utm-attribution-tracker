<?php
/**
 * PrivacyPolicy — registers suggested privacy policy text.
 *
 * @package Utmatra\Privacy
 */

namespace Utmatra\Privacy;

use Utmatra\DB\Retention;
use Utmatra\Tracking\Attribution;
use Utmatra\Reports\ConversionWebhook;

defined( 'ABSPATH' ) || exit;

/**
 * PrivacyPolicy
 *
 * @since 1.0.0
 * @since 1.0.0 Describes both cookies and their lifetime.
 */
class PrivacyPolicy {

	/**
	 * Register suggested privacy policy text with WordPress.
	 *
	 * Hooked on `admin_init`. Describes what the plugin stores (salted IP
	 * hash, user agent, referrer, UTM values, and, while the cookie master
	 * switch is on, the attribution and visitor identifier cookies with
	 * their lifetime; behind a consent solution those wait for consent),
	 * why, and the retention period.
	 * The opening sentence depends on the untagged-visit tracking setting:
	 * on (the default) it says the first page of every visit is recorded,
	 * off it covers campaign links only. The closing sentence depends on the
	 * webhook configuration: when a webhook URL is set, attributed orders
	 * and form submissions (including the submitted form field values) are
	 * forwarded to that external service.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Describes both cookies and no longer implies consent is always asked.
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
				__( 'Recorded events are automatically deleted after %d days.', 'utmatra-utm-attribution-and-tracking' ),
				$days
			);
		} else {
			$retention = __( 'Recorded events are kept until manually deleted.', 'utmatra-utm-attribution-and-tracking' );
		}

		if ( 1 === (int) get_option( 'utmatra_track_visits', 1 ) ) {
			$opening = __( 'This site uses Utmatra to measure where visitors come from and how marketing campaigns perform. When you arrive on the site, the plugin records the first page of your visit, the address of the site you came from (referrer), any campaign parameters (UTM values, advertising click IDs and any additional URL parameters the site owner has configured) in the URL, your browser identification string (user agent), and a salted one-way hash of your IP address.', 'utmatra-utm-attribution-and-tracking' );
		} else {
			$opening = __( 'This site uses Utmatra to measure the performance of marketing campaigns. When you visit a page through a campaign link, the plugin records the campaign parameters (UTM values, advertising click IDs and any additional URL parameters the site owner has configured) from the URL, the address of the page you came from (referrer), your browser identification string (user agent), and a salted one-way hash of your IP address.', 'utmatra-utm-attribution-and-tracking' );
		}

		$cookies = '';
		if ( Attribution::enabled() ) {
			$cookies = ' ' . sprintf(
				/* translators: %d: cookie lifetime in days. */
				_n(
					'Two first-party cookies are kept for %d day: one holds the campaign that brought you here (campaign parameters, landing page and referring site), the other a random visitor identifier (not derived from your IP address or any personal data), so that your later orders or form submissions can be linked to that campaign. Where the site asks for cookie consent, they are only stored once you agree.',
					'Two first-party cookies are kept for %d days: one holds the campaign that brought you here (campaign parameters, landing page and referring site), the other a random visitor identifier (not derived from your IP address or any personal data), so that your later orders or form submissions can be linked to that campaign. Where the site asks for cookie consent, they are only stored once you agree.',
					Attribution::cookie_days(),
					'utmatra-utm-attribution-and-tracking'
				),
				Attribution::cookie_days()
			);
		}

		$content  = '<p>' . $opening . ' ' . __( 'The same data is recorded when you submit a form after arriving through a campaign link.', 'utmatra-utm-attribution-and-tracking' ) . $cookies . ' ' . __( 'The raw IP address is never stored, and the hash cannot be linked to you across other websites.', 'utmatra-utm-attribution-and-tracking' ) . '</p>';
		$content .= '<p>' . __( 'This data is used for aggregate campaign statistics.', 'utmatra-utm-attribution-and-tracking' ) . ' ';

		if ( '' !== ConversionWebhook::url() ) {
			$content .= __( 'Attributed orders and form submissions, including the values you entered in the form, are forwarded to an external service configured by the site owner.', 'utmatra-utm-attribution-and-tracking' );
		} else {
			$content .= __( 'It is not shared with third parties.', 'utmatra-utm-attribution-and-tracking' );
		}

		$content .= ' ' . $retention . '</p>';

		wp_add_privacy_policy_content( __( 'Utmatra', 'utmatra-utm-attribution-and-tracking' ), $content );
	}
}
