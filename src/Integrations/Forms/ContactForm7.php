<?php
/**
 * Contact Form 7 integration — zero-config form attribution capture.
 *
 * Appends the visitor's last-touch attribution parameters to every CF7 form
 * as hidden fields (named after the parameter itself: `utm_source`,
 * `gclid`, `landing_page`, …) so submissions carry source data, and
 * resolves the matching `[_utm_source]`-style special mail tags in mail
 * templates from the submitted fields.
 *
 * Hooks register only when Contact Form 7 is active (`WPCF7_VERSION`
 * defined, checked at `plugins_loaded` priority 20). Without CF7 the class
 * is loaded but completely inert.
 *
 * @package UTMAT\Integrations\Forms
 */

namespace UTMAT\Integrations\Forms;

use UTMAT\Insights\Recorder;
use UTMAT\Tracking\Attribution;
use UTMAT\Tracking\Tracker;
use WPCF7_ContactForm;

defined( 'ABSPATH' ) || exit;

/**
 * ContactForm7
 *
 * @since 1.0.0
 * @since 1.0.0 Hidden fields follow Tracker::param_whitelist().
 */
class ContactForm7 {

	/**
	 * Register Contact Form 7 hooks — only when CF7 is active.
	 *
	 * Hooked on `plugins_loaded` priority 20 (CF7 may load after this plugin).
	 * Bails without adding a single hook when CF7 is absent.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Added the recent-leads recorder on `wpcf7_mail_sent`.
	 * @return void
	 */
	public static function maybe_init(): void {
		if ( ! defined( 'WPCF7_VERSION' ) ) {
			return;
		}

		add_filter( 'wpcf7_form_hidden_fields', array( self::class, 'add_hidden_fields' ) );
		add_filter( 'wpcf7_special_mail_tags', array( self::class, 'resolve_mail_tag' ), 10, 3 );
		add_action( 'wpcf7_mail_sent', array( self::class, 'record_lead' ) );
	}

	/**
	 * Append the last-touch attribution hidden fields to every CF7 form.
	 *
	 * Every last-touch field name (built-ins and custom parameters) is
	 * always appended — with the server-side value when the attribution
	 * cookie is readable, empty otherwise — so page caches serve HTML that
	 * still contains the fields and the front-end beacon can fill them
	 * client-side (it only fills empty fields, so a server-rendered value
	 * always wins). First-touch `_first` fields are not
	 * auto-added; the beacon fills them when a form author adds one manually.
	 *
	 * CF7 core esc_attr()s every hidden field name and value at render
	 * (WPCF7_ContactForm::form_hidden_fields()), so the input-sanitized
	 * values are passed raw here — escaping them too would double-encode.
	 *
	 * @since 1.0.0 Loops Tracker::param_whitelist() so custom parameters get hidden fields too.
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

		foreach ( array_merge( Tracker::param_whitelist(), array( 'lp' ) ) as $param ) {
			$name = Attribution::field_name( $param );
			if ( ! isset( $fields[ $name ] ) ) {
				$fields[ $name ] = $map[ $name ] ?? '';
			}
		}

		return $fields;
	}

	/**
	 * Resolve the attribution special mail tags from the submitted fields.
	 *
	 * Supports every recognised field name, including `_first` variants:
	 * `[_utm_source]`, `[_utm_campaign_first]`, `[_gclid]`, `[_landing_page]`,
	 * etc. CF7 special mail tags carry a leading underscore, which is stripped
	 * to find the field. Reads the value from the submitted POST fields (filled
	 * server-side or by the beacon), so the mail body reflects exactly what the
	 * form submitted. None of these names collide with CF7's own special tags
	 * (`_url`, `_user_*`, `_post_*`, `_site_*`, `_date`, `_time`, …).
	 *
	 * @since 1.0.0 Tag names follow the standard field names (`[_utm_source]` instead of a prefixed variant).
	 *
	 * @param string $output The tag output resolved so far.
	 * @param string $name   The tag name including its leading underscore, e.g. `_utm_source`.
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

	/**
	 * Record a lead for the recent-leads dashboard widget on successful submission.
	 *
	 * Fires on `wpcf7_mail_sent` — CF7 only runs this action after the
	 * notification email was sent, i.e. a genuine successful submission.
	 * Skips silently when the visitor carries no attribution data (nothing
	 * to classify).
	 *
	 * @since 1.0.0
	 * @param WPCF7_ContactForm $contact_form The submitted form.
	 * @return void
	 */
	public static function record_lead( $contact_form ): void {
		if ( ! $contact_form instanceof WPCF7_ContactForm || ! Attribution::has_data() ) {
			return;
		}

		Recorder::record(
			'contact-form-7',
			array(
				'form_id'    => $contact_form->id(),
				'form_title' => $contact_form->title(),
				'posted'     => Attribution::get_last(),
				'created_at' => time(),
			)
		);
	}

	/**
	 * Report per-form attribution field coverage for Tracking Doctor diagnostics.
	 *
	 * Every CF7 form is always `complete`: add_hidden_fields() unconditionally
	 * appends every recognised field to every form via `wpcf7_form_hidden_fields`,
	 * so there is nothing a form author could misconfigure. Returns an empty
	 * array when CF7 is inactive.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{form_id: int|string, form_title: string, status: string, present: string[], missing: string[]}>
	 */
	public static function get_form_status(): array {
		if ( ! defined( 'WPCF7_VERSION' ) || ! class_exists( 'WPCF7_ContactForm' ) ) {
			return array();
		}

		$present = Attribution::field_names();
		$rows    = array();

		foreach ( WPCF7_ContactForm::find() as $form ) {
			$rows[] = array(
				'form_id'    => $form->id(),
				'form_title' => $form->title(),
				'status'     => 'complete',
				'present'    => $present,
				'missing'    => array(),
			);
		}

		return $rows;
	}
}
