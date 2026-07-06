<?php
/**
 * TrackedLink shortcode — [utmat_link].
 *
 * Usage: [utmat_link id="example-campaign" label="Click here"]
 *
 * Renders an anchor tag that points to the plugin's redirect endpoint so
 * clicks are counted before the visitor is forwarded to the destination.
 *
 * @package UTMAT\Shortcode
 */

namespace UTMAT\Shortcode;

use UTMAT\Tracking\LinkManager;

defined( 'ABSPATH' ) || exit;

/**
 * TrackedLink
 */
class TrackedLink {

	/**
	 * Render the [utmat_link] shortcode.
	 *
	 * @since 1.0.0
	 * @param array<string, string>|string $atts    Shortcode attributes.
	 * @param string|null                  $content Shortcode inner content (unused).
	 * @return string HTML anchor tag or empty string.
	 */
	public static function render( $atts, ?string $content = null ): string {
		$atts = shortcode_atts(
			array(
				'id'    => '',
				'label' => '',
				'class' => '',
				'rel'   => 'noopener',
			),
			(array) $atts,
			'utmat_link'
		);

		$id = sanitize_key( $atts['id'] );
		if ( empty( $id ) ) {
			return '';
		}

		$link = LinkManager::get( $id );
		if ( ! $link ) {
			return '';
		}

		$label       = ! empty( $atts['label'] ) ? $atts['label'] : ( $link['label'] ?? $id );
		$redirect    = esc_url( LinkManager::redirect_url( $id ) );
		$class_attr  = ! empty( $atts['class'] ) ? ' class="' . esc_attr( $atts['class'] ) . '"' : '';
		$rel_attr    = ! empty( $atts['rel'] ) ? ' rel="' . esc_attr( $atts['rel'] ) . '"' : '';

		return sprintf(
			'<a href="%s"%s%s>%s</a>',
			$redirect,
			$class_attr,
			$rel_attr,
			esc_html( $label )
		);
	}
}
