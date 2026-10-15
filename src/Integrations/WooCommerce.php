<?php
/**
 * WooCommerce integration — order attribution capture and display.
 *
 * Attaches the first/last-touch attribution sets (read from the
 * `utmatra_attr` cookie via Attribution) and the visitor id to WooCommerce
 * orders as order meta at checkout. Once the order is paid it records a
 * conversion event row for the revenue-by-campaign report and — when a URL
 * is configured — POSTs the conversion to a webhook (see
 * Utmatra\Reports\ConversionWebhook), exactly once per order. It also
 * renders a "Campaign attribution" metabox on the order edit screen.
 *
 * Every hook in this class is registered only when WooCommerce is active
 * (checked at `plugins_loaded` priority 20, after Woo has loaded). On a site
 * without WooCommerce the class is loaded but completely inert.
 *
 * @package Utmatra\Integrations
 */

namespace Utmatra\Integrations;

use Utmatra\Insights\Recorder;
use Utmatra\Reports\ConversionWebhook;
use Utmatra\Scheduler;
use Utmatra\Tracking\Attribution;
use Utmatra\Tracking\Tracker;
use Utmatra\Tracking\TrafficSource;
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
	public const META_FIRST = '_utmatra_first';

	/**
	 * Order meta key holding the last-touch attribution set.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const META_LAST = '_utmatra_last';

	/**
	 * Order meta key holding the visitor id captured at checkout.
	 *
	 * Payment confirmations often arrive server-to-server (gateway webhooks,
	 * IPN) without the visitor's cookie, so the id is stored with the order.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const META_VISITOR = '_utmatra_vid';

	/**
	 * Order meta flag set once the paid conversion has been recorded.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const META_CONVERTED = '_utmatra_converted';

	/**
	 * Order meta flag set at checkout: record the conversion once paid.
	 *
	 * Only orders carrying it are recorded on payment, so orders placed
	 * before conversions moved to payment (already recorded at checkout,
	 * no flag) are never counted a second time.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const META_AWAITING = '_utmatra_awaiting_payment';

	/**
	 * Action Scheduler hook that delivers the order webhook asynchronously.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const WEBHOOK_HOOK = 'utmatra_send_order_webhook';

	/**
	 * Register WooCommerce hooks — only when WooCommerce is active.
	 *
	 * Hooked on `plugins_loaded` priority 20: this plugin boots at priority 10
	 * and WooCommerce may load after it alphabetically, so the class check
	 * must be deferred rather than run at boot time. Bails without adding a
	 * single hook when WooCommerce is absent.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Hooks paid-conversion recording and the async order webhook.
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

		// The conversion is recorded only once the order is paid: online
		// gateways call payment_complete(), offline methods (cash on delivery,
		// or BACS once marked paid) move the order to a paid status.
		add_action( 'woocommerce_payment_complete', array( self::class, 'record_paid_conversion' ) );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'record_on_paid_status' ), 10, 3 );

		// Order webhook delivery, queued on Action Scheduler by
		// record_paid_conversion() so a slow receiver never blocks a gateway
		// callback or an admin bulk status change.
		add_action( self::WEBHOOK_HOOK, array( self::class, 'send_order_webhook' ) );

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
				$out['utmatra'] = __( 'Attribution', 'utm-attribution-tracker' );
			}
		}
		if ( ! isset( $out['utmatra'] ) ) {
			$out['utmatra'] = __( 'Attribution', 'utm-attribution-tracker' );
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
		if ( 'utmatra' === $column && $order instanceof WC_Order ) {
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
		if ( 'utmatra' !== $column ) {
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
	 * Attach attribution meta and the visitor id to an order at checkout,
	 * and flag it to have its conversion recorded once paid (META_AWAITING).
	 *
	 * Skips silently when the visitor has no attribution cookie, and when the
	 * order already carries last-touch meta (double-write guard: the classic
	 * and Store API hooks can both fire for one order under some checkout
	 * setups). Meta is written via the CRUD API only — HPOS-compatible.
	 *
	 * Nothing is recorded or sent here: checkout runs before the gateway takes
	 * payment, so a declined or abandoned order must not count as revenue.
	 * The conversion is recorded by record_paid_conversion() once the order
	 * is paid.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Records the conversion on payment instead of at checkout.
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

		$order->update_meta_data( self::META_FIRST, Attribution::get_first() );
		$order->update_meta_data( self::META_LAST, Attribution::get_last() );

		$visitor_id = Attribution::visitor_id();
		if ( '' !== $visitor_id ) {
			$order->update_meta_data( self::META_VISITOR, $visitor_id );
		}
		$order->update_meta_data( self::META_AWAITING, 1 );

		if ( $save ) {
			$order->save();
		}
	}

	/**
	 * Record the conversion when an order moves to a paid status.
	 *
	 * Hooked on `woocommerce_order_status_changed`, so any status in
	 * wc_get_is_paid_statuses() counts — processing and completed by
	 * default, plus custom paid statuses an order is moved to by hand.
	 *
	 * @since 1.0.0
	 * @param int|string $order_id The order id.
	 * @param string     $from     The previous status (unused).
	 * @param string     $to       The new status, without the `wc-` prefix.
	 * @return void
	 */
	public static function record_on_paid_status( $order_id, $from, $to ): void {
		if ( in_array( (string) $to, wc_get_is_paid_statuses(), true ) ) {
			self::record_paid_conversion( $order_id );
		}
	}

	/**
	 * Record the conversion for a paid order — exactly once.
	 *
	 * Hooked on `woocommerce_payment_complete`, and called by
	 * record_on_paid_status(); both can fire for the same order, so the
	 * META_CONVERTED flag makes every call after the first a no-op. Only
	 * orders flagged META_AWAITING at checkout are recorded — orders from
	 * before this change, and orders created outside checkout (admin, REST,
	 * subscription renewals that copy the parent's meta), are skipped.
	 *
	 * Everything is built from the order meta stored at checkout, never from
	 * the current request: the payment confirmation may be a gateway's
	 * server-to-server call carrying no visitor cookie, so the row's referrer
	 * is left empty rather than recording the gateway or admin screen. The
	 * conversion row is inserted via Tracker::record() directly, bypassing
	 * is_duplicate() — each order is a distinct conversion. The webhook is
	 * queued as an async action (sent inline when Action Scheduler is not
	 * loaded).
	 *
	 * @since 1.0.0
	 * @param int|string $order_id The paid order's id.
	 * @return void
	 */
	public static function record_paid_conversion( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order->get_meta( self::META_CONVERTED ) || ! $order->get_meta( self::META_AWAITING ) ) {
			return;
		}
		if ( ! in_array( $order->get_created_via(), array( 'checkout', 'store-api' ), true ) ) {
			return;
		}

		$last = $order->get_meta( self::META_LAST );
		if ( ! is_array( $last ) || empty( $last ) ) {
			return;
		}

		// Flag first and persist: this narrows (but cannot fully close) the
		// window in which two concurrent payment callbacks both record.
		$order->update_meta_data( self::META_CONVERTED, time() );
		$order->delete_meta_data( self::META_AWAITING );
		$order->save();

		$event = array(
			'link_id'       => 'order',
			'is_conversion' => 1,
			'touch'         => 'last',
			'order_total'   => (float) $order->get_total(),
			'destination'   => $order->get_checkout_order_received_url(),
			'referrer'      => '',
			'visitor_id'    => (string) $order->get_meta( self::META_VISITOR ),
			// The checkout's own referrer is the site, so the row inherits
			// the last touch's channel instead of being classified fresh.
			'channel'       => (string) ( $last['ch'] ?? '' ),
			// Conversion metadata for the Journeys labels.
			'meta'          => array(
				'order_id'     => $order->get_id(),
				'order_number' => (string) $order->get_order_number(),
				'currency'     => $order->get_currency(),
			),
		);
		foreach ( Tracker::param_whitelist() as $key ) {
			$event[ $key ] = isset( $last[ $key ] ) ? (string) $last[ $key ] : '';
		}

		Tracker::record( $event );

		Recorder::record(
			'woocommerce',
			array(
				'form_id'    => $order->get_id(),
				'form_title' => sprintf(
					/* translators: %s: order number. */
					__( 'Order #%s', 'utm-attribution-tracker' ),
					$order->get_order_number()
				),
				'posted'     => $last,
				'created_at' => time(),
				// The order conversion row and the conversion webhook are
				// both handled here — without this flag Recorder::record()
				// would add a second `lead` row and a second POST per order.
				'is_lead'    => false,
			)
		);

		if ( '' === ConversionWebhook::url() ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::WEBHOOK_HOOK, array( (int) $order->get_id() ), Scheduler::GROUP, true );
		} else {
			self::send_order_webhook( $order->get_id() );
		}
	}

	/**
	 * Deliver the conversion webhook for a recorded order.
	 *
	 * The WEBHOOK_HOOK action callback (and the inline fallback without
	 * Action Scheduler). Reads the attribution stored on the order, so it is
	 * independent of the request that runs it.
	 *
	 * @since 1.0.0
	 * @param int|string $order_id The order id.
	 * @return void
	 */
	public static function send_order_webhook( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || '' === ConversionWebhook::url() ) {
			return;
		}

		$first = $order->get_meta( self::META_FIRST );
		$last  = $order->get_meta( self::META_LAST );

		ConversionWebhook::send_conversion(
			$order->get_id(),
			(float) $order->get_total(),
			$order->get_currency(),
			is_array( $first ) ? $first : array(),
			is_array( $last ) ? $last : array()
		);
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
				'utmatra-attribution',
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
