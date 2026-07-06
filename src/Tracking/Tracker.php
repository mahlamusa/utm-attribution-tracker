<?php
/**
 * Tracker — records click events on init.
 *
 * Two tracking modes:
 *  1. Outbound redirect: /?utmat_track=<slug> → records event then wp_redirect().
 *  2. Passive incoming: any page load with utm_campaign present is recorded.
 *
 * Filter `utmat_track_logged_in_users` (bool, default false) controls whether
 * administrator clicks are recorded.
 *
 * @package UTMAT\Tracking
 */

namespace UTMAT\Tracking;

use UTMAT\DB\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Tracker
 */
class Tracker {

	/**
	 * Register the utmat_track query variable so WordPress parses it.
	 *
	 * @since 1.0.0
	 * @param string[] $vars Existing public query vars.
	 * @return string[]
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = 'utmat_track';
		return $vars;
	}

	/**
	 * Main entry-point hooked on `init` (priority 1).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function handle_incoming_request(): void {
		if ( ! self::should_track() ) {
			return;
		}

		// --- 1. Outbound redirect ---
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$track_id = isset( $_GET['utmat_track'] ) ? sanitize_key( wp_unslash( $_GET['utmat_track'] ) ) : '';

		if ( ! empty( $track_id ) ) {
			$link = LinkManager::get( $track_id );
			if ( $link ) {
				$destination = LinkManager::build_url( $link );
				self::record(
					array(
						'link_id'      => $track_id,
						'utm_source'   => $link['utm_source'] ?? '',
						'utm_medium'   => $link['utm_medium'] ?? '',
						'utm_campaign' => $link['utm_campaign'] ?? '',
						'utm_id'       => $link['utm_id'] ?? '',
						'utm_term'     => $link['utm_term'] ?? '',
						'utm_content'  => $link['utm_content'] ?? '',
						'destination'  => $destination,
					)
				);
				wp_redirect( $destination, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}
		}

		// --- 2. Passive incoming UTM tracking ---
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$utm_campaign = isset( $_GET['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ) ) : '';
		if ( ! empty( $utm_campaign ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			self::record(
				array(
					'link_id'      => 'incoming',
					'utm_source'   => isset( $_GET['utm_source'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) : '',
					'utm_medium'   => isset( $_GET['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_medium'] ) ) : '',
					'utm_campaign' => $utm_campaign,
					'utm_id'       => isset( $_GET['utm_id'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_id'] ) ) : '',
					'utm_term'     => isset( $_GET['utm_term'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_term'] ) ) : '',
					'utm_content'  => isset( $_GET['utm_content'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_content'] ) ) : '',
					'destination'  => esc_url_raw( ( is_ssl() ? 'https' : 'http' ) . '://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ) ),
				)
			);
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Determine whether the current request should be tracked.
	 *
	 * Cron, AJAX, and REST API requests are never page views, so they are
	 * always excluded — otherwise an unrelated plugin/theme AJAX or REST
	 * call that happens to forward `utm_campaign` in its query string would
	 * get recorded as a click, inflating campaign stats with noise.
	 *
	 * By default logged-in admins are also excluded.
	 * Return `true` from the `utmat_track_logged_in_users` filter to override.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private static function should_track(): bool {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( false !== strpos( $request_uri, '/' . rest_get_url_prefix() . '/' ) ) {
			return false;
		}

		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			/**
			 * Whether to track clicks from logged-in administrators.
			 *
			 * @var bool $track_admins
			 */
			$track_admins = apply_filters( 'utmat_track_logged_in_users', false );
			if ( ! $track_admins ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Insert a tracking event row into the database.
	 *
	 * @since 1.0.0
	 * @param array<string, string> $data Event fields.
	 * @return void
	 */
	public static function record( array $data ): void {
		global $wpdb;

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '';
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Schema::table(),
			array(
				'link_id'      => substr( $data['link_id'] ?? '', 0, 64 ),
				'utm_source'   => substr( $data['utm_source'] ?? '', 0, 100 ),
				'utm_medium'   => substr( $data['utm_medium'] ?? '', 0, 100 ),
				'utm_campaign' => substr( $data['utm_campaign'] ?? '', 0, 100 ),
				'utm_id'       => substr( $data['utm_id'] ?? '', 0, 100 ),
				'utm_term'     => substr( $data['utm_term'] ?? '', 0, 100 ),
				'utm_content'  => substr( $data['utm_content'] ?? '', 0, 255 ),
				'destination'  => substr( $data['destination'] ?? '', 0, 2083 ),
				'ip_hash'      => hash( 'sha256', $ip ),
				'user_agent'   => $ua,
				'referrer'     => substr( $ref, 0, 2083 ),
				'clicked_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
