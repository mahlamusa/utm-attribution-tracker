<?php
/**
 * Bricks Builder integration — dynamic data tags and lead recording.
 *
 * Registers one Bricks dynamic data tag per recognised attribution field
 * name (`{utm_source}`, `{landing_page_first}`, …) under a "UTM &
 * Attribution" group, so a Hidden field in the Form element can carry one
 * as its value. The tags resolve server-side from the attribution cookie
 * at render, like Bricks' own `{url_parameter:…}` tag, so on a fully
 * page-cached site the stored value may be stale. A form whose actions
 * include "Custom" is recorded as a lead on submit.
 *
 * Built from the Bricks developer documentation without a live Bricks
 * install: every entry point is guarded so nothing runs, and nothing can
 * fail, unless Bricks is the active theme. Registered on
 * `after_setup_theme` because Bricks is a theme and none of its constants
 * exist at `plugins_loaded`.
 *
 * @package Utmatra\Integrations\Forms
 */

namespace Utmatra\Integrations\Forms;

use Utmatra\Insights\Recorder;
use Utmatra\Tracking\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * Bricks
 *
 * @since 1.0.0
 */
class Bricks {

	/**
	 * Bricks form field types that are not submitted values of the form's
	 * own fields: hidden inputs, uploads and passwords.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const SKIPPED_TYPES = array( 'hidden', 'file', 'password' );

	/**
	 * Register Bricks hooks — only when Bricks is the active theme.
	 *
	 * Hooked on `after_setup_theme`. Bails without adding a single hook when
	 * `BRICKS_VERSION` is undefined.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_init(): void {
		if ( ! defined( 'BRICKS_VERSION' ) ) {
			return;
		}

		add_filter( 'bricks/dynamic_tags_list', array( self::class, 'register_tags' ) );
		add_filter( 'bricks/dynamic_data/render_tag', array( self::class, 'render_tag' ), 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', array( self::class, 'render_content' ), 20, 3 );
		add_filter( 'bricks/frontend/render_data', array( self::class, 'render_content' ), 20, 2 );
		add_action( 'bricks/form/custom_action', array( self::class, 'record_lead' ) );
	}

	/**
	 * Add one dynamic data tag per attribution field name to the picker.
	 *
	 * @since 1.0.0
	 * @param array $tags Registered tags, each `[ name, label, group ]`.
	 * @return array
	 */
	public static function register_tags( $tags ) {
		if ( ! is_array( $tags ) ) {
			$tags = array();
		}

		foreach ( Attribution::field_names() as $name ) {
			$tags[] = array(
				'name'  => '{' . $name . '}',
				'label' => $name,
				'group' => __( 'Utmatra', 'utmatra-utm-attribution-and-tracking' ),
			);
		}

		return $tags;
	}

	/**
	 * Resolve a single attribution tag.
	 *
	 * Any tag that is not an attribution field name passes through
	 * untouched. The value is HTML-escaped so it is safe both as text and
	 * inside the documented attribute use (`value="{utm_source}"`).
	 *
	 * @since 1.0.0
	 *
	 * @param string $tag     The tag including braces.
	 * @param mixed  $post    The post being rendered (unused).
	 * @param string $context Render context (unused).
	 *
	 * @return string
	 */
	public static function render_tag( $tag, $post, $context = 'text' ) {
		if ( ! is_string( $tag ) ) {
			return $tag;
		}

		$name = trim( $tag, '{}' );

		// Only the documented `{name}` form resolves; a malformed tag such
		// as `{{utm_source}` is left untouched.
		if ( '{' . $name . '}' !== $tag || ! in_array( $name, Attribution::field_names(), true ) ) {
			return $tag;
		}

		return self::value( $name );
	}

	/**
	 * Resolve every attribution tag inside a block of content.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content The content.
	 * @param mixed  $post    The post being rendered (unused).
	 * @param string $context Render context (unused).
	 *
	 * @return string
	 */
	public static function render_content( $content, $post, $context = 'text' ) {
		if ( ! is_string( $content ) || false === strpos( $content, '{' ) ) {
			return $content;
		}

		$names   = Attribution::field_names();
		$pattern = '/\{(' . implode(
			'|',
			array_map(
				static function ( $name ) {
					return preg_quote( $name, '/' );
				},
				$names
			)
		) . ')\}/';

		return (string) preg_replace_callback(
			$pattern,
			static function ( $matches ) {
				return self::value( $matches[1] );
			},
			$content
		);
	}

	/**
	 * Record a lead for the recent-leads dashboard widget on submission.
	 *
	 * Fires on `bricks/form/custom_action`, the form's "Custom" action.
	 * Skips silently when the visitor carries no attribution data or the
	 * form object does not expose the documented accessors. The submitted
	 * values of the form's own fields are passed as `fields`, keyed by
	 * label; hidden fields, uploads and passwords are left out.
	 *
	 * @since 1.0.0
	 * @param mixed $form The Bricks form instance (`get_fields()`, `get_settings()`).
	 * @return void
	 */
	public static function record_lead( $form ): void {
		if ( ! Attribution::has_data() ) {
			return;
		}

		if ( ! is_object( $form ) || ! method_exists( $form, 'get_fields' ) || ! method_exists( $form, 'get_settings' ) ) {
			return;
		}

		$values    = (array) $form->get_fields();
		$settings  = (array) $form->get_settings();
		$post_id   = (int) ( $values['postId'] ?? 0 );
		$submitted = array();

		foreach ( (array) ( $settings['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) || in_array( (string) ( $field['type'] ?? '' ), self::SKIPPED_TYPES, true ) ) {
				continue;
			}

			$id    = (string) ( $field['id'] ?? '' );
			$label = (string) ( $field['label'] ?? '' );

			$submitted[ '' !== $label ? $label : $id ] = $values[ 'form-field-' . $id ] ?? '';
		}

		Recorder::record(
			'bricks',
			array(
				'form_id'    => $post_id . '-' . (string) ( $values['formId'] ?? '' ),
				'form_title' => $post_id > 0 ? get_the_title( $post_id ) : '',
				'posted'     => Attribution::get_last(),
				'created_at' => time(),
				'fields'     => $submitted,
			)
		);
	}

	/**
	 * Report per-form attribution field coverage for Tracking Doctor diagnostics.
	 *
	 * Scans every post's Bricks content for Form elements and reports, per
	 * element, which attribution field names appear as the value of a
	 * Hidden field. The stored shape is decoded defensively; anything
	 * unexpected yields no rows. Returns an empty array when Bricks is not
	 * the active theme.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{form_id: string, form_title: string, status: string, present: string[], missing: string[]}>
	 */
	public static function get_form_status(): array {
		if ( ! defined( 'BRICKS_VERSION' ) ) {
			return array();
		}

		$meta_key = defined( 'BRICKS_DB_PAGE_CONTENT' ) ? BRICKS_DB_PAGE_CONTENT : '_bricks_page_content_2';
		$names    = Attribution::field_names();
		$rows     = array();

		$post_ids = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- forms-coverage scan needs every Bricks post, not a paged subset.
				'fields'         => 'ids',
				'meta_key'       => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off diagnostics scan, not a hot path.
				'no_found_rows'  => true,
			)
		);

		foreach ( $post_ids as $post_id ) {
			foreach ( self::form_elements( get_post_meta( $post_id, $meta_key, true ) ) as $element ) {
				$present = array();

				foreach ( (array) ( $element['settings']['fields'] ?? array() ) as $field ) {
					if ( ! is_array( $field ) || 'hidden' !== ( $field['type'] ?? '' ) ) {
						continue;
					}

					$name = trim( (string) ( $field['value'] ?? '' ), '{}' );

					if ( '' !== $name && trim( (string) $field['value'] ) === '{' . $name . '}' && in_array( $name, $names, true ) ) {
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
					'form_id'    => $post_id . '-' . (string) ( $element['id'] ?? '' ),
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
	 * The Form elements of a stored Bricks content value.
	 *
	 * Bricks stores a flat element list in post meta; it may arrive as an
	 * array, a serialised string or JSON. Any other shape yields nothing.
	 *
	 * @since 1.0.0
	 * @param mixed $stored The raw post meta value.
	 * @return array<int, array<string, mixed>>
	 */
	private static function form_elements( $stored ): array {
		if ( is_string( $stored ) ) {
			$decoded = maybe_unserialize( $stored );
			$stored  = is_array( $decoded ) ? $decoded : json_decode( $stored, true );
		}

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$forms = array();

		foreach ( $stored as $element ) {
			if ( is_array( $element ) && 'form' === ( $element['name'] ?? '' ) ) {
				$forms[] = $element;
			}
		}

		return $forms;
	}

	/**
	 * The escaped attribution value for a field name.
	 *
	 * @since 1.0.0
	 * @param string $name Attribution field name.
	 * @return string esc_html()'d value, or an empty string.
	 */
	private static function value( string $name ): string {
		return esc_html( Attribution::field_map()[ $name ] ?? '' );
	}
}
