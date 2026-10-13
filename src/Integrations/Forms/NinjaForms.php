<?php
/**
 * Ninja Forms integration — merge-tag attribution auto-fill.
 *
 * Registers one Ninja Forms merge tag per recognised attribution field name
 * (`{utmat:utm_source}` … `{utmat:landing}`, plus `_first` variants) so a
 * user can add a Hidden field whose default value is one of these tags to
 * capture attribution — values are resolved server-side, at form render,
 * from the Attribution cookie reader.
 *
 * Ninja Forms resolves merge tags through a class extending
 * `NF_Abstracts_MergeTags`, registered on `Ninja_Forms()->merge_tags`. That
 * parent class only exists once Ninja Forms core has finished loading, so
 * the subclass lives in its own file (NinjaFormsMergeTags.php) and is
 * required lazily from register_merge_tags() below — never unconditionally
 * at Bootstrap time — to avoid a fatal "class not found" when Ninja Forms
 * is absent.
 *
 * Hooks register only when Ninja Forms is active (`ninja-forms/ninja-forms.php`
 * active and `Ninja_Forms()` callable, checked at `plugins_loaded` priority
 * 20). Without Ninja Forms the class is loaded but completely inert.
 *
 * Deliberately out of scope: HandL's admin "one-click add fields to form"
 * injector, which programmatically creates NF fields and mutates a form's
 * stored field configuration. This adapter only exposes the merge tag —
 * users add the Hidden field themselves.
 *
 * @package UTMAT\Integrations\Forms
 */

namespace UTMAT\Integrations\Forms;

use UTMAT\Insights\Recorder;
use UTMAT\Tracking\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * NinjaForms
 *
 * @since 1.0.0
 */
class NinjaForms {

	/**
	 * Ninja Forms field types that are not submitted values of the form's
	 * own fields: hidden inputs, uploads, passwords and non-input elements.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const SKIPPED_TYPES = array( 'hidden', 'file_upload', 'password', 'passwordconfirm', 'submit', 'html', 'hr', 'note', 'recaptcha', 'recaptcha_v3', 'hcaptcha', 'turnstile', 'spam', 'signature', 'repeater', 'creditcard', 'creditcardcvc', 'creditcardexpiration', 'creditcardfullname', 'creditcardnumber', 'creditcardzip' );

	/**
	 * Register the merge-tag hook — only when Ninja Forms is active.
	 *
	 * Hooked on `plugins_loaded` priority 20 (Ninja Forms may load after this
	 * plugin). Bails without adding a single hook when Ninja Forms is absent.
	 * `is_plugin_active()` is loaded on demand — it lives in an admin-only
	 * file not autoloaded on front-end requests.
	 *
	 * Ninja Forms fires `ninja_forms_loaded` from its own `plugins_loaded`
	 * callback at the default priority, which is before this method runs,
	 * so the merge tags are registered directly when that action has
	 * already fired and only deferred to it otherwise.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_init(): void {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active( 'ninja-forms/ninja-forms.php' ) || ! function_exists( 'Ninja_Forms' ) ) {
			return;
		}

		if ( did_action( 'ninja_forms_loaded' ) ) {
			self::register_merge_tags();
		} else {
			add_action( 'ninja_forms_loaded', array( self::class, 'register_merge_tags' ) );
		}
		add_action( 'ninja_forms_after_submission', array( self::class, 'record_lead' ) );
	}

	/**
	 * Record a lead for the recent-leads dashboard widget on successful submission.
	 *
	 * Fires on `ninja_forms_after_submission`, which Ninja Forms runs once a
	 * submission has been processed and saved. Skips silently when the
	 * visitor carries no attribution data (nothing to classify). The
	 * submitted values of the form's own fields are passed as `fields`,
	 * keyed by label (falling back to the field key); the submission data
	 * carries neither label nor type, so both come from the form's field
	 * models. Hidden fields, uploads, passwords and non-input elements are
	 * left out.
	 *
	 * @since 1.0.0
	 * @param array $form_data Submission data Ninja Forms passes to the action (`form_id`, `fields`, `settings`, etc.).
	 * @return void
	 */
	public static function record_lead( $form_data ): void {
		if ( ! is_array( $form_data ) || ! Attribution::has_data() ) {
			return;
		}

		$form_id   = $form_data['form_id'] ?? '';
		$models    = array();
		$submitted = array();

		if ( '' !== $form_id && function_exists( 'Ninja_Forms' ) ) {
			foreach ( (array) Ninja_Forms()->form( $form_id )->get_fields() as $model ) {
				if ( is_object( $model ) && method_exists( $model, 'get_id' ) ) {
					$models[ (string) $model->get_id() ] = $model;
				}
			}
		}

		foreach ( (array) ( $form_data['fields'] ?? array() ) as $id => $field ) {
			$model = $models[ (string) ( $field['id'] ?? $id ) ] ?? null;

			if ( ! is_array( $field ) || null === $model || in_array( (string) $model->get_setting( 'type' ), self::SKIPPED_TYPES, true ) ) {
				continue;
			}

			$label = (string) $model->get_setting( 'label' );

			$submitted[ '' !== $label ? $label : (string) ( $field['key'] ?? $id ) ] = $field['value'] ?? '';
		}

		Recorder::record(
			'ninja-forms',
			array(
				'form_id'    => $form_id,
				'form_title' => $form_data['settings']['title'] ?? '',
				'posted'     => Attribution::get_last(),
				'created_at' => time(),
				'fields'     => $submitted,
			)
		);
	}

	/**
	 * Register the plugin merge-tag group with Ninja Forms.
	 *
	 * Fires on `ninja_forms_loaded` — the action Ninja Forms itself uses to
	 * collect merge-tag providers. By the time it fires, `NF_Abstracts_MergeTags`
	 * is guaranteed to exist, which is why the provider subclass is required
	 * here rather than unconditionally at Bootstrap time.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_merge_tags(): void {
		if ( ! function_exists( 'Ninja_Forms' ) || ! class_exists( 'NF_Abstracts_MergeTags' ) ) {
			return;
		}

		require_once __DIR__ . '/NinjaFormsMergeTags.php';

		Ninja_Forms()->merge_tags['utmat_merge_tags'] = new NinjaFormsMergeTags();
	}

	/**
	 * Map every merge-tag param to its full attribution field name.
	 *
	 * Built from the same source of truth Attribution::field_name() uses
	 * (Attribution::touch_keys()) so the merge-tag list always matches the
	 * recognised field names — e.g. `utm_source` => `utm_source`, `gclid`
	 * => `gclid`, `landing` => `landing_page`, `channel` => `channel`,
	 * `referrer_host` => `referrer_host`, plus a `_first`-suffixed variant
	 * of each for first-touch values. The tag namespace (`{utmat:…}`) and
	 * tag params are unchanged; only the field names they map to follow the
	 * standard parameter names. The landing page keeps its historical
	 * `landing` tag param; the channel and referrer host use the field
	 * name itself.
	 *
	 * @since 1.0.0
	 * @return array<string, string> Merge-tag param => full attribution field name.
	 */
	public static function merge_tag_params(): array {
		$params = array();

		foreach ( Attribution::touch_keys() as $param ) {
			$field_name = Attribution::field_name( $param );
			$tag_param  = ( 'lp' === $param ) ? 'landing' : $field_name;

			$params[ $tag_param ]            = $field_name;
			$params[ $tag_param . '_first' ] = $field_name . '_first';
		}

		return $params;
	}

	/**
	 * Resolve a merge-tag param to its attribution value.
	 *
	 * Returns an empty string — never a notice or the literal tag text — for
	 * an unrecognised param or one with no attribution data.
	 *
	 * @since 1.0.0
	 *
	 * @param string $param Merge-tag param, e.g. `utm_source` or `utm_source_first`.
	 *
	 * @return string esc_html()'d attribution value, or an empty string.
	 */
	public static function resolve( string $param ): string {
		$params = self::merge_tag_params();

		if ( ! isset( $params[ $param ] ) ) {
			return '';
		}

		$map = Attribution::field_map();

		return esc_html( $map[ $params[ $param ] ] ?? '' );
	}

	/**
	 * Report per-form attribution field coverage for Tracking Doctor diagnostics.
	 *
	 * For each Ninja Forms form, inspects every field for a `default` setting
	 * matching one of our `{utmat:<param>}` merge tags — the same mechanism
	 * register_merge_tags() exposes. Returns an empty array when Ninja Forms
	 * is inactive.
	 *
	 * @since 1.0.0
	 * @return array<int, array{form_id: int|string, form_title: string, status: string, present: string[], missing: string[]}>
	 */
	public static function get_form_status(): array {
		if ( ! function_exists( 'Ninja_Forms' ) ) {
			return array();
		}

		$params = self::merge_tag_params();
		$names  = Attribution::field_names();
		$rows   = array();

		foreach ( Ninja_Forms()->form()->get_forms() as $form ) {
			$form_id = $form->get_id();
			$present = array();

			foreach ( (array) Ninja_Forms()->form( $form_id )->get_fields() as $field ) {
				$default = (string) $field->get_setting( 'default' );
				if ( preg_match( '/^\{utmat:([a-z_]+)\}$/', $default, $matches ) && isset( $params[ $matches[1] ] ) ) {
					$present[] = $params[ $matches[1] ];
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
				'form_id'    => $form_id,
				'form_title' => (string) $form->get_setting( 'title' ),
				'status'     => $status,
				'present'    => $present,
				'missing'    => $missing,
			);
		}

		return $rows;
	}
}
