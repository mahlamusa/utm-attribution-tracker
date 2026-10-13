<?php
/**
 * WooCommerce integration — order attribution capture and display.
 *
 * Attaches the first/last-touch attribution sets (read from the
 * `utmat_attr` cookie via Attribution) to WooCommerce orders as order
 * meta, records a conversion event row for the revenue-by-campaign report,
 * renders a "Campaign attribution" metabox on the order edit screen, and —
 * when a URL is configured — POSTs the conversion to a webhook (see
 * UTMAT\Reports\ConversionWebhook).
 *
 * Every hook in this class is registered only when WooCommerce is active
 * (checked at `plugins_loaded` priority 20, after Woo has loaded). On a site
 * without WooCommerce the class is loaded but completely inert.
 *
 * @package UTMAT\Integrations
 */

namespace UTMAT\Integrations;

use UTMAT\Insights\Recorder;
use UTMAT\Reports\ConversionWebhook;
use UTMAT\Tracking\Attribution;
use UTMAT\Tracking\Tracker;
use UTMAT\Tracking\TrafficSource;
use WC_Order;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce
 *
 * @since 1.0.0
 */
class WooCommerce {

	/**
	 * Order meta key holding the first-touch attribution set.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const META_FIRST = '_utmat_first';

	/**
	 * Order meta key holding the last-touch attribution set.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const META_LAST = '_utmat_last';

	/**
	 * Register WooCommerce hooks — only when WooCommerce is active.
	 *
	 * Hooked on `plugins_loaded` priority 20: this plugin boots at priority 10
	 * and WooCommerce may load after it alphabetically, so the class check
	 * must be deferred rather than run at boot time. Bails without adding a
	 * single hook when WooCommerce is absent.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Classic (shortcode) checkout — order object before it is saved.
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'capture_classic' ), 10, 2 );

		// Block checkout / Store API — order already created and saved.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'capture_store_api' ) );

		// Order edit screen metabox (legacy post table and HPOS).
		add_action( 'add_meta_boxes', array( self::class, 'register_metabox' ) );

		// Attribution column on the orders list (HPOS and legacy post table).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( self::class, 'add_list_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( self::class, 'render_list_column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( self::class, 'add_list_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( self::class, 'render_list_column_legacy' ), 10, 2 );
	}

	/**
	 * Add the Attribution column to the orders list, after the status column.
	 *
	 * Used for both the HPOS orders table and the legacy shop_order post
	 * table (their column filters share this shape).
	 *
	 * @since 1.0.0
	 * @param array<string, string> $columns Existing list table columns.
	 * @return array<string, string>
	 */
	public static function add_list_column( $columns ): array {
		$columns = is_array( $columns ) ? $columns : array();

		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out['utmat'] = __( 'Attribution', 'utm-attribution-tracker' );
			}
		}
		if ( ! isset( $out['utmat'] ) ) {
			$out['utmat'] = __( 'Attribution', 'utm-attribution-tracker' );
		}

		return $out;
	}

	/**
	 * Render the Attribution column on the HPOS orders table.
	 *
	 * @since 1.0.0
	 * @param string        $column Column key being rendered.
	 * @param WC_Order|null $order  The order for the current row.
	 * @return void
	 */
	public static function render_list_column_hpos( $column, $order ): void {
		if ( 'utmat' === $column && $order instanceof WC_Order ) {
			self::render_list_column( $order );
		}
	}

	/**
	 * Render the Attribution column on the legacy shop_order post table.
	 *
	 * @since 1.0.0
	 * @param string $column  Column key being rendered.
	 * @param int    $post_id The order post ID for the current row.
	 * @return void
	 */
	public static function render_list_column_legacy( $column, $post_id ): void {
		if ( 'utmat' !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order instanceof WC_Order ) {
			self::render_list_column( $order );
		}
	}

	/**
	 * Echo the compact last-touch attribution summary for a list row.
	 *
	 * Shows `source / campaign` from the last touch (either part omitted
	 * when empty), the channel label when both are empty (an untagged
	 * organic, social, AI-assistant or referral touch), or an em dash when
	 * the order has no attribution meta.
	 *
	 * @since 1.0.0
	 * @param WC_Order $order The order for the current row.
	 * @return void
	 */
	private static function render_list_column( WC_Order $order ): void {
		$last = $order->get_meta( self::META_LAST );
		if ( ! is_array( $last ) || empty( $last ) ) {
			echo '<span aria-hidden="true">&mdash;</span>';
			return;
		}

		$parts = array_filter(
			array(
				(string) ( $last['utm_source'] ?? '' ),
				(string) ( $last['utm_campaign'] ?? '' ),
			)
		);

		if ( empty( $parts ) ) {
			$labels  = TrafficSource::channel_labels();
			$channel = (string) ( $last['ch'] ?? '' );
			if ( isset( $labels[ $channel ] ) ) {
				echo esc_html( $labels[ $channel ] );
				return;
			}
			echo '<span aria-hidden="true">&mdash;</span>';
			return;
		}

		echo esc_html( implode( ' / ', $parts ) );
	}

	/**
	 * Capture attribution on the classic (shortcode) checkout.
	 *
	 * Fires inside WC_Checkout::create_order() after totals are set but
	 * before the order is saved, so meta added here is persisted by the
	 * save() call that immediately follows — no extra save needed.
	 *
	 * @since 1.0.0
	 * @param WC_Order             $order The order being created.
	 * @param array<string, mixed> $data  Posted checkout data (unused).
	 * @return void
	 */
	public static function capture_classic( $order, $data ): void {
		if ( $order instanceof WC_Order ) {
			self::attach_attribution( $order, false );
		}
	}

	/**
	 * Capture attribution on the block checkout (Store API).
	 *
	 * Fires after the order has been created and saved, so meta added here
	 * must be persisted with an explicit save(). The double-write guard in
	 * attach_attribution() makes any overlap with the classic hook harmless.
	 *
	 * @since 1.0.0
	 * @param WC_Order $order The processed order.
	 * @return void
	 */
	public static function capture_store_api( $order ): void {
		if ( $order instanceof WC_Order ) {
			self::attach_attribution( $order, true );
		}
	}

	/**
	 * Attach attribution meta to an order and record the conversion event.
	 *
	 * Skips silently when the visitor has no attribution cookie, and when the
	 * order already carries last-touch meta (double-write guard: the classic
	 * and Store API hooks can both fire for one order under some checkout
	 * setups). Meta is written via the CRUD API only — HPOS-compatible.
	 *
	 * The conversion row is built from the last-touch set and inserted via
	 * Tracker::record() directly, bypassing is_duplicate() — each order is a
	 * distinct conversion and must never be deduplicated away.
	 *
	 * @since 1.0.0
	 * @param WC_Order $order The order to attach attribution to.
	 * @param bool     $save  Whether to call save() (true for the Store API path, where the order is already persisted).
	 * @return void
	 */
	private static function attach_attribution( WC_Order $order, bool $save ): void {
		if ( ! Attribution::has_data() ) {
			return;
		}

		// Double-write guard — skip if attribution was already captured.
		if ( ! empty( $order->get_meta( self::META_LAST ) ) ) {
			return;
		}

		$first = Attribution::get_first();
		$last  = Attribution::get_last();

		$order->update_meta_data( self::META_FIRST, $first );
		$order->update_meta_data( self::META_LAST, $last );

		if ( $save ) {
			$order->save();
		}

		// On the classic path the order has no ID yet (save() runs after the
		// hook), so fall back to the generic order-received endpoint URL.
		$destination = $order->get_id()
			? $order->get_checkout_order_received_url()
			: wc_get_endpoint_url( 'order-received', '', wc_get_checkout_url() );

		$event = array(
			'link_id'       => 'order',
			'is_conversion' => 1,
			'touch'         => 'last',
			'order_total'   => (float) $order->get_total(),
			'destination'   => $destination,
			// The checkout's own referrer is the site, so the row inherits
			// the last touch's channel instead of being classified fresh.
			'channel'       => (string) ( $last['ch'] ?? '' ),
		);
		foreach ( Tracker::param_whitelist() as $key ) {
			$event[ $key ] = isset( $last[ $key ] ) ? (string) $last[ $key ] : '';
		}

		Tracker::record( $event );

		// On the classic path the order has no ID yet (see the $destination
		// fallback above) — get_order_number() defaults to get_id(), so a
		// real order number is only available once the order is saved.
		$order_id = $order->get_id();

		Recorder::record(
			'woocommerce',
			array(
				'form_id'    => $order_id,
				'form_title' => $order_id
					? sprintf(
						/* translators: %s: order number. */
						__( 'Order #%s', 'utm-attribution-tracker' ),
						$order->get_order_number()
					)
					: __( 'New order', 'utm-attribution-tracker' ),
				'posted'     => $last,
				'created_at' => time(),
			)
		);

		if ( '' !== ConversionWebhook::url() ) {
			ConversionWebhook::send_conversion(
				$order->get_id(),
				(float) $order->get_total(),
				$order->get_currency(),
				$first,
				$last
			);
		}
	}

	/**
	 * Register the attribution metabox on the order edit screen.
	 *
	 * Registered for both the legacy `shop_order` post-type screen and the
	 * HPOS orders screen (`wc_get_page_screen_id( 'shop-order' )`) so the box
	 * appears whichever order storage mode the site uses.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_metabox(): void {
		$screens = array( 'shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$hpos_screen = wc_get_page_screen_id( 'shop-order' );
			if ( $hpos_screen && ! in_array( $hpos_screen, $screens, true ) ) {
				$screens[] = $hpos_screen;
			}
		}

		foreach ( $screens as $screen ) {
			add_meta_box(
				'utmat-attribution',
				__( 'Campaign attribution (UTM)', 'utm-attribution-tracker' ),
				array( self::class, 'render_metabox' ),
				$screen,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Render the attribution metabox.
	 *
	 * Resolves the order from either a WP_Post (legacy screen) or a WC_Order
	 * (HPOS screen passes the order object directly), then renders the first-
	 * and last-touch sets side by side. Stored meta originates from a
	 * visitor-controlled cookie — input-sanitized but NOT output-escaped — so
	 * every value is esc_html()'d and the landing page esc_url()'d here.
	 *
	 * @since 1.0.0
	 * @param WC_Order|WP_Post $post_or_order The current order or its post object.
	 * @return void
	 */
	public static function render_metabox( $post_or_order ): void {
		$order = ( $post_or_order instanceof WC_Order )
			? $post_or_order
			: wc_get_order( $post_or_order->ID ?? 0 );

		$first = $order ? $order->get_meta( self::META_FIRST ) : array();
		$last  = $order ? $order->get_meta( self::META_LAST ) : array();

		if ( ! is_array( $first ) ) {
			$first = array();
		}
		if ( ! is_array( $last ) ) {
			$last = array();
		}

		if ( empty( $first ) && empty( $last ) ) {
			echo '<p>' . esc_html__( 'No attribution data', 'utm-attribution-tracker' ) . '</p>';
			return;
		}

		echo '<div style="display:flex;gap:24px;flex-wrap:wrap;">';
		self::render_touch_column( __( 'First touch', 'utm-attribution-tracker' ), $first );
		self::render_touch_column( __( 'Last touch', 'utm-attribution-tracker' ), $last );
		echo '</div>';
	}

	/**
	 * Render one labelled attribution column (first or last touch).
	 *
	 * Lists every stored parameter — the six UTMs, the six ad click IDs, the
	 * channel (rendered as its label), the referrer host, the landing page,
	 * and the timestamp (formatted in the site timezone via wp_date()). All
	 * values are escaped at output: esc_html() for text and esc_url() for
	 * the landing page link.
	 *
	 * @since 1.0.0
	 * @param string                    $title The column heading.
	 * @param array<string, string|int> $touch The attribution set to render.
	 * @return void
	 */
	private static function render_touch_column( string $title, array $touch ): void {
		$channel_labels = TrafficSource::channel_labels();
		if ( isset( $touch['ch'] ) && isset( $channel_labels[ $touch['ch'] ] ) ) {
			$touch['ch'] = $channel_labels[ $touch['ch'] ];
		}

		$labels = array(
			'utm_source'   => __( 'Source', 'utm-attribution-tracker' ),
			'utm_medium'   => __( 'Medium', 'utm-attribution-tracker' ),
			'utm_campaign' => __( 'Campaign', 'utm-attribution-tracker' ),
			'utm_id'       => __( 'Campaign ID', 'utm-attribution-tracker' ),
			'utm_term'     => __( 'Term', 'utm-attribution-tracker' ),
			'utm_content'  => __( 'Content', 'utm-attribution-tracker' ),
			'gclid'        => __( 'Google click ID', 'utm-attribution-tracker' ),
			'fbclid'       => __( 'Facebook click ID', 'utm-attribution-tracker' ),
			'msclkid'      => __( 'Microsoft click ID', 'utm-attribution-tracker' ),
			'ttclid'       => __( 'TikTok click ID', 'utm-attribution-tracker' ),
			'li_fat_id'    => __( 'LinkedIn click ID', 'utm-attribution-tracker' ),
			'twclid'       => __( 'X / Twitter click ID', 'utm-attribution-tracker' ),
			'ch'           => __( 'Channel', 'utm-attribution-tracker' ),
			'rh'           => __( 'Referrer', 'utm-attribution-tracker' ),
			'lp'           => __( 'Landing page', 'utm-attribution-tracker' ),
			'ts'           => __( 'Time', 'utm-attribution-tracker' ),
		);

		echo '<div style="flex:1;min-width:220px;">';
		echo '<h4 style="margin:0 0 8px;">' . esc_html( $title ) . '</h4>';

		if ( empty( $touch ) ) {
			echo '<p>' . esc_html__( 'No attribution data', 'utm-attribution-tracker' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped" style="border:none;">';
		foreach ( $labels as $key => $label ) {
			if ( ! isset( $touch[ $key ] ) || '' === $touch[ $key ] ) {
				continue;
			}

			echo '<tr><th scope="row" style="text-align:left;width:40%;">' . esc_html( $label ) . '</th><td>';

			if ( 'lp' === $key ) {
				$lp = (string) $touch[ $key ];
				echo '<a href="' . esc_url( $lp ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $lp ) . '</a>';
			} elseif ( 'ts' === $key ) {
				echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $touch[ $key ] ) );
			} else {
				echo esc_html( (string) $touch[ $key ] );
			}

			echo '</td></tr>';
		}
		echo '</table>';
		echo '</div>';
	}
}
