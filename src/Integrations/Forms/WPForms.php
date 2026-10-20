<?php
/**
 * WPForms integration — zero-config attribution capture.
 *
 * Appends one empty hidden input per recognised attribution field name to
 * every WPForms form (Lite and Pro). The front-end beacon fills them from
 * the attribution cookie on page load, so the values arrive in the POST
 * with each submission and never touch the page cache. WPForms ignores
 * inputs outside its own `wpforms[…]` namespace, so the injected inputs
 * cannot break validation or appear in `{all_fields}`.
 *
 * One smart tag per field name (`{utm_source}`, `{landing_page_first}`, …)
 * resolves at submit time for notification templates, from the submitted
 * POST first and the cookie second. A field whose default value is one of
 * those tags is marked with `data-utmatra-field` so the beacon fills it
 * client-side; at render the tag resolves to an empty string on purpose,
 * because a server-rendered value would be baked into cached HTML.
 *
 * Hooks register only when WPForms is active (`WPFORMS_VERSION` defined,
 * checked at `plugins_loaded` priority 20). Without WPForms the class is
 * loaded but completely inert.
 *
 * @package Utmatra\Integrations\Forms
 */

namespace Utmatra\Integrations\Forms;

use Utmatra\Insights\Recorder;
use Utmatra\Tracking\Attribution;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * WPForms
 *
 * @since 1.0.0
 */
class WPForms {

	/**
	 * WPForms field types that are not submitted values of the form's own
	 * fields: hidden inputs, uploads, passwords and non-input elements.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const SKIPPED_TYPES = array( 'hidden', 'file-upload', 'password', 'pagebreak', 'divider', 'html', 'content', 'captcha', 'internal-information', 'layout', 'repeater', 'entry-preview' );

	/**
	 * Register WPForms hooks — only when WPForms is active.
	 *
	 * Hooked on `plugins_loaded` priority 20 (WPForms may load after this
	 * plugin). Bails without adding a single hook when WPForms is absent.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_init(): void {
		if ( ! defined( 'WPFORMS_VERSION' ) || ! function_exists( 'wpforms' ) ) {
			return;
		}

		add_action( 'wpforms_display_submit_before', array( self::class, 'print_hidden_inputs' ) );
		add_filter( 'wpforms_smart_tags', array( self::class, 'register_smart_tags' ) );
		add_filter( 'wpforms_smarttags_process_value', array( self::class, 'resolve_smart_tag' ), 10, 7 );
		add_filter( 'wpforms_field_properties', array( self::class, 'mark_tag_fields' ), 20, 3 );
		add_action( 'wpforms_process_complete', array( self::class, 'record_lead' ), 10, 4 );
	}

	/**
	 * Print one empty hidden input per attribution field name inside the form.
	 *
	 * Fires on `wpforms_display_submit_before`, inside the `<form>` just
	 * before the submit button. Every name from Attribution::field_names()
	 * is emitted, first-touch variants included, so the beacon fills both
	 * touches and new names reach every form without a change here. The
	 * value is always empty: the beacon writes the current visitor's data.
	 *
	 * @since 1.0.0
	 * @param array $form_data Form data and settings (unused).
	 * @return void
	 */
	public static function print_hidden_inputs( $form_data ): void {
		foreach ( Attribution::field_names() as $name ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="">' . "\n";
		}
	}

	/**
	 * Register one smart tag per attribution field name.
	 *
	 * The tags are bare identifiers (`{utm_source}`, `{gclid_first}`, …);
	 * none collides with a WPForms built-in tag.
	 *
	 * @since 1.0.0
	 * @param array $tags Registered smart tags, tag name => label.
	 * @return array
	 */
	public static function register_smart_tags( $tags ) {
		if ( ! is_array( $tags ) ) {
			$tags = array();
		}

		foreach ( Attribution::field_names() as $name ) {
			$tags[ $name ] = sprintf(
				/* translators: %s: attribution field name, e.g. utm_source. */
				__( 'Utmatra: %s', 'utmatra-utm-attribution-and-tracking' ),
				$name
			);
		}

		return $tags;
	}

	/**
	 * Resolve an attribution smart tag.
	 *
	 * Any tag that is not an attribution field name passes through
	 * untouched. In the `field-properties` context (form render) the value
	 * is an empty string so nothing visitor-specific is baked into cached
	 * HTML; the beacon fills the field instead. Elsewhere (notifications,
	 * confirmations) the submitted POST value wins, falling back to the
	 * cookie for a tag used without a matching field.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Truncates by character, not byte.
	 *
	 * @param mixed  $value     Value resolved so far.
	 * @param string $tag_name  Smart tag name.
	 * @param array  $form_data Form data (unused).
	 * @param array  $fields    Submitted fields (unused).
	 * @param mixed  $entry_id  Entry ID (unused).
	 * @param object $smart_tag Smart tag object (unused).
	 * @param string $context   Processing context.
	 *
	 * @return mixed
	 */
	public static function resolve_smart_tag( $value, $tag_name, $form_data, $fields, $entry_id, $smart_tag, $context ) {
		$tag_name = (string) $tag_name;

		if ( ! in_array( $tag_name, Attribution::field_names(), true ) ) {
			return $value;
		}

		if ( 'field-properties' === $context ) {
			return '';
		}

		// WPForms verifies its own submission; these are attribution values
		// read while composing notifications — no nonce applies. Non-string
		// values are ignored and the value is capped like the cookie writers.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST[ $tag_name ] ) && is_string( $_POST[ $tag_name ] ) && '' !== $_POST[ $tag_name ] ) {
			return mb_substr( sanitize_text_field( wp_unslash( $_POST[ $tag_name ] ) ), 0, 255 );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return Attribution::field_map()[ $tag_name ] ?? '';
	}

	/**
	 * Mark a field whose default value is an attribution smart tag.
	 *
	 * Runs on `wpforms_field_properties` at priority 20, after WPForms'
	 * own prefill callback, and only adds the `data-utmatra-field` attribute
	 * the beacon looks for. The value is left alone: the initial render is
	 * already empty, and after a failed validation WPForms must keep
	 * restoring the submitted value itself.
	 *
	 * @since 1.0.0
	 *
	 * @param array $properties Field properties.
	 * @param array $field      Field settings.
	 * @param array $form_data  Form data (unused).
	 *
	 * @return array
	 */
	public static function mark_tag_fields( $properties, $field, $form_data ) {
		if ( ! is_array( $properties ) || ! is_array( $field ) ) {
			return $properties;
		}

		$name = self::tag_name_from_default( (string) ( $field['default_value'] ?? '' ) );

		if ( '' === $name || ! isset( $properties['inputs']['primary'] ) || ! is_array( $properties['inputs']['primary'] ) ) {
			return $properties;
		}

		$properties['inputs']['primary']['data']['utmatra-field'] = $name;

		return $properties;
	}

	/**
	 * Record a lead for the recent-leads dashboard widget on successful submission.
	 *
	 * Fires on `wpforms_process_complete`, after the submission passed
	 * validation and was processed. Skips silently when the visitor
	 * carries no attribution data. The submitted values of the form's own
	 * fields are passed as `fields`, keyed by label; hidden inputs,
	 * uploads, passwords, non-input elements and any field whose default is
	 * an attribution tag are left out so attribution never travels twice.
	 *
	 * @since 1.0.0
	 *
	 * @param array $fields    Submitted fields, id => [ name, value, id, type ].
	 * @param array $entry     Raw entry (unused).
	 * @param array $form_data Form data and settings.
	 * @param mixed $entry_id  Entry ID (unused).
	 *
	 * @return void
	 */
	public static function record_lead( $fields, $entry, $form_data, $entry_id ): void {
		if ( ! Attribution::has_data() ) {
			return;
		}

		$form_data = is_array( $form_data ) ? $form_data : array();
		$submitted = array();

		foreach ( (array) $fields as $id => $field ) {
			if ( ! is_array( $field ) || in_array( (string) ( $field['type'] ?? '' ), self::SKIPPED_TYPES, true ) ) {
				continue;
			}

			if ( '' !== self::tag_name_from_default( (string) ( $form_data['fields'][ $id ]['default_value'] ?? '' ) ) ) {
				continue;
			}

			$label = (string) ( $field['name'] ?? '' );

			$submitted[ '' !== $label ? $label : (string) $id ] = $field['value'] ?? '';
		}

		Recorder::record(
			'wpforms',
			array(
				'form_id'    => $form_data['id'] ?? '',
				'form_title' => (string) ( $form_data['settings']['form_title'] ?? '' ),
				'posted'     => Attribution::get_last(),
				'created_at' => time(),
				'fields'     => $submitted,
			)
		);
	}

	/**
	 * Report per-form attribution field coverage for Tracking Doctor diagnostics.
	 *
	 * Every published WPForms form is `complete`: print_hidden_inputs()
	 * injects every recognised field into every form, so there is nothing a
	 * form author could misconfigure. Returns an empty array when WPForms
	 * is inactive. The form handler returns `false`, not an empty list, when
	 * no form exists, hence the array cast.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{form_id: int|string, form_title: string, status: string, present: string[], missing: string[]}>
	 */
	public static function get_form_status(): array {
		if ( ! defined( 'WPFORMS_VERSION' ) || ! function_exists( 'wpforms' ) ) {
			return array();
		}

		$handler = wpforms()->obj( 'form' );

		if ( ! is_object( $handler ) || ! method_exists( $handler, 'get' ) ) {
			return array();
		}

		$present = Attribution::field_names();
		$rows    = array();

		foreach ( (array) $handler->get(
			'',
			array(
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		) as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$rows[] = array(
				'form_id'    => $post->ID,
				'form_title' => $post->post_title,
				'status'     => 'complete',
				'present'    => $present,
				'missing'    => array(),
			);
		}

		return $rows;
	}

	/**
	 * The attribution field name a default value refers to, if it is exactly
	 * one of our smart tags.
	 *
	 * @since 1.0.0
	 * @param string $default_value The field's default value setting.
	 * @return string The field name, or '' when the default is not an attribution tag.
	 */
	private static function tag_name_from_default( string $default_value ): string {
		if ( preg_match( '/^\{([a-z0-9_]+)\}$/', trim( $default_value ), $matches ) && in_array( $matches[1], Attribution::field_names(), true ) ) {
			return $matches[1];
		}

		return '';
	}
}
