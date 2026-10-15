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
 * @package Utmatra\Integrations\Forms
 */

namespace Utmatra\Integrations\Forms;

use Utmatra\Insights\Recorder;
use Utmatra\Tracking\Attribution;
use WPCF7_ContactForm;
use WPCF7_Submission;

defined( 'ABSPATH' ) || exit;

/**
 * ContactForm7
 *
 * @since 1.0.0
 */
class ContactForm7 {

	/**
	 * CF7 tag basetypes that are not submitted values of the form's own
	 * fields: hidden inputs, uploads, the submit button and quiz/response
	 * tags.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const SKIPPED_BASETYPES = array( 'hidden', 'file', 'submit', 'response', 'quiz' );

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

		foreach ( Attribution::touch_keys() as $param ) {
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
	 * `[_channel]`, `[_referrer_host]`, etc. CF7 special mail tags carry a
	 * leading underscore, which is stripped
	 * to find the field. Reads the value from the submitted POST fields (filled
	 * server-side or by the beacon), so the mail body reflects exactly what the
	 * form submitted. A tag already resolved by an earlier handler on the
	 * same filter (CF7's own `_date`, `_time`, `_url`, `_remote_ip`,
	 * `_user_*`, `_post_*`, `_site_*`, … handlers run first) is passed
	 * through untouched, so a custom parameter named like one of CF7's
	 * special tags can never hijack it — only `null` output (a tag CF7 did
	 * not recognise) is looked up here.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Truncates by character, not byte.
	 *
	 * @param string|null $output The tag output resolved so far, null when unrecognised.
	 * @param string      $name   The tag name including its leading underscore, e.g. `_utm_source`.
	 * @param bool        $html   Whether the mail body is HTML.
	 *
	 * @return string|null The resolved value, or the incoming $output when the tag is unrecognised.
	 */
	public static function resolve_mail_tag( $output, $name, $html ) {
		if ( null !== $output ) {
			return $output;
		}

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
			? mb_substr( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ), 0, 255 )
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
	 * to classify). The submitted values of the form's own tags are passed
	 * as `fields`, keyed by tag name (CF7 tags carry no labels); hidden
	 * fields, uploads, the submit button and quiz/response tags are left
	 * out.
	 *
	 * @since 1.0.0
	 * @param WPCF7_ContactForm $contact_form The submitted form.
	 * @return void
	 */
	public static function record_lead( $contact_form ): void {
		if ( ! $contact_form instanceof WPCF7_ContactForm || ! Attribution::has_data() ) {
			return;
		}

		$submitted  = array();
		$submission = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;

		if ( $submission ) {
			$posted = (array) $submission->get_posted_data();

			foreach ( (array) $contact_form->scan_form_tags() as $tag ) {
				$name = (string) ( $tag->name ?? '' );

				if ( '' === $name || in_array( (string) ( $tag->basetype ?? '' ), self::SKIPPED_BASETYPES, true ) ) {
					continue;
				}

				$submitted[ $name ] = $posted[ $name ] ?? '';
			}
		}

		Recorder::record(
			'contact-form-7',
			array(
				'form_id'    => $contact_form->id(),
				'form_title' => $contact_form->title(),
				'posted'     => Attribution::get_last(),
				'created_at' => time(),
				'fields'     => $submitted,
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
