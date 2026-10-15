<?php
/**
 * Rewrites — pretty short-link rewrite rule for tracked redirects.
 *
 * Maps `/go/{slug}/` onto the existing `utmatra_track` query var so the
 * redirect handler in Tracker is reused untouched. The rule is registered on
 * `init` and must also be registered during activation, before the rewrite
 * flush, so the flushed rules include it.
 *
 * @package Utmatra\Tracking
 */

namespace Utmatra\Tracking;

use WP;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites
 */
class Rewrites {

	/**
	 * Register the `/go/{slug}/` rewrite rule.
	 *
	 * Hooked on `init`; also called directly from Plugin::activate() before
	 * flush_rewrite_rules() so activation flushes the rule into place.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function add_rules(): void {
		add_rewrite_rule(
			'^go/([a-z0-9_\-]+)/?$',
			'index.php?utmatra_track=$matches[1]',
			'top'
		);
	}

	/**
	 * Bridge pretty short-link requests into the existing redirect handler.
	 *
	 * Tracker::handle_incoming_request() runs on `init` and reads the slug
	 * from `$_GET`, but for a pretty `/go/{slug}/` request the query var only
	 * materialises when WP parses the rewrite rules — after `init`. Hooked on
	 * `parse_request`, this copies the parsed slug into `$_GET` and re-invokes
	 * the handler, which records the click and issues the 302.
	 *
	 * @since 1.0.0
	 * @param WP $wp The WordPress environment instance, with parsed query vars.
	 * @return void
	 */
	public static function handle_pretty_request( WP $wp ): void {
		if ( empty( $wp->query_vars['utmatra_track'] ) ) {
			return;
		}

		// Query-string requests were already handled on `init`.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['utmatra_track'] ) ) {
			return;
		}

		$_GET['utmatra_track'] = sanitize_key( (string) $wp->query_vars['utmatra_track'] );
		Tracker::handle_incoming_request();
	}
}
