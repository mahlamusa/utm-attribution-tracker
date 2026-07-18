<?php
/**
 * LinkManager — CRUD for managed tracked links.
 *
 * Links are stored as a serialised array in the `utmat_links` option.
 *
 * @package UTMAT\Tracking
 */

namespace UTMAT\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * LinkManager
 */
class LinkManager {

	private const OPTION_KEY = 'utmat_links';

	/**
	 * Seed an example link on first activation.
	 *
	 * Uses the current site URL as the destination placeholder so no
	 * third-party domain is hard-coded. Admins update it to their own URL.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function seed_defaults(): void {
		$existing = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		if ( ! isset( $existing['example-campaign'] ) ) {
			$existing['example-campaign'] = array(
				'label'        => 'Example Campaign',
				'destination'  => home_url( '/' ),
				'utm_source'   => 'plugin',
				'utm_medium'   => 'banner',
				'utm_campaign' => 'example',
				'utm_id'       => '',
				'utm_term'     => 'demo',
				'utm_content'  => 'example link',
			);
			update_option( self::OPTION_KEY, $existing );
		}
	}

	/**
	 * Return all managed links keyed by their slug.
	 *
	 * @since 1.0.0
	 * @return array<string, array<string, string>>
	 */
	public static function all(): array {
		$links = get_option( self::OPTION_KEY, array() );
		return is_array( $links ) ? $links : array();
	}

	/**
	 * Return a single link by slug, or null if not found.
	 *
	 * @since 1.0.0
	 * @param string $id Link slug.
	 * @return array<string, string>|null
	 */
	public static function get( string $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/**
	 * Build the full destination URL with UTM query parameters appended.
	 *
	 * Fragment-safe: a `#fragment` in the destination is split off before the
	 * query parameters are appended and re-attached afterwards, so
	 * `https://ex.com/page#pricing` yields `https://ex.com/page?utm_…#pricing`.
	 *
	 * @since 1.0.0
	 * @param array<string, string> $link Link data array.
	 * @return string
	 */
	public static function build_url( array $link ): string {
		$base   = $link['destination'] ?? '';
		$params = array();

		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_term', 'utm_content' ) as $key ) {
			if ( ! empty( $link[ $key ] ) ) {
				$params[ $key ] = $link[ $key ];
			}
		}

		if ( empty( $params ) ) {
			return $base;
		}

		$fragment = '';
		$hash_pos = strpos( $base, '#' );
		if ( false !== $hash_pos ) {
			$fragment = substr( $base, $hash_pos );
			$base     = substr( $base, 0, $hash_pos );
		}

		$separator = ( strpos( $base, '?' ) !== false ) ? '&' : '?';
		return $base . $separator . http_build_query( $params ) . $fragment;
	}

	/**
	 * Build the on-site redirect URL for a given link slug.
	 *
	 * @since 1.0.0
	 * @param string $id Link slug.
	 * @return string
	 */
	public static function redirect_url( string $id ): string {
		return add_query_arg( 'utmat_track', rawurlencode( $id ), home_url( '/' ) );
	}

	/**
	 * Save a new or updated link.
	 *
	 * @since 1.0.0
	 * @param string                $id   Link slug.
	 * @param array<string, string> $data Link fields.
	 * @return void
	 */
	public static function save( string $id, array $data ): void {
		$all        = self::all();
		$all[ $id ] = $data;
		update_option( self::OPTION_KEY, $all );
	}

	/**
	 * Delete a managed link by slug.
	 *
	 * @since 1.0.0
	 * @param string $id Link slug.
	 * @return void
	 */
	public static function delete( string $id ): void {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION_KEY, $all );
	}
}
