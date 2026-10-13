<?php
/**
 * Forminator integration — hidden-field marking and lead recording.
 *
 * Forminator names a Hidden Field `hidden-N`, so the front-end beacon
 * cannot find an attribution field by name. A Hidden Field whose Default
 * Value is "Query parameter" and whose query parameter is a recognised
 * attribution field name (`utm_source`, `utm_source_first`, …) is marked
 * with `data-utmat-field="<name>"` at render and the beacon fills it from
 * the attribution cookie on page load. Forminator's own URL prefill is
 * left alone: a matching parameter on the current page wins, because the
 * beacon never overwrites a non-empty field. No value is written by this
 * plugin's PHP, so cached HTML never carries another visitor's data.
 *
 * Hooks register only when Forminator is active (`FORMINATOR_VERSION`
 * defined, checked at `plugins_loaded` priority 20). Without Forminator
 * the class is loaded but completely inert.
 *
 * @package UTMAT\Integrations\Forms
 */

namespace UTMAT\Integrations\Forms;

use Forminator_API;
use Forminator_Base_Form_Model;
use UTMAT\Insights\Recorder;
use UTMAT\Tracking\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * Forminator
 *
 * @since 1.0.0
 */
class Forminator {

	/**
	 * Forminator field types that are not submitted values of the form's own
	 * fields: hidden inputs, uploads, passwords and non-input elements.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const SKIPPED_TYPES = array( 'hidden', 'upload', 'password', 'captcha', 'html', 'section', 'page-break', 'stripe', 'stripe-ocs', 'paypal', 'postdata', 'signature', 'group' );

	/**
	 * Register Forminator hooks — only when Forminator is active.
	 *
	 * Hooked on `plugins_loaded` priority 20 (Forminator may load after this
	 * plugin). Bails without adding a single hook when it is absent.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_init(): void {
		if ( ! defined( 'FORMINATOR_VERSION' ) ) {
			return;
		}

		add_filter( 'forminator_field_markup', array( self::class, 'mark_hidden_markup' ), 10, 3 );
		add_action( 'forminator_after_handle_form', array( self::class, 'record_lead' ) );
	}

	/**
	 * Add the beacon marker to a matching hidden field's markup.
	 *
	 * Runs on `forminator_field_markup`. Only a Hidden Field set to
	 * "Query parameter" with an attribution field name as its parameter is
	 * touched; the first `<input type="hidden" ` in its markup gains the
	 * `data-utmat-field` attribute. Everything else is returned untouched.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html     The field markup.
	 * @param array  $field    The field settings (`type`, `default_value`, `prefill`, …).
	 * @param object $renderer The form renderer (unused).
	 *
	 * @return string
	 */
	public static function mark_hidden_markup( $html, $field, $renderer ) {
		if ( ! is_string( $html ) || ! is_array( $field ) ) {
			return $html;
		}

		if ( 'hidden' !== ( $field['type'] ?? '' ) || 'query' !== ( $field['default_value'] ?? '' ) ) {
			return $html;
		}

		$name = (string) ( $field['prefill'] ?? '' );

		if ( ! in_array( $name, Attribution::field_names(), true ) ) {
			return $html;
		}

		$needle   = '<input type="hidden" ';
		$position = strpos( $html, $needle );

		if ( false === $position ) {
			return $html;
		}

		return substr_replace( $html, '<input type="hidden" data-utmat-field="' . esc_attr( $name ) . '" ', $position, strlen( $needle ) );
	}

	/**
	 * Record a lead for the recent-leads dashboard widget on successful submission.
	 *
	 * Fires on `forminator_after_handle_form`, which Forminator also runs
	 * for spam, pending and draft entries — only `active` entries are
	 * leads. Skips silently when the visitor carries no attribution data.
	 * The submitted values of the form's own fields are passed as `fields`,
	 * keyed by field label; hidden fields, uploads, passwords and non-input
	 * elements are left out.
	 *
	 * @since 1.0.0
	 * @param object $entry The entry model (`entry_id`, `form_id`, `status`, `get_meta()`).
	 * @return void
	 */
	public static function record_lead( $entry ): void {
		if ( ! Attribution::has_data() ) {
			return;
		}

		if ( ! is_object( $entry ) || 'active' !== ( $entry->status ?? '' ) || ! class_exists( 'Forminator_Base_Form_Model' ) ) {
			return;
		}

		$form_id   = $entry->form_id ?? 0;
		$model     = Forminator_Base_Form_Model::get_model( $form_id );
		$submitted = array();

		if ( is_object( $model ) && method_exists( $model, 'get_real_fields' ) && method_exists( $entry, 'get_meta' ) ) {
			foreach ( (array) $model->get_real_fields() as $field ) {
				if ( ! is_object( $field ) || in_array( (string) $field->type, self::SKIPPED_TYPES, true ) ) {
					continue;
				}

				$label = (string) $field->field_label;

				$submitted[ '' !== $label ? $label : (string) $field->slug ] = $entry->get_meta( $field->slug, '' );
			}
		}

		Recorder::record(
			'forminator',
			array(
				'form_id'    => $form_id,
				'form_title' => self::form_title( $model ),
				'posted'     => Attribution::get_last(),
				'created_at' => time(),
				'fields'     => $submitted,
			)
		);
	}

	/**
	 * Report per-form attribution field coverage for Tracking Doctor diagnostics.
	 *
	 * A field counts as present when it is a Hidden Field set to "Query
	 * parameter" whose parameter is a recognised attribution field name —
	 * the same rule the marker applies. Returns an empty array when
	 * Forminator is inactive.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{form_id: int|string, form_title: string, status: string, present: string[], missing: string[]}>
	 */
	public static function get_form_status(): array {
		if ( ! defined( 'FORMINATOR_VERSION' ) || ! class_exists( 'Forminator_API' ) ) {
			return array();
		}

		$names = Attribution::field_names();
		$rows  = array();

		foreach ( (array) Forminator_API::get_forms( null, 1, 500 ) as $model ) {
			if ( ! is_object( $model ) || ! method_exists( $model, 'get_fields' ) ) {
				continue;
			}

			$present = array();

			foreach ( (array) $model->get_fields() as $field ) {
				if ( ! is_object( $field ) || 'hidden' !== (string) $field->type || 'query' !== (string) $field->default_value ) {
					continue;
				}

				$name = (string) $field->prefill;

				if ( in_array( $name, $names, true ) ) {
					$present[] = $name;
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
				'form_id'    => $model->id,
				'form_title' => self::form_title( $model ),
				'status'     => $status,
				'present'    => $present,
				'missing'    => $missing,
			);
		}

		return $rows;
	}

	/**
	 * The display title of a form model.
	 *
	 * Forminator stores the display name in `settings['formName']`; the
	 * model's `name` is a sanitised slug and only a fallback.
	 *
	 * @since 1.0.0
	 * @param mixed $model The form model, or anything else when the form was not found.
	 * @return string
	 */
	private static function form_title( $model ): string {
		if ( ! is_object( $model ) ) {
			return '';
		}

		$settings = isset( $model->settings ) && is_array( $model->settings ) ? $model->settings : array();
		$title    = (string) ( $settings['formName'] ?? '' );

		return '' !== $title ? $title : (string) ( $model->name ?? '' );
	}
}
