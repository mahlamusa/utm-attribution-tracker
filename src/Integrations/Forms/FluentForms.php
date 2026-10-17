<?php
/**
 * Fluent Forms integration — lead recording and coverage reporting.
 *
 * Fluent Forms renders a Hidden Field's name attribute verbatim, so a
 * field named `utm_source` (or `utm_source_first`) is filled by the
 * front-end beacon on page load with no help from PHP. This adapter only
 * records the submission as a lead and reports per-form coverage for the
 * Tracking Doctor.
 *
 * Hooks register only when Fluent Forms is active (`FLUENTFORM` defined,
 * checked at `plugins_loaded` priority 20). Without Fluent Forms the class
 * is loaded but completely inert.
 *
 * @package Utmatra\Integrations\Forms
 */

namespace Utmatra\Integrations\Forms;

use FluentForm\App\Modules\Form\FormFieldsParser;
use Utmatra\Insights\Recorder;
use Utmatra\Tracking\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * FluentForms
 *
 * @since 1.0.0
 * @since 1.0.0 Skips submissions flagged as spam.
 */
class FluentForms {

	/**
	 * Fluent Forms elements that are not submitted values of the form's own
	 * fields: hidden inputs, uploads, passwords and non-input elements.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const SKIPPED_ELEMENTS = array( 'input_hidden', 'input_file', 'input_image', 'input_password', 'custom_html', 'section_break', 'form_step', 'recaptcha', 'hcaptcha', 'turnstile', 'payment_method', 'custom_payment_component', 'signature' );

	/**
	 * Register the Fluent Forms hook — only when Fluent Forms is active.
	 *
	 * Hooked on `plugins_loaded` priority 20 (Fluent Forms may load after
	 * this plugin). Bails without adding a single hook when it is absent.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_init(): void {
		if ( ! defined( 'FLUENTFORM' ) ) {
			return;
		}

		add_action( 'fluentform/submission_inserted', array( self::class, 'record_lead' ), 10, 3 );
	}

	/**
	 * Whether a stored submission carries the `spam` status.
	 *
	 * @since 1.0.0
	 * @param int $insert_id Submission ID.
	 * @return bool
	 */
	private static function is_spam( $insert_id ): bool {
		global $wpdb;

		// One indexed lookup per submission; Fluent Forms exposes no API for
		// the status at this point.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}fluentform_submissions WHERE id = %d", absint( $insert_id ) ) );

		return 'spam' === $status;
	}

	/**
	 * Record a lead for the recent-leads dashboard widget on successful submission.
	 *
	 * Fires on `fluentform/submission_inserted`, once the submission has
	 * been stored. Skips silently when the visitor carries no attribution
	 * data. The submitted values of the form's own fields are passed as
	 * `fields`, keyed by admin label (falling back to the field label, then
	 * the field name); hidden inputs, uploads, passwords and non-input
	 * elements are left out. A submission an anti-spam check flagged is
	 * skipped: unless the admin chose to skip processing, Fluent Forms stores
	 * it with the `spam` status and still fires the hook.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Skips submissions flagged as spam.
	 *
	 * @param int    $insert_id Submission ID, read for its spam status.
	 * @param array  $form_data Submitted values keyed by field name.
	 * @param object $form      The form (`id`, `title`, `form_fields`).
	 *
	 * @return void
	 */
	public static function record_lead( $insert_id, $form_data, $form ): void {
		if ( ! Attribution::has_data() || ! is_object( $form ) || self::is_spam( $insert_id ) ) {
			return;
		}

		$form_data = is_array( $form_data ) ? $form_data : array();
		$submitted = array();

		foreach ( self::inputs( $form ) as $name => $input ) {
			if ( in_array( (string) ( $input['element'] ?? '' ), self::SKIPPED_ELEMENTS, true ) ) {
				continue;
			}

			$label = (string) ( $input['raw']['settings']['admin_field_label'] ?? '' );
			if ( '' === $label ) {
				$label = (string) ( $input['raw']['settings']['label'] ?? '' );
			}
			if ( '' === $label ) {
				$label = (string) $name;
			}

			$submitted[ $label ] = $form_data[ $name ] ?? '';
		}

		Recorder::record(
			'fluent-forms',
			array(
				'form_id'    => $form->id ?? '',
				'form_title' => (string) ( $form->title ?? '' ),
				'posted'     => Attribution::get_last(),
				'created_at' => time(),
				'fields'     => $submitted,
			)
		);
	}

	/**
	 * Report per-form attribution field coverage for Tracking Doctor diagnostics.
	 *
	 * A field counts as present when it is a Hidden Field whose name
	 * attribute is one of the recognised attribution field names — the
	 * same name the beacon fills by. Returns an empty array when Fluent
	 * Forms is inactive.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{form_id: int|string, form_title: string, status: string, present: string[], missing: string[]}>
	 */
	public static function get_form_status(): array {
		if ( ! defined( 'FLUENTFORM' ) || ! function_exists( 'wpFluent' ) ) {
			return array();
		}

		$names = Attribution::field_names();
		$rows  = array();
		$forms = wpFluent()->table( 'fluentform_forms' )
			->select( array( 'id', 'title', 'form_fields' ) )
			->where( 'status', 'published' )
			->orderBy( 'id', 'ASC' )
			->get();

		// The query builder returns a Traversable Collection; casting it to
		// an array would expose its protected internals instead of the rows.
		foreach ( $forms as $form ) {
			if ( ! is_object( $form ) ) {
				continue;
			}

			$present = array();

			foreach ( self::inputs( $form ) as $name => $input ) {
				if ( 'input_hidden' === ( $input['element'] ?? '' ) && in_array( (string) $name, $names, true ) ) {
					$present[] = (string) $name;
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
				'form_id'    => $form->id,
				'form_title' => (string) $form->title,
				'status'     => $status,
				'present'    => $present,
				'missing'    => $missing,
			);
		}

		return $rows;
	}

	/**
	 * The form's input fields keyed by name, with element and raw settings.
	 *
	 * The label chain reads the raw settings directly, so the parser's
	 * resolved `admin_label` key is not requested; both callers share the
	 * same `with` set so its per-form cache is never reset between them.
	 *
	 * @since 1.0.0
	 * @param object $form The form object.
	 * @return array<string, array<string, mixed>>
	 */
	private static function inputs( $form ): array {
		if ( ! class_exists( FormFieldsParser::class ) ) {
			return array();
		}

		return (array) FormFieldsParser::getEntryInputs( $form, array( 'element', 'raw' ) );
	}
}
