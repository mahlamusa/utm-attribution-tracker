<?php
/**
 * Contact Form 7 integration — zero-config form attribution capture.
 *
 * Appends the visitor's last-touch attribution parameters to every CF7 form
 * as hidden fields (named `utmat_*`) so submissions carry source data,
 * and resolves `[_utmat_*]` special mail tags in mail templates from
 * the submitted fields.
 *
 * Hooks register only when Contact Form 7 is active (`WPCF7_VERSION`
 * defined, checked at `plugins_loaded` priority 20). Without CF7 the class
 * is loaded but completely inert.
 *
 * @package UTMAT\Integrations\Forms
 */

namespace UTMAT\Integrations\Forms;

use UTMAT\Tracking\Attribution;
use UTMAT\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

/**
 * ContactForm7
 *
 * @since 1.0.0
 */
class ContactForm7 {

	/**
	 * Register Contact Form 7 hooks — only when CF7 is active.
	 *
	 * Hooked on `plugins_loaded` priority 20 (CF7 may load after this plugin).
	 * Bails without adding a single hook when CF7 is absent.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_init(): void {
		if ( ! defined( 'WPCF7_VERSION' ) ) {
			return;
		}

		add_filter( 'wpcf7_form_hidden_fields', array( self::class, 'add_hidden_fields' ) );
		add_filter( 'wpcf7_special_mail_tags', array( self::class, 'resolve_mail_tag' ), 10, 3 );
	}

	/**
	 * Append the last-touch attribution hidden fields to every CF7 form.
	 *
	 * All eleven field names are always appended — with the server-side value
	 * when the attribution cookie is readable, empty otherwise — so page
	 * caches serve HTML that still contains the fields and the front-end
	 * beacon can fill them client-side (it only fills empty fields, so a
	 * server-rendered value always wins). First-touch `_first` fields are not
	 * auto-added; the beacon fills them when a form author adds one manually.
	 *
	 * CF7 core esc_attr()s every hidden field name and value at render
	 * (WPCF7_ContactForm::form_hidden_fields()), so the input-sanitized
	 * values are passed raw here — escaping them too would double-encode.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $fields Hidden fields registered so far.
	 *
	 * @return array<string, string>
	 */
	public static function add_hidden_fields( $fields ): array {
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		$map = Attribution::field_map();

		foreach ( array_merge( Tracker::PARAM_WHITELIST, array( 'lp' ) ) as $param ) {
			$name = Attribution::field_name( $param );
			if ( ! isset( $fields[ $name ] ) ) {
				$fields[ $name ] = $map[ $name ] ?? '';
			}
		}

		return $fields;
	}

	/**
	 * Resolve `[_utmat_*]` special mail tags from the submitted fields.
	 *
	 * Supports every recognised field name, including `_first` variants:
	 * `[_utmat_source]`, `[_utmat_campaign_first]`, etc. Reads the
	 * value from the submitted POST fields (filled server-side or by the
	 * beacon), so the mail body reflects exactly what the form submitted.
	 *
	 * @since 1.0.0
	 *
	 * @param string $output The tag output resolved so far.
	 * @param string $name   The tag name including its leading underscore, e.g. `_utmat_source`.
	 * @param bool   $html   Whether the mail body is HTML.
	 *
	 * @return string
	 */
	public static function resolve_mail_tag( $output, $name, $html ) {
		$field = ltrim( (string) $name, '_' );

		if ( ! in_array( $field, Attribution::field_names(), true ) ) {
			return $output;
		}

		// CF7 verifies its own submission; these fields are attribution
		// metadata read during mail composition — no nonce applies. Non-string
		// values (crafted array fields) are ignored, and values are capped at
		// 255 chars to match the attribution writers.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$value = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] )
			? substr( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ), 0, 255 )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $html ? esc_html( $value ) : $value;
	}
}
