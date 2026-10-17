<?php
/**
 * Gravity Forms integration — dynamic field population.
 *
 * Registers a `gform_field_value_{name}` filter for every recognised
 * attribution field name (`utm_source` … `twclid`, `landing_page`, plus
 * `_first` variants) so users can add a Hidden field with "Allow field to
 * be populated dynamically" and the matching parameter name to capture
 * attribution — values come from the Attribution cookie reader server-side.
 * On submit those fields are set again from the submitter's own cookie, so
 * a page cache cannot pass one visitor's prefilled values to everyone.
 *
 * Hooks register only when Gravity Forms is active (`GFForms` class exists,
 * checked at `plugins_loaded` priority 20). Without Gravity Forms the class
 * is loaded but completely inert.
 *
 * @package Utmatra\Integrations\Forms
 */

namespace Utmatra\Integrations\Forms;

use GFAPI;
use Utmatra\Insights\Recorder;
use Utmatra\Tracking\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * GravityForms
 *
 * @since 1.0.0
 * @since 1.0.0 Replaces cached attribution prefills with the submitter's own values.
 */
class GravityForms {

	/**
	 * Gravity Forms field types that are not submitted values of the form's
	 * own fields: hidden inputs, uploads, passwords and non-input elements.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const SKIPPED_TYPES = array( 'hidden', 'fileupload', 'password', 'html', 'section', 'page', 'captcha', 'honeypot', 'creditcard', 'signature', 'repeater' );

	/**
	 * Register the dynamic-population filters — only when GF is active.
	 *
	 * Hooked on `plugins_loaded` priority 20 (Gravity Forms may load after
	 * this plugin). Bails without adding a single hook when GF is absent.
	 * One filter is registered per recognised field name via a closure
	 * factory; each closure resolves its value lazily at render time.
	 *
	 * Gravity Forms esc_attr()s dynamically populated values when rendering
	 * the field input, so the input-sanitized values are returned raw —
	 * escaping them here too would double-encode.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Registers the submit-time override of the attribution fields.
	 * @return void
	 */
	public static function maybe_init(): void {
		if ( ! class_exists( 'GFForms' ) ) {
			return;
		}

		foreach ( Attribution::field_names() as $name ) {
			add_filter(
				'gform_field_value_' . $name,
				static function ( $value ) use ( $name ) {
					// A value already populated (query string, shortcode
					// attribute, hook) always wins over the cookie.
					if ( is_string( $value ) && '' !== $value ) {
						return $value;
					}

					$map = Attribution::field_map();

					return $map[ $name ] ?? $value;
				}
			);
		}

		add_action( 'gform_pre_submission', array( self::class, 'override_prefills' ) );
		add_action( 'gform_after_submission', array( self::class, 'record_lead' ), 10, 2 );
	}

	/**
	 * Replace the submitted attribution fields with the submitter's own values.
	 *
	 * The population filters above run when the form is rendered, so a page
	 * cache serves the first visitor's attribution to everyone. Fires on
	 * `gform_pre_submission`, before the entry is saved and notifications
	 * are sent: every single-input field populated dynamically under a
	 * recognised attribution name gets the submitter's value from the
	 * attribution cookie, or, when the cookie carries none, the field's own
	 * default value. Built from the Gravity Forms developer documentation;
	 * untested locally because Gravity Forms is a paid plugin.
	 *
	 * @since 1.0.0
	 * @param array $form The form being submitted.
	 * @return void
	 */
	public static function override_prefills( $form ): void {
		if ( ! is_array( $form ) ) {
			return;
		}

		$map   = Attribution::field_map();
		$names = Attribution::field_names();

		foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
			if ( ! is_object( $field ) || empty( $field->allowsPrepopulate ) || ! empty( $field->inputs ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Gravity Forms field property.
				continue;
			}

			$name = (string) ( $field->inputName ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Gravity Forms field property.
			if ( ! in_array( $name, $names, true ) ) {
				continue;
			}

			$default = method_exists( $field, 'get_value_default' ) ? $field->get_value_default() : ( $field->defaultValue ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Gravity Forms field property.

			// The submitted value is replaced, not read, so no nonce applies;
			// Gravity Forms verifies its own submission. Slashed like the rest
			// of $_POST, which Gravity Forms unslashes when it reads it.
			$_POST[ 'input_' . $field->id ] = wp_slash( $map[ $name ] ?? (string) $default ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}

	/**
	 * Record a lead for the recent-leads dashboard widget on successful submission.
	 *
	 * Fires on `gform_after_submission`, which only runs once the entry has
	 * been successfully saved. Skips silently when the visitor carries no
	 * attribution data (nothing to classify). The submitted values of the
	 * form's own fields are passed as `fields`, keyed by label (falling
	 * back to the field ID), read through each field's export value so
	 * multi-input fields such as name and address come back joined; hidden
	 * fields, uploads, passwords and non-input elements are left out. Built
	 * from the Gravity Forms developer documentation; untested locally
	 * because Gravity Forms is a paid plugin.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Skips entries flagged as spam.
	 * @param array $entry The submitted entry, read for the field values.
	 * @param array $form  The form the entry belongs to.
	 * @return void
	 */
	public static function record_lead( $entry, $form ): void {
		if ( ! Attribution::has_data() ) {
			return;
		}

		$entry = is_array( $entry ) ? $entry : array();

		// The hook also fires for entries the spam filter flagged.
		if ( 'spam' === ( $entry['status'] ?? '' ) ) {
			return;
		}

		$submitted = array();

		foreach ( (array) ( is_array( $form ) ? ( $form['fields'] ?? array() ) : array() ) as $field ) {
			if ( ! is_object( $field ) || in_array( (string) ( $field->type ?? '' ), self::SKIPPED_TYPES, true ) ) {
				continue;
			}

			$id    = (string) ( $field->id ?? '' );
			$label = (string) ( $field->label ?? '' );
			$value = method_exists( $field, 'get_value_export' ) ? $field->get_value_export( $entry ) : null;

			if ( null === $value || '' === $value ) {
				$value = $entry[ $id ] ?? '';
			}

			$submitted[ '' !== $label ? $label : $id ] = $value;
		}

		Recorder::record(
			'gravity-forms',
			array(
				'form_id'    => is_array( $form ) ? ( $form['id'] ?? '' ) : '',
				'form_title' => is_array( $form ) ? ( $form['title'] ?? '' ) : '',
				'posted'     => Attribution::get_last(),
				'created_at' => time(),
				'fields'     => $submitted,
			)
		);
	}

	/**
	 * Report per-form attribution field coverage for Tracking Doctor diagnostics.
	 *
	 * For each GF form, inspects every field for `allowsPrepopulate` fields
	 * whose `inputName` matches a recognised attribution field name — the
	 * same dynamic-population mechanism maybe_init() relies on to fill
	 * values. Returns an empty array when Gravity Forms is inactive.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{form_id: int|string, form_title: string, status: string, present: string[], missing: string[]}>
	 */
	public static function get_form_status(): array {
		if ( ! class_exists( 'GFAPI' ) ) {
			return array();
		}

		$names = Attribution::field_names();
		$rows  = array();

		foreach ( GFAPI::get_forms() as $form ) {
			$present = array();
			foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
				// GF_Field's own camelCase property names; not ours to rename.
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( ! empty( $field->allowsPrepopulate ) && in_array( $field->inputName, $names, true ) ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$present[] = $field->inputName;
				}
			}
			$present = array_values( array_unique( $present ) );
			$missing = array_values( array_diff( $names, $present ) );

			if ( empty( $present ) ) {
				$status = 'none';
			} elseif ( empty( $missing ) ) {
				$status = 'complete';
			} else {
				$status = 'partial';
			}

			$rows[] = array(
				'form_id'    => $form['id'],
				'form_title' => $form['title'],
				'status'     => $status,
				'present'    => $present,
				'missing'    => $missing,
			);
		}

		return $rows;
	}
}
