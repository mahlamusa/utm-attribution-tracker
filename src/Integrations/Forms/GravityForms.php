<?php
/**
 * Gravity Forms integration — dynamic field population.
 *
 * Registers a `gform_field_value_{name}` filter for every recognised
 * attribution field name (`utmat_source` … `utmat_landing`, plus
 * `_first` variants) so users can add a Hidden field with "Allow field to
 * be populated dynamically" and the matching parameter name to capture
 * attribution — values come from the Attribution cookie reader server-side.
 *
 * Hooks register only when Gravity Forms is active (`GFForms` class exists,
 * checked at `plugins_loaded` priority 20). Without Gravity Forms the class
 * is loaded but completely inert.
 *
 * @package UTMAT\Integrations\Forms
 */

namespace UTMAT\Integrations\Forms;

use GFAPI;
use UTMAT\Insights\Recorder;
use UTMAT\Tracking\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * GravityForms
 *
 * @since 1.0.0
 */
class GravityForms {

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
	 * @since 1.0.0 Added the recent-leads recorder on `gform_after_submission`.
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

		add_action( 'gform_after_submission', array( self::class, 'record_lead' ), 10, 2 );
	}

	/**
	 * Record a lead for the recent-leads dashboard widget on successful submission.
	 *
	 * Fires on `gform_after_submission`, which only runs once the entry has
	 * been successfully saved. Skips silently when the visitor carries no
	 * attribution data (nothing to classify).
	 *
	 * @since 1.0.0
	 * @param array $entry The submitted entry (unused — attribution comes from the request-scoped cookie, not the entry's field values).
	 * @param array $form  The form the entry belongs to.
	 * @return void
	 */
	public static function record_lead( $entry, $form ): void {
		if ( ! Attribution::has_data() ) {
			return;
		}

		Recorder::record(
			'gravity-forms',
			array(
				'form_id'    => is_array( $form ) ? ( $form['id'] ?? '' ) : '',
				'form_title' => is_array( $form ) ? ( $form['title'] ?? '' ) : '',
				'posted'     => Attribution::get_last(),
				'created_at' => time(),
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
