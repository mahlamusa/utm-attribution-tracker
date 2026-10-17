<?php
/**
 * Formidable Forms integration — hidden-field marking and lead recording.
 *
 * Formidable names every input `item_meta[ID]`, so the front-end beacon
 * cannot find an attribution field by name. A Hidden field whose Field Key
 * starts with a recognised attribution field name (`utm_source`,
 * `utm_source2` on a second form, `landing_page_first`, …) is marked with
 * `data-utmatra-field="<name>"` at render and the beacon fills it from the
 * attribution cookie on page load. No value is written by PHP, so cached
 * HTML never carries another visitor's data.
 *
 * Hooks register only when Formidable is active (`FrmAppHelper` class
 * exists, checked at `plugins_loaded` priority 20). Without Formidable the
 * class is loaded but completely inert.
 *
 * @package Utmatra\Integrations\Forms
 */

namespace Utmatra\Integrations\Forms;

use FrmEntry;
use FrmField;
use FrmForm;
use Utmatra\Insights\Recorder;
use Utmatra\Tracking\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * Formidable
 *
 * @since 1.0.0
 */
class Formidable {

	/**
	 * Formidable field types that are not submitted values of the form's own
	 * fields: hidden inputs, uploads, passwords and non-input elements.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const SKIPPED_TYPES = array( 'hidden', 'file', 'password', 'captcha', 'html', 'divider', 'end_divider', 'break', 'submit', 'form', 'summary', 'credit_card', 'signature', 'user_id' );

	/**
	 * Formidable's `is_draft` value for a submitted entry
	 * (FrmEntriesHelper::SUBMITTED_ENTRY_STATUS).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const SUBMITTED_STATUS = 0;

	/**
	 * Formidable's `is_draft` value for a saved draft
	 * (FrmEntriesHelper::DRAFT_ENTRY_STATUS).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const DRAFT_STATUS = 1;

	/**
	 * Transient prefix marking a draft whose lead is owed once it is submitted.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const DRAFT_TRANSIENT = 'utmatra_frm_draft_';

	/**
	 * Register Formidable hooks — only when Formidable is active.
	 *
	 * Hooked on `plugins_loaded` priority 20 (Formidable may load after this
	 * plugin). Bails without adding a single hook when it is absent.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Hooks entry updates to record submitted drafts.
	 * @return void
	 */
	public static function maybe_init(): void {
		if ( ! class_exists( 'FrmAppHelper' ) ) {
			return;
		}

		add_action( 'frm_field_input_html', array( self::class, 'mark_hidden_input' ) );
		add_action( 'frm_after_create_entry', array( self::class, 'record_lead' ), 10, 3 );
		add_action( 'frm_after_update_entry', array( self::class, 'record_submitted_draft' ), 10, 2 );
	}

	/**
	 * Print the beacon marker inside a matching hidden field's input tag.
	 *
	 * Fires on `frm_field_input_html`, which Formidable output-buffers and
	 * splices into the `<input>` tag. Prints nothing unless the field is a
	 * Hidden field whose key resolves to an attribution field name.
	 *
	 * @since 1.0.0
	 * @param array|object $field The field being rendered (`type`, `field_key`, …).
	 * @return void
	 */
	public static function mark_hidden_input( $field ): void {
		$field = is_object( $field ) ? (array) $field : $field;

		if ( ! is_array( $field ) || 'hidden' !== ( $field['type'] ?? '' ) ) {
			return;
		}

		$name = self::field_name_for_key( (string) ( $field['field_key'] ?? '' ) );

		if ( '' !== $name ) {
			echo ' data-utmatra-field="' . esc_attr( $name ) . '"';
		}
	}

	/**
	 * Record a lead for the recent-leads dashboard widget on successful submission.
	 *
	 * Fires on `frm_after_create_entry`, once the entry has been saved.
	 * Repeater rows (child entries) are skipped — the parent entry is the
	 * lead. A saved draft (Formidable Pro) is not a lead yet: it is marked
	 * so record_submitted_draft() records it once it is submitted. Any other
	 * non-submitted status (spam) is skipped. Skips silently when the
	 * visitor carries no attribution data, so a draft saved without
	 * attribution is never marked.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Defers draft entries until they are submitted.
	 *
	 * @param int   $entry_id The new entry ID.
	 * @param int   $form_id  The form ID.
	 * @param array $args     Extra arguments (`is_child`).
	 *
	 * @return void
	 */
	public static function record_lead( $entry_id, $form_id, $args ): void {
		if ( ! Attribution::has_data() || ! empty( $args['is_child'] ) ) {
			return;
		}

		if ( ! class_exists( 'FrmForm' ) || ! class_exists( 'FrmField' ) || ! class_exists( 'FrmEntry' ) ) {
			return;
		}

		$entry  = FrmEntry::getOne( $entry_id, true );
		$status = is_object( $entry ) ? (int) ( $entry->is_draft ?? self::SUBMITTED_STATUS ) : self::SUBMITTED_STATUS;

		if ( self::DRAFT_STATUS === $status ) {
			// Pro drafts can live longer; a draft submitted after this
			// expires simply records no lead.
			set_transient( self::DRAFT_TRANSIENT . absint( $entry_id ), 1, 30 * DAY_IN_SECONDS );
			return;
		}
		if ( self::SUBMITTED_STATUS !== $status ) {
			return;
		}

		self::record( $entry, $form_id );
	}

	/**
	 * Record the lead for a draft once it is submitted.
	 *
	 * Fires on `frm_after_update_entry`. Formidable Pro submits a saved
	 * draft by updating the entry, so `frm_after_create_entry` never fires
	 * for it. Acts only on entries record_lead() marked as drafts, so an
	 * admin editing an entry never records a lead. The mark is cleared
	 * before recording, so a repeated update cannot record twice.
	 *
	 * @since 1.0.0
	 *
	 * @param int $entry_id The updated entry ID.
	 * @param int $form_id  The form ID.
	 *
	 * @return void
	 */
	public static function record_submitted_draft( $entry_id, $form_id ): void {
		$key = self::DRAFT_TRANSIENT . absint( $entry_id );
		if ( ! get_transient( $key ) || ! class_exists( 'FrmForm' ) || ! class_exists( 'FrmField' ) || ! class_exists( 'FrmEntry' ) ) {
			return;
		}

		$entry  = FrmEntry::getOne( $entry_id, true );
		$status = is_object( $entry ) ? (int) ( $entry->is_draft ?? self::SUBMITTED_STATUS ) : self::SUBMITTED_STATUS;
		if ( self::DRAFT_STATUS === $status ) {
			return;
		}

		delete_transient( $key );
		if ( self::SUBMITTED_STATUS !== $status || ! Attribution::has_data() ) {
			return;
		}

		self::record( $entry, $form_id );
	}

	/**
	 * Pass a submitted entry to the recent-leads recorder.
	 *
	 * @since 1.0.0
	 *
	 * @param object|null $entry   The entry with its metas, or null when it could not be loaded.
	 * @param int         $form_id The form ID.
	 *
	 * @return void
	 */
	private static function record( $entry, $form_id ): void {
		$form      = FrmForm::getOne( $form_id );
		$metas     = is_object( $entry ) && isset( $entry->metas ) && is_array( $entry->metas ) ? $entry->metas : array();
		$submitted = array();

		foreach ( (array) FrmField::get_all_for_form( $form_id ) as $field ) {
			if ( ! is_object( $field ) || in_array( (string) ( $field->type ?? '' ), self::SKIPPED_TYPES, true ) ) {
				continue;
			}

			$label = (string) ( $field->name ?? '' );

			$submitted[ '' !== $label ? $label : (string) $field->id ] = $metas[ $field->id ] ?? '';
		}

		Recorder::record(
			'formidable',
			array(
				'form_id'    => $form_id,
				'form_title' => is_object( $form ) ? (string) ( $form->name ?? '' ) : '',
				'posted'     => Attribution::get_last(),
				'created_at' => time(),
				'fields'     => $submitted,
			)
		);
	}

	/**
	 * Report per-form attribution field coverage for Tracking Doctor diagnostics.
	 *
	 * A field counts as present when it is a Hidden field whose key
	 * resolves through field_name_for_key() — the same rule the marker
	 * applies. Templates, child (repeater) forms and trashed forms are
	 * skipped. Returns an empty array when Formidable is inactive.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{form_id: int|string, form_title: string, status: string, present: string[], missing: string[]}>
	 */
	public static function get_form_status(): array {
		if ( ! class_exists( 'FrmForm' ) || ! class_exists( 'FrmField' ) ) {
			return array();
		}

		$names = Attribution::field_names();
		$rows  = array();

		foreach ( (array) FrmForm::getAll(
			array(
				'is_template'    => 0,
				'parent_form_id' => 0,
			)
		) as $form ) {
			if ( ! is_object( $form ) || 'trash' === ( $form->status ?? '' ) ) {
				continue;
			}

			$present = array();

			foreach ( (array) FrmField::get_all_for_form( $form->id ) as $field ) {
				if ( ! is_object( $field ) || 'hidden' !== ( $field->type ?? '' ) ) {
					continue;
				}

				$name = self::field_name_for_key( (string) ( $field->field_key ?? '' ) );

				if ( '' !== $name ) {
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
				'form_id'    => $form->id,
				'form_title' => (string) ( $form->name ?? '' ),
				'status'     => $status,
				'present'    => $present,
				'missing'    => $missing,
			);
		}

		return $rows;
	}

	/**
	 * The attribution field name a Formidable field key stands for.
	 *
	 * Formidable keeps field keys unique per site by appending digits to a
	 * taken key (`utm_source2` on a second form), so the longest recognised
	 * name that is a prefix of the key wins: `utm_source_first2` resolves
	 * to `utm_source_first`, not `utm_source`. Only a digit suffix counts,
	 * so an unrelated key such as `utm_source_extra` matches nothing.
	 *
	 * @since 1.0.0
	 * @param string $key The field key.
	 * @return string The field name, or '' when no name is a prefix of the key.
	 */
	private static function field_name_for_key( string $key ): string {
		if ( '' === $key ) {
			return '';
		}

		$names = Attribution::field_names();
		usort(
			$names,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		foreach ( $names as $name ) {
			if ( 0 !== strpos( $key, $name ) ) {
				continue;
			}

			$rest = substr( $key, strlen( $name ) );
			if ( '' === $rest || ctype_digit( $rest ) ) {
				return $name;
			}
		}

		return '';
	}
}
