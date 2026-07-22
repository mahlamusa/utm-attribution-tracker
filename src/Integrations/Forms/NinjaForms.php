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

use UTMAT\Tracking\Attribution;
use UTMAT\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

/**
 * NinjaForms
 *
 * @since 1.0.0
 */
class NinjaForms {

	/**
	 * Register the merge-tag hook — only when Ninja Forms is active.
	 *
	 * Hooked on `plugins_loaded` priority 20 (Ninja Forms may load after this
	 * plugin). Bails without adding a single hook when Ninja Forms is absent.
	 * `is_plugin_active()` is loaded on demand — it lives in an admin-only
	 * file not autoloaded on front-end requests.
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

		add_action( 'ninja_forms_loaded', array( self::class, 'register_merge_tags' ) );
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
	 * (Tracker::PARAM_WHITELIST plus the `lp` landing-page param, renamed
	 * `landing`) so the merge-tag list always matches the recognised field
	 * names — e.g. `utm_source` => `utmat_source`, `gclid` =>
	 * `utmat_gclid`, `landing` => `utmat_landing`, plus a
	 * `_first`-suffixed variant of each for first-touch values.
	 *
	 * @since 1.0.0
	 * @return array<string, string> Merge-tag param => full attribution field name.
	 */
	public static function merge_tag_params(): array {
		$params = array();

		foreach ( array_merge( Tracker::PARAM_WHITELIST, array( 'lp' ) ) as $param ) {
			$tag_param  = ( 'lp' === $param ) ? 'landing' : $param;
			$field_name = Attribution::field_name( $param );

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
