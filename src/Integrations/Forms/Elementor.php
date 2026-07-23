<?php
/**
 * Elementor — Elementor Pro Forms attribution auto-fill.
 *
 * Hooks Elementor Pro's hidden-field render filter to auto-fill Hidden
 * fields whose Field ID matches a tracked attribution parameter. Ported
 * from HandL's `lite/elementor.php` (`elementor_pro/forms/render/item/hidden`)
 * and adapted to read from Attribution::field_map() instead of raw
 * per-param cookies, matching the adaptation already made for NinjaForms.
 *
 * @package UTMAT\Integrations\Forms
 */

namespace UTMAT\Integrations\Forms;

use UTMAT\Tracking\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor
 *
 * @since 1.0.0
 */
class Elementor {

	/**
	 * Register the hidden-field auto-fill filter when Elementor and
	 * Elementor Pro are both active.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_init(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'elementor_pro/forms/render/item/hidden', array( self::class, 'fill_hidden_field' ), 10, 3 );
	}

	/**
	 * Whether both base Elementor and Elementor Pro's Forms feature are active.
	 *
	 * Free Elementor has no Form widget — the Forms feature ships in
	 * Elementor Pro — so ELEMENTOR_PRO_VERSION alone would be enough to know
	 * Forms is available. But ELEMENTOR_PRO_VERSION is defined at the top of
	 * Pro's main plugin file unconditionally, even if base Elementor is
	 * missing or inactive, so the base-Elementor check guards against that
	 * broken-install state too. The constant is defined immediately on Pro's
	 * own bootstrap, before widgets register, so it is safe to check here on
	 * plugins_loaded.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private static function is_active(): bool {
		if ( ! did_action( 'elementor/loaded' ) && ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * Auto-fill a Hidden field's value from attribution data.
	 *
	 * Resolves the field's Field ID (`custom_id`, falling back to `_id`)
	 * against the tracked attribution field names; when it matches and a
	 * value is available, sets `field_value`. Fields that don't match a
	 * tracked parameter, or that have no attribution value, are returned
	 * untouched so the widget's own default/placeholder wins.
	 *
	 * Values come from Attribution::field_map(), which already returns
	 * sanitize_text_field()-cleaned strings, and Elementor's own form
	 * renderer escapes field_value at output — it is not escaped again
	 * here to avoid double-escaping values containing `&`.
	 *
	 * @since 1.0.0
	 * @param array $item Hidden field item data (`custom_id`, `_id`, `field_value`, etc).
	 * @param int   $item_index Zero-based index of the field within the form.
	 * @param mixed $form The Elementor Pro form record instance.
	 * @return array The item, with `field_value` set when its Field ID matches a tracked attribution parameter that has a value.
	 */
	public static function fill_hidden_field( $item, $item_index, $form ): array {
		$field = ! empty( $item['custom_id'] ) ? $item['custom_id'] : ( $item['_id'] ?? '' );

		if ( ! in_array( $field, Attribution::field_names(), true ) ) {
			return $item;
		}

		$value = Attribution::field_map()[ $field ] ?? '';

		if ( '' !== $value ) {
			$item['field_value'] = $value;
		}

		return $item;
	}

	/**
	 * Tracking Doctor forms-coverage rows for Elementor Pro forms.
	 *
	 * Scans every post/page's `_elementor_data` for Form widgets (at any
	 * nesting depth) and reports, per widget, which tracked attribution
	 * parameters are present as Hidden fields.
	 *
	 * @since 1.0.0
	 * @return array<int, array{form_id: string, form_title: string, status: string, present: array, missing: array}>
	 */
	public static function get_form_status(): array {
		if ( ! self::is_active() ) {
			return array();
		}

		$names = Attribution::field_names();
		$rows  = array();

		$post_ids = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- forms-coverage scan needs every Elementor post, not a paged subset.
				'fields'         => 'ids',
				'meta_key'       => '_elementor_data', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off diagnostics scan, not a hot path.
				'no_found_rows'  => true,
			)
		);

		foreach ( $post_ids as $post_id ) {
			$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			foreach ( self::find_form_widgets( $data ) as $index => $widget ) {
				$present = array();

				foreach ( (array) ( $widget['settings']['form_fields'] ?? array() ) as $field ) {
					if ( 'hidden' !== ( $field['field_type'] ?? '' ) ) {
						continue;
					}

					$field_name = ! empty( $field['custom_id'] ) ? $field['custom_id'] : ( $field['_id'] ?? '' );

					if ( in_array( $field_name, $names, true ) ) {
						$present[] = $field_name;
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
					'form_id'    => $post_id . '-' . $index,
					'form_title' => get_the_title( $post_id ),
					'status'     => $status,
					'present'    => $present,
					'missing'    => $missing,
				);
			}
		}

		return $rows;
	}

	/**
	 * Recursively collect Elementor Form widgets from an element tree.
	 *
	 * @since 1.0.0
	 * @param array $elements Elementor element tree (as decoded from `_elementor_data`).
	 * @return array<int, array> Flat list of Form widget elements found anywhere in the tree.
	 */
	private static function find_form_widgets( array $elements ): array {
		$forms = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['widgetType'] ) && 'form' === $element['widgetType'] ) {
				$forms[] = $element;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$forms = array_merge( $forms, self::find_form_widgets( $element['elements'] ) );
			}
		}

		return $forms;
	}
}
