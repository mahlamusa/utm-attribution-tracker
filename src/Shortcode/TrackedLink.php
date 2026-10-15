<?php
/**
 * TrackedLink shortcode — [utmatra_link].
 *
 * Usage:
 *   [utmatra_link id="example-campaign" label="Click here"]
 *   [utmatra_link id="example-campaign"]Click <strong>here</strong>[/utmatra_link]
 *
 * Renders an anchor tag that points to the plugin's redirect endpoint so
 * clicks are counted before the visitor is forwarded to the destination.
 * Enclosed content wins over the label attribute and may contain markup
 * (filtered through wp_kses_post) and nested shortcodes.
 *
 * @package Utmatra\Shortcode
 */

namespace Utmatra\Shortcode;

use Utmatra\Tracking\LinkManager;

defined( 'ABSPATH' ) || exit;

/**
 * TrackedLink
 */
class TrackedLink {

	/**
	 * Render the [utmatra_link] shortcode.
	 *
	 * @since 1.0.0
	 * @param array<string, string>|string $atts    Shortcode attributes.
	 * @param string|null                  $content Shortcode inner content; wins over the label attribute.
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
			'utmatra_link'
		);

		$id = sanitize_key( $atts['id'] );
		if ( empty( $id ) ) {
			return '';
		}

		$link = LinkManager::get( $id );
		if ( ! $link ) {
			return '';
		}

		$redirect   = esc_url( LinkManager::redirect_url( $id ) );
		$class_attr = ! empty( $atts['class'] ) ? ' class="' . esc_attr( $atts['class'] ) . '"' : '';
		$rel_attr   = ! empty( $atts['rel'] ) ? ' rel="' . esc_attr( $atts['rel'] ) . '"' : '';

		// Enclosed content wins over the label attribute; it may carry markup
		// (author-provided, filtered through wp_kses_post) and nested shortcodes.
		if ( null !== $content && '' !== trim( $content ) ) {
			$text = wp_kses_post( do_shortcode( $content ) );
		} else {
			$label = ! empty( $atts['label'] ) ? $atts['label'] : ( $link['label'] ?? $id );
			$text  = esc_html( $label );
		}

		return sprintf(
			'<a href="%s"%s%s>%s</a>',
			$redirect,
			$class_attr,
			$rel_attr,
			$text
		);
	}
}
