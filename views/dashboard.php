<?php
/**
 * Dashboard view
 *
 * Tabbed admin page (Overview, Links, Journeys, Settings, Diagnostics)
 * rendered by Dashboard::render_page(). All data is loaded by
 * assets/js/dashboard.js through the utmatra/v1 REST routes.
 *
 * @since 1.0.0
 *
 * @package Utmatra\Admin
 */

use Utmatra\Admin\DashboardWidget;
use Utmatra\Tracking\Attribution;
use Utmatra\Tracking\TrafficSource;

defined( 'ABSPATH' ) || exit;

// Tags allowed inside translated strings that carry inline code or links.
$utmatra_kses = array(
	'code' => array(),
	'a'    => array(
		'href'                         => array(),
		'data-utmatra-run-diagnostics' => array(),
	),
);
?>
<div class="wrap utmatra-wrap" id="utmatra-dashboard">

	<div class="utmatra-header">
		<div class="utmatra-header__titles">
			<h1 class="utmatra-brand">
				<img class="utmatra-brand__logo" src="<?php echo esc_url( UTMATRA_URL . 'assets/images/utmatra-logo.svg' ); ?>" width="161" height="39" alt="Utmatra" />
				<span class="utmatra-brand__tagline"><?php esc_html_e( 'UTM Attribution and Tracking', 'utm-attribution-tracker' ); ?></span>
			</h1>
			<p class="utmatra-header__sub"><?php esc_html_e( 'Campaign clicks, conversions and first/last-touch attribution, kept on your own site.', 'utm-attribution-tracker' ); ?></p>
		</div>
		<div class="utmatra-header__actions">
			<button type="button" class="button" id="utmatra-export-btn"
				title="<?php esc_attr_e( 'Download the raw events for the selected period as CSV (up to 10,000 rows).', 'utm-attribution-tracker' ); ?>"><?php esc_html_e( 'Export CSV', 'utm-attribution-tracker' ); ?></button>
			<button type="button" class="button button-primary" id="utmatra-add-link-btn"><?php esc_html_e( 'Add tracked link', 'utm-attribution-tracker' ); ?></button>
		</div>
	</div>

	<hr class="wp-header-end" />

	<div class="nav-tab-wrapper utmatra-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Dashboard sections', 'utm-attribution-tracker' ); ?>">
		<a href="#overview" class="nav-tab nav-tab-active" id="utmatra-tab-overview" role="tab" aria-selected="true" aria-controls="utmatra-panel-overview" tabindex="0"><?php esc_html_e( 'Overview', 'utm-attribution-tracker' ); ?></a>
		<a href="#links" class="nav-tab" id="utmatra-tab-links" role="tab" aria-selected="false" aria-controls="utmatra-panel-links" tabindex="-1"><?php esc_html_e( 'Links', 'utm-attribution-tracker' ); ?></a>
		<a href="#journeys" class="nav-tab" id="utmatra-tab-journeys" role="tab" aria-selected="false" aria-controls="utmatra-panel-journeys" tabindex="-1"><?php esc_html_e( 'Journeys', 'utm-attribution-tracker' ); ?></a>
		<a href="#settings" class="nav-tab" id="utmatra-tab-settings" role="tab" aria-selected="false" aria-controls="utmatra-panel-settings" tabindex="-1"><?php esc_html_e( 'Settings', 'utm-attribution-tracker' ); ?></a>
		<a href="#diagnostics" class="nav-tab" id="utmatra-tab-diagnostics" role="tab" aria-selected="false" aria-controls="utmatra-panel-diagnostics" tabindex="-1"><?php esc_html_e( 'Diagnostics', 'utm-attribution-tracker' ); ?></a>
	</div>

	<!-- Error state -->
	<div id="utmatra-error" class="notice notice-error" hidden>
		<p id="utmatra-error-msg"></p>
	</div>

	<!-- Screen reader announcements for copy actions -->
	<div id="utmatra-live" class="screen-reader-text" role="status" aria-live="polite"></div>

	<!-- Overview -->
	<div class="utmatra-panel" id="utmatra-panel-overview" role="tabpanel" aria-labelledby="utmatra-tab-overview" tabindex="0">

		<div class="utmatra-filters" id="utmatra-filters">
			<div class="utmatra-period-btns utmatra-segmented" role="group" aria-label="<?php esc_attr_e( 'Date range', 'utm-attribution-tracker' ); ?>">
				<button type="button" class="utmatra-period-btn active" data-period="7" aria-pressed="true"><?php esc_html_e( '7 days', 'utm-attribution-tracker' ); ?></button>
				<button type="button" class="utmatra-period-btn" data-period="30" aria-pressed="false"><?php esc_html_e( '30 days', 'utm-attribution-tracker' ); ?></button>
				<button type="button" class="utmatra-period-btn" data-period="90" aria-pressed="false"><?php esc_html_e( '90 days', 'utm-attribution-tracker' ); ?></button>
			</div>

			<div class="utmatra-daterange">
				<label for="utmatra-date-since" class="utmatra-filters__label"><?php esc_html_e( 'Custom', 'utm-attribution-tracker' ); ?></label>
				<input type="date" id="utmatra-date-since" />
				<span aria-hidden="true"><?php esc_html_e( 'to', 'utm-attribution-tracker' ); ?></span>
				<label for="utmatra-date-until" class="screen-reader-text"><?php esc_html_e( 'Custom range end date', 'utm-attribution-tracker' ); ?></label>
				<input type="date" id="utmatra-date-until" />
				<button type="button" class="button" id="utmatra-date-apply"><?php esc_html_e( 'Apply', 'utm-attribution-tracker' ); ?></button>
				<button type="button" class="button-link" id="utmatra-date-clear" hidden><?php esc_html_e( 'Clear', 'utm-attribution-tracker' ); ?></button>
			</div>

			<div class="utmatra-filters__campaign">
				<label for="utmatra-campaign-filter" class="utmatra-filters__label"><?php esc_html_e( 'Campaign', 'utm-attribution-tracker' ); ?></label>
				<select id="utmatra-campaign-filter">
					<option value=""><?php esc_html_e( 'All campaigns', 'utm-attribution-tracker' ); ?></option>
				</select>
			</div>

			<p class="utmatra-filters__caption" id="utmatra-compare-caption"></p>
		</div>

		<!-- Loading state -->
		<div id="utmatra-loading" class="utmatra-loading" aria-live="polite">
			<span class="spinner is-active"></span>
			<p><?php esc_html_e( 'Loading data…', 'utm-attribution-tracker' ); ?></p>
		</div>

		<div class="utmatra-tiles" id="utmatra-tiles">
			<div class="utmatra-tile" id="utmatra-tile-clicks">
				<p class="utmatra-tile__label"><?php esc_html_e( 'Total clicks', 'utm-attribution-tracker' ); ?></p>
				<p class="utmatra-tile__value" id="utmatra-total-count" aria-live="polite">—</p>
				<p class="utmatra-tile__meta">
					<span class="utmatra-trend" id="utmatra-trend" hidden></span>
					<span id="utmatra-clicks-meta"></span>
				</p>
			</div>
			<div class="utmatra-tile" id="utmatra-tile-conversions" hidden>
				<p class="utmatra-tile__label"><?php esc_html_e( 'Conversions', 'utm-attribution-tracker' ); ?></p>
				<p class="utmatra-tile__value" id="utmatra-conv-count">—</p>
				<p class="utmatra-tile__meta" id="utmatra-conv"></p>
			</div>
			<div class="utmatra-tile" id="utmatra-tile-revenue" hidden>
				<p class="utmatra-tile__label"><?php esc_html_e( 'Attributed revenue', 'utm-attribution-tracker' ); ?></p>
				<p class="utmatra-tile__value" id="utmatra-revenue-total">—</p>
				<p class="utmatra-tile__meta" id="utmatra-revenue-meta"></p>
			</div>
			<div class="utmatra-tile utmatra-tile--text" id="utmatra-tile-source">
				<p class="utmatra-tile__label"><?php esc_html_e( 'Top source', 'utm-attribution-tracker' ); ?></p>
				<p class="utmatra-tile__value" id="utmatra-top-source">—</p>
				<p class="utmatra-tile__meta" id="utmatra-top-source-meta"></p>
			</div>
			<div class="utmatra-tile utmatra-tile--text" id="utmatra-tile-campaign">
				<p class="utmatra-tile__label"><?php esc_html_e( 'Top campaign', 'utm-attribution-tracker' ); ?></p>
				<p class="utmatra-tile__value" id="utmatra-top-campaign">—</p>
				<p class="utmatra-tile__meta" id="utmatra-top-campaign-meta"></p>
			</div>
		</div>

		<!-- First-run onboarding, shown by JS when no events have ever been recorded -->
		<div class="utmatra-onboarding" id="utmatra-onboarding" hidden>
			<div class="utmatra-onboarding__main">
				<div>
					<h2><?php esc_html_e( 'Get your first tracked click', 'utm-attribution-tracker' ); ?></h2>
					<p class="utmatra-onboarding__lead"><?php esc_html_e( 'Tracking is already on. Nothing has been recorded yet because no tagged visit has arrived.', 'utm-attribution-tracker' ); ?></p>
				</div>
				<ol class="utmatra-steps">
					<li class="utmatra-step">
						<span class="utmatra-step__num" aria-hidden="true">1</span>
						<div>
							<h3><?php esc_html_e( 'Open a tagged URL in a private window', 'utm-attribution-tracker' ); ?></h3>
							<p><?php esc_html_e( 'Visits from logged-in administrators are not tracked, so use a private window or another browser.', 'utm-attribution-tracker' ); ?></p>
							<div class="utmatra-step__copy">
								<code class="utmatra-code" id="utmatra-onboarding-url"><?php echo esc_html( home_url( '/' ) . '?utm_source=newsletter&utm_medium=email&utm_campaign=launch' ); ?></code>
								<button type="button" class="button button-small" id="utmatra-onboarding-copy"><?php esc_html_e( 'Copy', 'utm-attribution-tracker' ); ?></button>
							</div>
						</div>
					</li>
					<li class="utmatra-step">
						<span class="utmatra-step__num" aria-hidden="true">2</span>
						<div>
							<h3><?php esc_html_e( 'Or create a tracked short link', 'utm-attribution-tracker' ); ?></h3>
							<p><?php esc_html_e( 'A tracked link counts the click before forwarding the visitor, and comes with a QR code.', 'utm-attribution-tracker' ); ?></p>
							<p class="utmatra-step__action">
								<button type="button" class="button" id="utmatra-onboarding-add"><?php esc_html_e( 'Create a tracked link', 'utm-attribution-tracker' ); ?></button>
							</p>
						</div>
					</li>
					<li class="utmatra-step">
						<span class="utmatra-step__num" aria-hidden="true">3</span>
						<div>
							<h3><?php esc_html_e( 'Come back in a minute', 'utm-attribution-tracker' ); ?></h3>
							<p><?php esc_html_e( 'Ad clicks carrying gclid, fbclid, msclkid, ttclid, li_fat_id or twclid are tracked automatically, even without UTM parameters. Bots and repeated reloads are filtered out.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</li>
				</ol>
			</div>
			<div class="utmatra-onboarding__aside">
				<div>
					<h3><?php esc_html_e( 'Already sent traffic?', 'utm-attribution-tracker' ); ?></h3>
					<p><?php esc_html_e( 'If tagged visits should have shown up, a page cache or consent plugin is the usual reason.', 'utm-attribution-tracker' ); ?></p>
				</div>
				<a class="button" href="#diagnostics" data-utmatra-run-diagnostics="1"><?php esc_html_e( 'Run diagnostics', 'utm-attribution-tracker' ); ?></a>
				<div class="utmatra-onboarding__tracked">
					<h3><?php esc_html_e( 'What is tracked', 'utm-attribution-tracker' ); ?></h3>
					<ul>
						<li><?php esc_html_e( 'UTM source, medium, campaign, term, content and ID', 'utm-attribution-tracker' ); ?></li>
						<li><?php esc_html_e( 'Ad click IDs from six platforms', 'utm-attribution-tracker' ); ?></li>
						<li><?php esc_html_e( 'Landing page, referrer and device type', 'utm-attribution-tracker' ); ?></li>
						<li><?php esc_html_e( 'First and last touch, stored in a first-party cookie for the attribution window set in Settings', 'utm-attribution-tracker' ); ?></li>
					</ul>
				</div>
			</div>
		</div>

		<div class="utmatra-placeholder" id="utmatra-charts-placeholder" hidden>
			<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false"><path d="M3 3v18h18"></path><path d="M7 15l4-5 4 3 5-7"></path></svg>
			<p><?php esc_html_e( 'Clicks over time, traffic breakdown, reach and revenue charts appear here after the first click.', 'utm-attribution-tracker' ); ?></p>
		</div>

		<div class="utmatra-charts" id="utmatra-charts" hidden>

			<div class="utmatra-card utmatra-card--chart">
				<div class="utmatra-card__header">
					<h3 class="utmatra-card__title"><?php esc_html_e( 'Clicks over time', 'utm-attribution-tracker' ); ?></h3>
				</div>
				<div class="utmatra-card__body utmatra-card__body--chart">
					<canvas id="utmatra-chart-line" role="img"
						aria-label="<?php esc_attr_e( 'Clicks over time line chart', 'utm-attribution-tracker' ); ?>"></canvas>
					<p class="description utmatra-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
				</div>
			</div>

			<section class="utmatra-group" aria-labelledby="utmatra-group-traffic">
				<div class="utmatra-group__header">
					<h2 class="utmatra-group__title" id="utmatra-group-traffic"><?php esc_html_e( 'Traffic breakdown', 'utm-attribution-tracker' ); ?></h2>
					<p class="utmatra-group__desc"><?php esc_html_e( 'Where the clicks in this period came from', 'utm-attribution-tracker' ); ?></p>
				</div>
				<div class="utmatra-group__grid utmatra-group__grid--4">
					<div class="utmatra-card utmatra-card--bars">
						<div class="utmatra-card__header">
							<h3 class="utmatra-card__title" id="utmatra-title-source"><?php esc_html_e( 'By source', 'utm-attribution-tracker' ); ?></h3>
							<span class="utmatra-card__caption"><?php esc_html_e( 'clicks', 'utm-attribution-tracker' ); ?></span>
						</div>
						<div class="utmatra-card__body">
							<ol class="utmatra-bars" id="utmatra-bars-source" aria-labelledby="utmatra-title-source"></ol>
							<p class="description utmatra-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmatra-card utmatra-card--bars">
						<div class="utmatra-card__header">
							<h3 class="utmatra-card__title" id="utmatra-title-campaign"><?php esc_html_e( 'By campaign', 'utm-attribution-tracker' ); ?></h3>
							<span class="utmatra-card__caption"><?php esc_html_e( 'clicks', 'utm-attribution-tracker' ); ?></span>
						</div>
						<div class="utmatra-card__body">
							<ol class="utmatra-bars" id="utmatra-bars-campaign" aria-labelledby="utmatra-title-campaign"></ol>
							<p class="description utmatra-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmatra-card utmatra-card--bars">
						<div class="utmatra-card__header">
							<h3 class="utmatra-card__title" id="utmatra-title-medium"><?php esc_html_e( 'By medium', 'utm-attribution-tracker' ); ?></h3>
							<span class="utmatra-card__caption"><?php esc_html_e( 'clicks', 'utm-attribution-tracker' ); ?></span>
						</div>
						<div class="utmatra-card__body">
							<ol class="utmatra-bars" id="utmatra-bars-medium" aria-labelledby="utmatra-title-medium"></ol>
							<p class="description utmatra-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmatra-card utmatra-card--bars">
						<div class="utmatra-card__header">
							<h3 class="utmatra-card__title" id="utmatra-title-device"><?php esc_html_e( 'By device', 'utm-attribution-tracker' ); ?></h3>
							<span class="utmatra-card__caption"><?php esc_html_e( 'share', 'utm-attribution-tracker' ); ?></span>
						</div>
						<div class="utmatra-card__body">
							<ol class="utmatra-bars" id="utmatra-bars-device" aria-labelledby="utmatra-title-device"></ol>
							<p class="description utmatra-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
				</div>
			</section>

			<section class="utmatra-group" aria-labelledby="utmatra-group-reach">
				<div class="utmatra-group__header">
					<h2 class="utmatra-group__title" id="utmatra-group-reach"><?php esc_html_e( 'Reach', 'utm-attribution-tracker' ); ?></h2>
					<p class="utmatra-group__desc"><?php esc_html_e( 'Channels, landing pages, referrers and ad platforms', 'utm-attribution-tracker' ); ?></p>
				</div>
				<div class="utmatra-group__grid utmatra-group__grid--4">
					<div class="utmatra-card utmatra-card--bars">
						<div class="utmatra-card__header">
							<h3 class="utmatra-card__title" id="utmatra-title-channel"><?php esc_html_e( 'Channels', 'utm-attribution-tracker' ); ?></h3>
							<span class="utmatra-card__caption"><?php esc_html_e( 'visits', 'utm-attribution-tracker' ); ?></span>
						</div>
						<div class="utmatra-card__body">
							<ol class="utmatra-bars" id="utmatra-bars-channel" aria-labelledby="utmatra-title-channel"></ol>
							<p class="description utmatra-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
							<p class="utmatra-field__hint" id="utmatra-channel-hint" hidden><?php esc_html_e( 'Untagged visit tracking is off, so only tagged traffic is classified. Turn it on in Settings to see direct, organic, social, AI-assistant and referral visits.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmatra-card utmatra-card--bars">
						<div class="utmatra-card__header">
							<h3 class="utmatra-card__title" id="utmatra-title-landing"><?php esc_html_e( 'Top landing pages', 'utm-attribution-tracker' ); ?></h3>
							<span class="utmatra-card__caption"><?php esc_html_e( 'clicks', 'utm-attribution-tracker' ); ?></span>
						</div>
						<div class="utmatra-card__body">
							<ol class="utmatra-bars" id="utmatra-bars-landing" aria-labelledby="utmatra-title-landing"></ol>
							<p class="description utmatra-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmatra-card utmatra-card--bars">
						<div class="utmatra-card__header">
							<h3 class="utmatra-card__title" id="utmatra-title-referrer"><?php esc_html_e( 'Top referrers', 'utm-attribution-tracker' ); ?></h3>
							<span class="utmatra-card__caption"><?php esc_html_e( 'clicks', 'utm-attribution-tracker' ); ?></span>
						</div>
						<div class="utmatra-card__body">
							<ol class="utmatra-bars" id="utmatra-bars-referrer" aria-labelledby="utmatra-title-referrer"></ol>
							<p class="description utmatra-nodata" hidden><?php esc_html_e( 'No external referrers recorded in this period.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmatra-card utmatra-card--bars">
						<div class="utmatra-card__header">
							<h3 class="utmatra-card__title" id="utmatra-title-platform"><?php esc_html_e( 'Ad platforms', 'utm-attribution-tracker' ); ?></h3>
							<span class="utmatra-card__caption"><?php esc_html_e( 'click IDs', 'utm-attribution-tracker' ); ?></span>
						</div>
						<div class="utmatra-card__body">
							<ol class="utmatra-bars" id="utmatra-bars-platform" aria-labelledby="utmatra-title-platform"></ol>
							<p class="description utmatra-nodata" hidden><?php esc_html_e( 'No ad click IDs recorded in this period.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
				</div>
			</section>

			<!-- Revenue, shown by JS only when WooCommerce is active -->
			<section class="utmatra-group" id="utmatra-card-revenue" aria-labelledby="utmatra-group-revenue" hidden>
				<div class="utmatra-group__header">
					<h2 class="utmatra-group__title" id="utmatra-group-revenue"><?php esc_html_e( 'Revenue', 'utm-attribution-tracker' ); ?></h2>
					<p class="utmatra-group__desc"><?php esc_html_e( 'WooCommerce orders attributed to tagged campaigns', 'utm-attribution-tracker' ); ?></p>
				</div>
				<div class="utmatra-card">
					<div class="utmatra-card__header">
						<h3 class="utmatra-card__title"><?php esc_html_e( 'Revenue by campaign', 'utm-attribution-tracker' ); ?></h3>
						<span class="utmatra-card__caption"><?php esc_html_e( 'Tagged campaigns', 'utm-attribution-tracker' ); ?></span>
					</div>
					<div class="utmatra-revenue">
						<div class="utmatra-card__body utmatra-card__body--chart utmatra-revenue__chart">
							<canvas id="utmatra-chart-revenue" role="img"
								aria-label="<?php esc_attr_e( 'Revenue by campaign bar chart', 'utm-attribution-tracker' ); ?>"></canvas>
							<p class="description utmatra-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
						</div>
						<dl class="utmatra-revenue__summary" id="utmatra-revenue-summary">
							<dt><?php esc_html_e( 'Orders', 'utm-attribution-tracker' ); ?></dt>
							<dd id="utmatra-revenue-orders">—</dd>
							<dt><?php esc_html_e( 'Average order', 'utm-attribution-tracker' ); ?></dt>
							<dd id="utmatra-revenue-aov">—</dd>
						</dl>
					</div>
				</div>
			</section>

		</div><!-- /.utmatra-charts -->

		<p class="utmatra-footnote">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: link to the Settings tab. */
					__( 'Logged-in administrators are not tracked, and bots and repeated reloads are filtered out automatically. Data retention is set under %s.', 'utm-attribution-tracker' ),
					'<a href="#settings">' . esc_html__( 'Settings', 'utm-attribution-tracker' ) . '</a>'
				),
				$utmatra_kses
			);
			?>
		</p>
	</div><!-- /#utmatra-panel-overview -->

	<!-- Links -->
	<div class="utmatra-panel" id="utmatra-panel-links" role="tabpanel" aria-labelledby="utmatra-tab-links" tabindex="0" hidden>
		<div class="utmatra-section utmatra-links" id="utmatra-links-section" hidden>
			<p class="utmatra-section__desc"><?php esc_html_e( 'Use these redirect URLs in emails, ads, and banners. The click is counted before the visitor is forwarded to the destination.', 'utm-attribution-tracker' ); ?></p>

			<div class="utmatra-links-toolbar">
				<label for="utmatra-links-filter" class="screen-reader-text"><?php esc_html_e( 'Search tracked links', 'utm-attribution-tracker' ); ?></label>
				<input type="search" id="utmatra-links-filter" class="utmatra-links-toolbar__search"
					placeholder="<?php esc_attr_e( 'Search by label, campaign, source, or medium…', 'utm-attribution-tracker' ); ?>" />
				<label for="utmatra-links-campaign" class="screen-reader-text"><?php esc_html_e( 'Filter links by campaign', 'utm-attribution-tracker' ); ?></label>
				<select id="utmatra-links-campaign">
					<option value=""><?php esc_html_e( 'All campaigns', 'utm-attribution-tracker' ); ?></option>
				</select>
				<span class="utmatra-links-toolbar__count" id="utmatra-links-count"></span>
				<span class="utmatra-add-form__msg" id="utmatra-links-msg" role="status"></span>
				<div class="utmatra-links-toolbar__bulk">
					<button type="button" class="button utmatra-bulk-delete-btn" id="utmatra-bulk-delete-btn" hidden><?php esc_html_e( 'Delete selected', 'utm-attribution-tracker' ); ?></button>
				</div>
			</div>

			<table class="wp-list-table widefat fixed striped utmatra-links-table" id="utmatra-links-table">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<label for="utmatra-select-all" class="screen-reader-text"><?php esc_html_e( 'Select all', 'utm-attribution-tracker' ); ?></label>
							<input type="checkbox" id="utmatra-select-all" />
						</td>
						<th scope="col" class="utmatra-col-label column-primary"><?php esc_html_e( 'Label', 'utm-attribution-tracker' ); ?></th>
						<th scope="col" class="utmatra-col-campaign"><?php esc_html_e( 'Campaign', 'utm-attribution-tracker' ); ?></th>
						<th scope="col" class="utmatra-col-source"><?php esc_html_e( 'Source / medium', 'utm-attribution-tracker' ); ?></th>
						<th scope="col" class="utmatra-col-clicks" aria-sort="none">
							<button type="button" class="button-link utmatra-col-sortable" id="utmatra-sort-clicks"><?php esc_html_e( 'Clicks', 'utm-attribution-tracker' ); ?> <span class="utmatra-sort-arrow" aria-hidden="true"></span></button>
						</th>
						<th scope="col" class="utmatra-col-url utmatra-col-shortlink"><?php esc_html_e( 'Short link', 'utm-attribution-tracker' ); ?></th>
						<th scope="col" class="utmatra-col-url utmatra-col-destination"><?php esc_html_e( 'Destination', 'utm-attribution-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody id="utmatra-links-tbody">
					<!-- Populated by JS -->
				</tbody>
			</table>

			<p class="utmatra-footnote"><?php esc_html_e( 'Hover over or tab to a row for Edit, Duplicate, QR code, Copy link and Delete. Click counts are all time.', 'utm-attribution-tracker' ); ?></p>
		</div>
	</div><!-- /#utmatra-panel-links -->

	<!-- Journeys -->
	<div class="utmatra-panel" id="utmatra-panel-journeys" role="tabpanel" aria-labelledby="utmatra-tab-journeys" tabindex="0" hidden>
		<div class="utmatra-journeys-head">
			<div>
				<h2><?php esc_html_e( 'Journeys', 'utm-attribution-tracker' ); ?></h2>
				<p class="utmatra-section__desc"><?php esc_html_e( 'The last 50 conversions and the tagged visits that led to them. Journeys are built from the visitor id cookie, so they cover traffic recorded after this version.', 'utm-attribution-tracker' ); ?></p>
			</div>
			<span class="utmatra-add-form__msg" id="utmatra-journeys-msg" role="status"></span>
		</div>

		<div id="utmatra-journeys-loading" class="utmatra-loading" aria-live="polite" hidden>
			<span class="spinner is-active"></span>
			<p><?php esc_html_e( 'Loading journeys…', 'utm-attribution-tracker' ); ?></p>
		</div>

		<div class="utmatra-placeholder" id="utmatra-journeys-empty" hidden>
			<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false"><path d="M3 12h4l3-8 4 16 3-8h4"></path></svg>
			<p><?php esc_html_e( 'No journeys yet. Journeys appear for visitors tracked after this version; earlier events have no visitor id.', 'utm-attribution-tracker' ); ?></p>
		</div>

		<table class="wp-list-table widefat fixed striped utmatra-journeys-table" id="utmatra-journeys-table" hidden>
			<thead>
				<tr>
					<th scope="col" class="utmatra-col-when"><?php esc_html_e( 'When', 'utm-attribution-tracker' ); ?></th>
					<th scope="col" class="utmatra-col-conversion column-primary"><?php esc_html_e( 'Conversion', 'utm-attribution-tracker' ); ?></th>
					<th scope="col" class="utmatra-col-first"><?php esc_html_e( 'First touch', 'utm-attribution-tracker' ); ?></th>
					<th scope="col" class="utmatra-col-last"><?php esc_html_e( 'Last touch', 'utm-attribution-tracker' ); ?></th>
					<th scope="col" class="utmatra-col-touches"><?php esc_html_e( 'Touches', 'utm-attribution-tracker' ); ?></th>
				</tr>
			</thead>
			<tbody id="utmatra-journeys-tbody">
				<!-- Populated by JS -->
			</tbody>
		</table>

		<p class="utmatra-footnote" id="utmatra-journeys-without" hidden></p>
	</div><!-- /#utmatra-panel-journeys -->

	<!-- Settings -->
	<div class="utmatra-panel" id="utmatra-panel-settings" role="tabpanel" aria-labelledby="utmatra-tab-settings" tabindex="0" hidden>

		<!-- Shown by JS when a known full-page cache plugin is detected -->
		<div class="notice notice-info inline utmatra-cache-notice" id="utmatra-cache-notice" hidden>
			<p></p>
		</div>

		<form id="utmatra-settings-form" class="utmatra-settings-form">

			<section class="utmatra-settings-section" aria-labelledby="utmatra-settings-tracking">
				<div class="utmatra-settings-section__intro">
					<h2 id="utmatra-settings-tracking"><?php esc_html_e( 'Tracking', 'utm-attribution-tracker' ); ?></h2>
					<p><?php esc_html_e( 'What the beacon records on each visit.', 'utm-attribution-tracker' ); ?></p>
				</div>
				<div class="utmatra-card utmatra-settings-card">
					<div class="utmatra-field">
						<span class="utmatra-field__label"><?php esc_html_e( 'Untagged visits', 'utm-attribution-tracker' ); ?></span>
						<div class="utmatra-field__control">
							<label class="utmatra-switch" for="utmatra-track-visits">
								<input type="checkbox" id="utmatra-track-visits" name="track_visits" class="utmatra-switch__input" />
								<span class="utmatra-switch__track" aria-hidden="true"></span>
								<span class="utmatra-switch__text"><?php esc_html_e( 'Track the first page of every visit', 'utm-attribution-tracker' ); ?></span>
							</label>
							<p class="utmatra-field__hint"><?php esc_html_e( 'Records the landing page and referrer of visits that arrive without campaign parameters, so organic, social, AI-assistant and referral traffic appear in the Channels report. On by default; about one event per visit.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmatra-field">
						<label class="utmatra-field__label" for="utmatra-custom-params"><?php esc_html_e( 'Custom parameters', 'utm-attribution-tracker' ); ?></label>
						<div class="utmatra-field__control">
							<input type="text" id="utmatra-custom-params" name="custom_params" class="regular-text" placeholder="coupon, aff, promo2" />
							<p class="utmatra-field__hint">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %1$s: example custom parameter name, as inline code. */
										__( 'Extra URL parameters to capture alongside UTM tags and click IDs, separated by commas. Up to 10 names that start with a letter and use lowercase letters, digits and underscores. Each one is available to forms under its own name (%1$s) and becomes a CSV column. Do not use the name of a form field or anything that identifies a person.', 'utm-attribution-tracker' ),
										'<code>coupon</code>'
									),
									$utmatra_kses
								);
								?>
							</p>
						</div>
					</div>
				</div>
			</section>

			<section class="utmatra-settings-section" aria-labelledby="utmatra-settings-privacy">
				<div class="utmatra-settings-section__intro">
					<h2 id="utmatra-settings-privacy"><?php esc_html_e( 'Data and privacy', 'utm-attribution-tracker' ); ?></h2>
					<p><?php esc_html_e( 'How long events are kept and how long a visitor stays attributed to a campaign.', 'utm-attribution-tracker' ); ?></p>
				</div>
				<div class="utmatra-card utmatra-settings-card">
					<div class="utmatra-field">
						<label class="utmatra-field__label" for="utmatra-retention-days"><?php esc_html_e( 'Keep events for', 'utm-attribution-tracker' ); ?></label>
						<div class="utmatra-field__control">
							<div class="utmatra-inline">
								<input type="number" id="utmatra-retention-days" name="retention_days" min="0" step="1" class="small-text" />
								<span><?php esc_html_e( 'days', 'utm-attribution-tracker' ); ?></span>
							</div>
							<p class="utmatra-field__hint"><?php esc_html_e( 'Events older than this are deleted daily. 0 keeps events forever.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmatra-field">
						<label class="utmatra-field__label" for="utmatra-cookie-days"><?php esc_html_e( 'Attribution window', 'utm-attribution-tracker' ); ?></label>
						<div class="utmatra-field__control">
							<div class="utmatra-inline">
								<input type="number" id="utmatra-cookie-days" name="cookie_days" min="1" max="730" step="1" class="small-text" />
								<span><?php esc_html_e( 'days', 'utm-attribution-tracker' ); ?></span>
							</div>
							<p class="utmatra-field__hint"><?php esc_html_e( 'How long the first/last-touch attribution cookie lives. Longer windows attribute slower-converting visitors.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmatra-settings-card__group">
						<h3 class="utmatra-card__title"><?php esc_html_e( 'Consent', 'utm-attribution-tracker' ); ?></h3>
						<div class="utmatra-field">
							<label class="utmatra-field__label" for="utmatra-consent-category"><?php esc_html_e( 'Consent category', 'utm-attribution-tracker' ); ?></label>
							<div class="utmatra-field__control">
								<select id="utmatra-consent-category" name="consent_category">
									<option value="marketing"><?php esc_html_e( 'Marketing (recommended)', 'utm-attribution-tracker' ); ?></option>
									<option value="statistics"><?php esc_html_e( 'Statistics', 'utm-attribution-tracker' ); ?></option>
									<option value="either"><?php esc_html_e( 'Marketing or statistics', 'utm-attribution-tracker' ); ?></option>
								</select>
								<p class="utmatra-field__hint"><?php esc_html_e( 'The attribution cookie is written only after the visitor grants this consent category. Applies when a plugin implementing the WP Consent API is active.', 'utm-attribution-tracker' ); ?></p>
								<p class="utmatra-field__hint" id="utmatra-consent-status">
									<?php
									// Server-rendered state of the WP Consent API; the two
									// "missing" states reuse the Tracking Doctor wording.
									if ( ! function_exists( 'wp_has_consent' ) ) {
										echo esc_html(
											sprintf(
												/* translators: %s: consent category label (marketing, statistics, or marketing or statistics). */
												__( 'No WP Consent API detected — the attribution cookie is written on every tagged visit. Install a consent plugin that implements the WP Consent API to gate it on the %s category.', 'utm-attribution-tracker' ),
												Attribution::consent_category_label( Attribution::consent_category() )
											)
										);
									} elseif ( '' === wp_get_consent_type() ) {
										esc_html_e( 'WP Consent API is active but no consent-management plugin has declared a consent type, so the API reports every category as granted and the cookie is written for every visitor.', 'utm-attribution-tracker' );
									} else {
										echo esc_html(
											sprintf(
												/* translators: %s: consent type declared by the consent plugin (optin or optout). */
												__( 'WP Consent API detected (%s).', 'utm-attribution-tracker' ),
												wp_get_consent_type()
											)
										);
									}
									echo ' ';
									esc_html_e( 'On sites with a full-page cache, a change to this setting reaches visitors after the cache is purged.', 'utm-attribution-tracker' );
									?>
								</p>
							</div>
						</div>
					</div>
				</div>
			</section>

			<section class="utmatra-settings-section" aria-labelledby="utmatra-settings-report">
				<div class="utmatra-settings-section__intro">
					<h2 id="utmatra-settings-report"><?php esc_html_e( 'Weekly report', 'utm-attribution-tracker' ); ?></h2>
					<p><?php esc_html_e( 'A short summary email once a week with clicks, top campaigns, top sources and, with WooCommerce, revenue.', 'utm-attribution-tracker' ); ?></p>
				</div>
				<div class="utmatra-card utmatra-settings-card">
					<div class="utmatra-field">
						<span class="utmatra-field__label"><?php esc_html_e( 'Weekly email', 'utm-attribution-tracker' ); ?></span>
						<div class="utmatra-field__control">
							<label class="utmatra-switch" for="utmatra-weekly-email">
								<input type="checkbox" id="utmatra-weekly-email" name="weekly_email" class="utmatra-switch__input" />
								<span class="utmatra-switch__track" aria-hidden="true"></span>
								<span class="utmatra-switch__text"><?php esc_html_e( 'Send a weekly campaign summary email', 'utm-attribution-tracker' ); ?></span>
							</label>
							<p class="utmatra-field__hint"><?php esc_html_e( 'Sent through wp_mail(), so your SMTP plugin applies. Off by default.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmatra-field">
						<span class="utmatra-field__label"><?php esc_html_e( 'Recipient', 'utm-attribution-tracker' ); ?></span>
						<div class="utmatra-field__control">
							<p class="utmatra-readonly" id="utmatra-report-recipient"><?php echo esc_html( get_option( 'admin_email' ) ); ?></p>
							<p class="utmatra-field__hint">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: name of the filter that changes the report recipient. */
										__( 'The site admin email. Developers can change the recipient with the %s filter.', 'utm-attribution-tracker' ),
										'<code>utmatra_report_recipient</code>'
									),
									$utmatra_kses
								);
								?>
							</p>
						</div>
					</div>
					<div class="utmatra-field utmatra-field--actions">
						<span class="utmatra-field__label" aria-hidden="true"></span>
						<div class="utmatra-field__control utmatra-inline">
							<button type="button" class="button" id="utmatra-test-email"
								title="<?php esc_attr_e( 'Send the weekly summary to the report recipient now, regardless of the toggle.', 'utm-attribution-tracker' ); ?>"><?php esc_html_e( 'Send test email', 'utm-attribution-tracker' ); ?></button>
							<span class="utmatra-add-form__msg" id="utmatra-test-email-msg" role="status"></span>
						</div>
					</div>
				</div>
			</section>

			<section class="utmatra-settings-section" aria-labelledby="utmatra-settings-webhook">
				<div class="utmatra-settings-section__intro">
					<h2 id="utmatra-settings-webhook"><?php esc_html_e( 'Webhooks', 'utm-attribution-tracker' ); ?></h2>
					<p><?php esc_html_e( 'Send each attributed WooCommerce order and form lead, with its attribution data, to your CRM or automation tool.', 'utm-attribution-tracker' ); ?></p>
				</div>
				<div class="utmatra-card utmatra-settings-card">
					<div class="utmatra-field">
						<label class="utmatra-field__label" for="utmatra-webhook-preset"><?php esc_html_e( 'Preset', 'utm-attribution-tracker' ); ?></label>
						<div class="utmatra-field__control">
							<select id="utmatra-webhook-preset" name="webhook_preset">
								<option value="generic"><?php esc_html_e( 'Generic JSON', 'utm-attribution-tracker' ); ?></option>
								<option value="zapier"><?php esc_html_e( 'Zapier', 'utm-attribution-tracker' ); ?></option>
								<option value="make"><?php esc_html_e( 'Make', 'utm-attribution-tracker' ); ?></option>
								<option value="hubspot"><?php esc_html_e( 'HubSpot Forms', 'utm-attribution-tracker' ); ?></option>
								<option value="gohighlevel"><?php esc_html_e( 'GoHighLevel', 'utm-attribution-tracker' ); ?></option>
							</select>
							<p class="utmatra-field__hint" id="utmatra-webhook-preset-help"></p>
						</div>
					</div>
					<div class="utmatra-field">
						<label class="utmatra-field__label" for="utmatra-webhook-url"><?php esc_html_e( 'Webhook URL', 'utm-attribution-tracker' ); ?></label>
						<div class="utmatra-field__control">
							<input type="url" id="utmatra-webhook-url" name="webhook_url" class="regular-text" placeholder="https://example.com/webhooks/utm" />
							<p class="utmatra-field__hint"><?php esc_html_e( 'POSTs a JSON payload here for every enabled event. Leave blank to disable.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmatra-field">
						<span class="utmatra-field__label"><?php esc_html_e( 'Send for', 'utm-attribution-tracker' ); ?></span>
						<div class="utmatra-field__control">
							<label class="utmatra-switch" for="utmatra-webhook-orders">
								<input type="checkbox" id="utmatra-webhook-orders" name="webhook_orders" class="utmatra-switch__input" />
								<span class="utmatra-switch__track" aria-hidden="true"></span>
								<span class="utmatra-switch__text"><?php esc_html_e( 'WooCommerce orders', 'utm-attribution-tracker' ); ?></span>
							</label>
							<p class="utmatra-field__hint" id="utmatra-webhook-orders-hint" hidden><?php esc_html_e( 'Not available for the HubSpot preset', 'utm-attribution-tracker' ); ?></p>
							<label class="utmatra-switch" for="utmatra-webhook-leads">
								<input type="checkbox" id="utmatra-webhook-leads" name="webhook_leads" class="utmatra-switch__input" />
								<span class="utmatra-switch__track" aria-hidden="true"></span>
								<span class="utmatra-switch__text"><?php esc_html_e( 'Form leads', 'utm-attribution-tracker' ); ?></span>
							</label>
						</div>
					</div>
					<div id="utmatra-webhook-secret-row" class="utmatra-settings-card__group" hidden>
						<div class="utmatra-field">
							<label class="utmatra-field__label" for="utmatra-webhook-secret"><?php esc_html_e( 'Signing secret', 'utm-attribution-tracker' ); ?></label>
							<div class="utmatra-field__control">
								<div class="utmatra-inline">
									<input type="text" id="utmatra-webhook-secret" class="regular-text utmatra-mono" readonly />
									<button type="button" class="button" id="utmatra-webhook-secret-copy"><?php esc_html_e( 'Copy', 'utm-attribution-tracker' ); ?></button>
								</div>
								<p class="utmatra-field__hint"><?php esc_html_e( 'Every request carries an X-Utmatra-Signature header (sha256=HMAC of the JSON body using this secret) so your receiver can verify it came from this site.', 'utm-attribution-tracker' ); ?></p>
							</div>
						</div>
						<div class="utmatra-field">
							<span class="utmatra-field__label"><?php esc_html_e( 'Last delivery', 'utm-attribution-tracker' ); ?></span>
							<div class="utmatra-field__control utmatra-inline">
								<span id="utmatra-webhook-last" class="description"></span>
								<button type="button" class="button" id="utmatra-test-webhook" hidden
									title="<?php esc_attr_e( 'Send a synthetic payload in the selected preset’s shape to the configured URL now.', 'utm-attribution-tracker' ); ?>"><?php esc_html_e( 'Send test webhook', 'utm-attribution-tracker' ); ?></button>
								<span class="utmatra-add-form__msg" id="utmatra-test-webhook-msg" role="status"></span>
							</div>
						</div>
					</div>
				</div>
			</section>

			<section class="utmatra-settings-section utmatra-settings-section--last" aria-labelledby="utmatra-settings-embed">
				<div class="utmatra-settings-section__intro">
					<h2 id="utmatra-settings-embed"><?php esc_html_e( 'Embedding', 'utm-attribution-tracker' ); ?></h2>
					<p><?php esc_html_e( 'Put tracked links in content and pass attribution into forms.', 'utm-attribution-tracker' ); ?></p>
				</div>
				<div class="utmatra-card utmatra-settings-card">
					<div class="utmatra-field">
						<span class="utmatra-field__label"><?php esc_html_e( 'Shortcode', 'utm-attribution-tracker' ); ?></span>
						<div class="utmatra-field__control">
							<code class="utmatra-code">[utmatra_link id=&quot;example-campaign&quot;]<?php esc_html_e( 'Click here', 'utm-attribution-tracker' ); ?>[/utmatra_link]</code>
							<p class="utmatra-field__hint"><?php esc_html_e( 'Enclosed text becomes the link text. You can also pass label="…", or leave both out to use the link’s label. Incoming visits that already carry UTM parameters are tracked automatically, no shortcode required.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmatra-field">
						<span class="utmatra-field__label"><?php esc_html_e( 'Form fields', 'utm-attribution-tracker' ); ?></span>
						<div class="utmatra-field__control">
							<p>
								<?php
								echo wp_kses(
									sprintf(
										/* translators: 1: utm_source field name, 2: utm_campaign field name, 3: gclid field name, 4: landing_page field name, 5: the _first suffix. */
										__( 'Add a hidden field named %1$s, %2$s, %3$s or %4$s (append %5$s for first touch) and it is filled from the attribution cookie. Contact Form 7, Gravity Forms, Ninja Forms and Elementor Pro Forms are supported.', 'utm-attribution-tracker' ),
										'<code>utm_source</code>',
										'<code>utm_campaign</code>',
										'<code>gclid</code>',
										'<code>landing_page</code>',
										'<code>_first</code>'
									),
									$utmatra_kses
								);
								?>
							</p>
							<p class="utmatra-field__hint">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: link to the Diagnostics tab. */
										__( 'Check which forms are covered under %s.', 'utm-attribution-tracker' ),
										'<a href="#diagnostics" data-utmatra-run-diagnostics="1">' . esc_html__( 'Diagnostics', 'utm-attribution-tracker' ) . '</a>'
									),
									$utmatra_kses
								);
								?>
							</p>
						</div>
					</div>
					<div class="utmatra-field">
						<span class="utmatra-field__label"><?php esc_html_e( 'Privacy policy', 'utm-attribution-tracker' ); ?></span>
						<div class="utmatra-field__control">
							<p>
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: link to the Privacy Policy Guide screen. */
										__( 'Suggested wording is added to the %s.', 'utm-attribution-tracker' ),
										'<a href="' . esc_url( admin_url( 'privacy-policy-guide.php' ) ) . '">' . esc_html__( 'Privacy Policy Guide', 'utm-attribution-tracker' ) . '</a>'
									),
									$utmatra_kses
								);
								?>
							</p>
						</div>
					</div>
				</div>
			</section>

			<div class="utmatra-savebar" id="utmatra-savebar">
				<span class="utmatra-savebar__count" id="utmatra-unsaved-count" aria-live="polite"></span>
				<span class="utmatra-add-form__msg" id="utmatra-settings-msg" role="status"></span>
				<div class="utmatra-savebar__actions">
					<button type="button" class="button" id="utmatra-settings-discard" disabled><?php esc_html_e( 'Discard', 'utm-attribution-tracker' ); ?></button>
					<button type="submit" class="button button-primary" id="utmatra-settings-save"><?php esc_html_e( 'Save settings', 'utm-attribution-tracker' ); ?></button>
				</div>
			</div>
		</form>
	</div><!-- /#utmatra-panel-settings -->

	<!-- Diagnostics -->
	<div class="utmatra-panel" id="utmatra-panel-diagnostics" role="tabpanel" aria-labelledby="utmatra-tab-diagnostics" tabindex="0" hidden>
		<div class="utmatra-diag-header">
			<div class="utmatra-diag-header__text">
				<h2><?php esc_html_e( 'Tracking Doctor', 'utm-attribution-tracker' ); ?></h2>
				<p class="utmatra-section__desc"><?php esc_html_e( 'Local checks on caching, consent, cookies, forms and WooCommerce attribution. Nothing is sent to any external service.', 'utm-attribution-tracker' ); ?></p>
			</div>
			<div class="utmatra-diag-header__actions">
				<span class="utmatra-diag-header__last" id="utmatra-diagnostics-last"><?php esc_html_e( 'Not run yet', 'utm-attribution-tracker' ); ?></span>
				<span class="utmatra-add-form__msg" id="utmatra-diagnostics-msg" role="status"></span>
				<button type="button" class="button button-primary" id="utmatra-run-diagnostics"><?php esc_html_e( 'Run diagnostics', 'utm-attribution-tracker' ); ?></button>
			</div>
		</div>

		<div class="utmatra-placeholder" id="utmatra-diagnostics-placeholder">
			<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false"><path d="M9 11l3 3 8-8"></path><path d="M20 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
			<p><?php esc_html_e( 'Run the checks to see whether caching, consent, cookies, forms and WooCommerce attribution are set up correctly.', 'utm-attribution-tracker' ); ?></p>
		</div>

		<div class="utmatra-diag-summary" id="utmatra-diag-summary" role="list" hidden>
			<div class="utmatra-diag-summary__item is-pass" role="listitem">
				<span><?php esc_html_e( 'Passed', 'utm-attribution-tracker' ); ?></span>
				<strong id="utmatra-diag-count-pass">0</strong>
			</div>
			<div class="utmatra-diag-summary__item is-warn" role="listitem">
				<span><?php esc_html_e( 'Warnings', 'utm-attribution-tracker' ); ?></span>
				<strong id="utmatra-diag-count-warn">0</strong>
			</div>
			<div class="utmatra-diag-summary__item is-fail" role="listitem">
				<span><?php esc_html_e( 'Failed', 'utm-attribution-tracker' ); ?></span>
				<strong id="utmatra-diag-count-fail">0</strong>
			</div>
			<div class="utmatra-diag-summary__item is-skip" role="listitem">
				<span><?php esc_html_e( 'Skipped', 'utm-attribution-tracker' ); ?></span>
				<strong id="utmatra-diag-count-skip">0</strong>
			</div>
		</div>

		<ul class="utmatra-card utmatra-diagnostics-list" id="utmatra-diagnostics-list" hidden></ul>

		<!-- Forms coverage matrix, populated by JS only when the forms check has rows -->
		<div class="utmatra-card utmatra-diagnostics-forms" id="utmatra-diagnostics-forms" hidden>
			<div class="utmatra-card__header">
				<h3 class="utmatra-card__title"><?php esc_html_e( 'Forms coverage', 'utm-attribution-tracker' ); ?></h3>
				<span class="utmatra-card__caption"><?php esc_html_e( 'Attribution fields found on each form', 'utm-attribution-tracker' ); ?></span>
			</div>
			<div class="utmatra-table-scroll">
				<table class="utmatra-table" id="utmatra-diagnostics-forms-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Form', 'utm-attribution-tracker' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Integration', 'utm-attribution-tracker' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'utm-attribution-tracker' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Present', 'utm-attribution-tracker' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Missing', 'utm-attribution-tracker' ); ?></th>
						</tr>
					</thead>
					<tbody id="utmatra-diagnostics-forms-tbody">
						<!-- Populated by JS -->
					</tbody>
				</table>
			</div>
			<p class="description" id="utmatra-diagnostics-unsupported" hidden></p>
		</div>
	</div><!-- /#utmatra-panel-diagnostics -->

	<!-- Add / edit link drawer (outside the panels so loading never hides it) -->
	<div class="utmatra-drawer-backdrop" id="utmatra-drawer-backdrop" hidden></div>
	<aside class="utmatra-drawer" id="utmatra-drawer" role="dialog" aria-modal="true" aria-labelledby="utmatra-drawer-title" tabindex="-1" hidden>
		<form class="utmatra-add-form utmatra-drawer__form" id="utmatra-add-form">
			<div class="utmatra-drawer__header">
				<h2 class="utmatra-drawer__title" id="utmatra-drawer-title"><?php esc_html_e( 'Add tracked link', 'utm-attribution-tracker' ); ?></h2>
				<button type="button" class="utmatra-icon-btn utmatra-drawer__close" id="utmatra-drawer-close" aria-label="<?php esc_attr_e( 'Close', 'utm-attribution-tracker' ); ?>">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M18 6 6 18M6 6l12 12"></path></svg>
				</button>
			</div>

			<div class="utmatra-drawer__body">
				<div class="utmatra-drawer__field" id="utmatra-builder-row">
					<label class="utmatra-switch" for="utmatra-builder-toggle">
						<input type="checkbox" id="utmatra-builder-toggle" class="utmatra-switch__input" />
						<span class="utmatra-switch__track" aria-hidden="true"></span>
						<span class="utmatra-switch__text"><?php esc_html_e( 'Build a URL only', 'utm-attribution-tracker' ); ?></span>
					</label>
					<p class="utmatra-field__hint"><?php esc_html_e( 'Get a tagged URL to paste anywhere. No short link is created and nothing is saved.', 'utm-attribution-tracker' ); ?></p>
				</div>

				<div class="utmatra-drawer__field" id="utmatra-drawer-label-field">
					<label for="utmatra-new-label"><?php esc_html_e( 'Label', 'utm-attribution-tracker' ); ?></label>
					<input type="text" id="utmatra-new-label" name="label" required
						placeholder="<?php esc_attr_e( 'Spring newsletter button', 'utm-attribution-tracker' ); ?>" />
					<p class="utmatra-field__hint"><?php esc_html_e( 'Shown in this table and used as the default link text of the shortcode.', 'utm-attribution-tracker' ); ?></p>
				</div>

				<div class="utmatra-drawer__field">
					<label for="utmatra-new-destination"><?php esc_html_e( 'Destination URL', 'utm-attribution-tracker' ); ?></label>
					<input type="url" id="utmatra-new-destination" name="destination" required
						placeholder="https://example.com/landing-page" />
					<p class="utmatra-field__hint"><?php esc_html_e( 'Where visitors land. The UTM parameters below are added to it on redirect.', 'utm-attribution-tracker' ); ?></p>
				</div>

				<div class="utmatra-presets-row">
					<label for="utmatra-preset-select"><?php esc_html_e( 'Preset', 'utm-attribution-tracker' ); ?></label>
					<select id="utmatra-preset-select">
						<option value=""><?php esc_html_e( 'None', 'utm-attribution-tracker' ); ?></option>
					</select>
					<button type="button" class="button-link" id="utmatra-preset-delete" hidden><?php esc_html_e( 'Delete preset', 'utm-attribution-tracker' ); ?></button>
					<button type="button" class="button-link" id="utmatra-preset-save"><?php esc_html_e( 'Save current as preset', 'utm-attribution-tracker' ); ?></button>
				</div>

				<!-- Shown by JS when "Save current as preset" is clicked -->
				<div class="utmatra-preset-name-row" id="utmatra-preset-name-row" hidden>
					<label for="utmatra-preset-name" class="screen-reader-text"><?php esc_html_e( 'Preset name', 'utm-attribution-tracker' ); ?></label>
					<input type="text" id="utmatra-preset-name" placeholder="<?php esc_attr_e( 'e.g. Instagram Story', 'utm-attribution-tracker' ); ?>" />
					<button type="button" class="button button-primary" id="utmatra-preset-name-confirm"><?php esc_html_e( 'Save', 'utm-attribution-tracker' ); ?></button>
					<button type="button" class="button" id="utmatra-preset-name-cancel"><?php esc_html_e( 'Cancel', 'utm-attribution-tracker' ); ?></button>
				</div>

				<div class="utmatra-drawer__grid">
					<div class="utmatra-drawer__field">
						<label for="utmatra-new-campaign"><?php esc_html_e( 'Campaign', 'utm-attribution-tracker' ); ?></label>
						<input type="text" id="utmatra-new-campaign" name="utm_campaign" list="utmatra-list-campaign" placeholder="spring-sale" />
					</div>
					<div class="utmatra-drawer__field">
						<label for="utmatra-new-source"><?php esc_html_e( 'Source', 'utm-attribution-tracker' ); ?></label>
						<input type="text" id="utmatra-new-source" name="utm_source" list="utmatra-list-source" placeholder="newsletter" />
					</div>
					<div class="utmatra-drawer__field">
						<label for="utmatra-new-medium"><?php esc_html_e( 'Medium', 'utm-attribution-tracker' ); ?></label>
						<input type="text" id="utmatra-new-medium" name="utm_medium" list="utmatra-list-medium" placeholder="email" />
					</div>
				</div>

				<!-- Suggestion datalists, populated by JS from previously used values -->
				<datalist id="utmatra-list-campaign"></datalist>
				<datalist id="utmatra-list-source"></datalist>
				<datalist id="utmatra-list-medium"></datalist>

				<!-- Shown by JS when a UTM value was normalized on blur/submit -->
				<div class="utmatra-normalize-notice" id="utmatra-normalize-notice" hidden>
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"></circle><path d="M12 8v4m0 4h.01"></path></svg>
					<p><?php esc_html_e( 'Some values were normalized for consistent reporting (lowercase, spaces replaced with "-").', 'utm-attribution-tracker' ); ?></p>
					<button type="button" class="utmatra-normalize-notice__dismiss" id="utmatra-normalize-dismiss"
						aria-label="<?php esc_attr_e( 'Dismiss this notice', 'utm-attribution-tracker' ); ?>">&times;</button>
				</div>

				<details class="utmatra-add-form__advanced">
					<summary><?php esc_html_e( 'Advanced: term, content, campaign ID', 'utm-attribution-tracker' ); ?></summary>
					<div class="utmatra-drawer__grid">
						<div class="utmatra-drawer__field">
							<label for="utmatra-new-term"><?php esc_html_e( 'Term', 'utm-attribution-tracker' ); ?></label>
							<input type="text" id="utmatra-new-term" name="utm_term" />
						</div>
						<div class="utmatra-drawer__field">
							<label for="utmatra-new-content"><?php esc_html_e( 'Content', 'utm-attribution-tracker' ); ?></label>
							<input type="text" id="utmatra-new-content" name="utm_content" />
						</div>
						<div class="utmatra-drawer__field">
							<label for="utmatra-new-utmid"><?php esc_html_e( 'Campaign ID (utm_id)', 'utm-attribution-tracker' ); ?></label>
							<input type="text" id="utmatra-new-utmid" name="utm_id" />
						</div>
					</div>
				</details>

				<div class="utmatra-drawer__field" id="utmatra-drawer-custom" hidden>
					<span class="utmatra-field__label"><?php esc_html_e( 'Custom parameters', 'utm-attribution-tracker' ); ?></span>
					<div class="utmatra-drawer__grid" id="utmatra-drawer-custom-grid"></div>
					<p class="utmatra-field__hint">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: link to the Settings tab. */
								__( 'Set up under %s.', 'utm-attribution-tracker' ),
								'<a href="#settings">' . esc_html__( 'Settings', 'utm-attribution-tracker' ) . '</a>'
							),
							$utmatra_kses
						);
						?>
					</p>
				</div>

				<div class="utmatra-drawer__generated">
					<p class="utmatra-field__label" id="utmatra-drawer-shortlink-label"><?php esc_html_e( 'Short link', 'utm-attribution-tracker' ); ?></p>
					<p class="utmatra-readonly" id="utmatra-drawer-shortlink"></p>
					<p class="utmatra-field__label" id="utmatra-drawer-preview-label"><?php esc_html_e( 'Visitors are forwarded to', 'utm-attribution-tracker' ); ?></p>
					<p class="utmatra-field__label" id="utmatra-drawer-preview-label-builder" hidden><?php esc_html_e( 'Tagged URL', 'utm-attribution-tracker' ); ?></p>
					<p class="utmatra-readonly utmatra-readonly--wrap" id="utmatra-drawer-preview"></p>
				</div>
			</div>

			<div class="utmatra-drawer__footer">
				<p class="utmatra-drawer__shortcode" id="utmatra-drawer-shortcode"></p>
				<div class="utmatra-drawer__actions">
					<span class="utmatra-add-form__msg" id="utmatra-add-msg" role="status"></span>
					<button type="button" class="button" id="utmatra-cancel-edit"><?php esc_html_e( 'Cancel', 'utm-attribution-tracker' ); ?></button>
					<button type="submit" class="button button-primary" id="utmatra-submit-btn"><?php esc_html_e( 'Add link', 'utm-attribution-tracker' ); ?></button>
				</div>
			</div>
		</form>
	</aside>

	<!-- Strings exposed to JS for re-use in dynamically rendered content -->
	<script id="utmatra-i18n" type="application/json">
	<?php
	echo wp_json_encode(
		array(
			'copied'                 => __( 'Copied!', 'utm-attribution-tracker' ),
			'copyFailed'             => __( 'Copy failed', 'utm-attribution-tracker' ),
			'errorPrefix'            => __( 'Could not load data:', 'utm-attribution-tracker' ),
			'noLinks'                => __( 'No tracked links yet. Use Add tracked link to create one.', 'utm-attribution-tracker' ),
			'noLinksMatch'           => __( 'No tracked links match your search.', 'utm-attribution-tracker' ),
			'deleteSelected'         => __( 'Delete selected', 'utm-attribution-tracker' ),
			'presetSaved'            => __( 'Preset saved.', 'utm-attribution-tracker' ),
			'presetNameRequired'     => __( 'Please enter a preset name.', 'utm-attribution-tracker' ),
			'confirmDeletePreset'    => __( 'Delete this preset?', 'utm-attribution-tracker' ),
			'testWebhookSending'     => __( 'Sending…', 'utm-attribution-tracker' ),
			'testWebhookSent'        => __( 'Test webhook sent.', 'utm-attribution-tracker' ),
			'testWebhookFailed'      => __( 'Delivery failed — check the URL and your receiver.', 'utm-attribution-tracker' ),
			'presetHelpGeneric'      => __( 'JSON POST signed with X-Utmatra-Signature. Events: conversion, lead, test.', 'utm-attribution-tracker' ),
			'presetHelpZapier'       => __( 'Create a Zap with the trigger Webhooks by Zapier → Catch Hook and paste its URL. Zapier parses the JSON and offers every key, including nested first_touch and last_touch values, as fields in later steps. Click Send test so the Zap can pull in a sample.', 'utm-attribution-tracker' ),
			'presetHelpMake'         => __( 'Add a Webhooks → Custom webhook module and paste its URL. Click Send test while the module is listening so Make can detect the data structure.', 'utm-attribution-tracker' ),
			'presetHelpHubspot'      => __( 'Paste the Forms API URL for your HubSpot form: replace {portalId} with your account ID and {formGuid} with the form ID. Only form fields that exist on that HubSpot form are accepted: add email, firstname, lastname, phone and, to keep attribution, hidden fields named utm_source, utm_medium, utm_campaign, utm_term and utm_content. When the visitor carries HubSpot’s own tracking cookie (hubspotutk) it is included so HubSpot can link the submission to its visitor record. A rejected submission shows HubSpot’s error under Last delivery. Send test creates a contact for utmatra-test@example.com. Leads only; WooCommerce orders are not sent to HubSpot.', 'utm-attribution-tracker' ),
			'presetHelpGohighlevel'  => __( 'In a HighLevel workflow add the Inbound Webhook trigger and paste its URL. Click Send test, then use Fetch sample request in the trigger to map email, phone, first_name and last_name onto Create/Update Contact. Keys are flat and contain no spaces.', 'utm-attribution-tracker' ),
			/* translators: 1: date and time of the delivery, 2: response detail. */
			'webhookLastOk'          => __( 'Last delivery: %1$s succeeded (%2$s)', 'utm-attribution-tracker' ),
			/* translators: 1: date and time of the delivery, 2: error detail. */
			'webhookLastFailed'      => __( 'Last delivery: %1$s failed (%2$s)', 'utm-attribution-tracker' ),
			'duplicate'              => __( 'Duplicate', 'utm-attribution-tracker' ),
			'duplicateHint'          => __( 'Pre-fill the add-link form with this link’s values to create a variant.', 'utm-attribution-tracker' ),
			'copySuffix'             => __( '(copy)', 'utm-attribution-tracker' ),
			'clicksLabel'            => __( 'Clicks', 'utm-attribution-tracker' ),
			'delete'                 => __( 'Delete', 'utm-attribution-tracker' ),
			'confirmDelete'          => __( 'Confirm?', 'utm-attribution-tracker' ),
			'added'                  => __( 'Link added.', 'utm-attribution-tracker' ),
			'updated'                => __( 'Link updated.', 'utm-attribution-tracker' ),
			'deleted'                => __( 'Link deleted.', 'utm-attribution-tracker' ),
			'edit'                   => __( 'Edit', 'utm-attribution-tracker' ),
			'saveChanges'            => __( 'Save changes', 'utm-attribution-tracker' ),
			'addLink'                => __( 'Add link', 'utm-attribution-tracker' ),
			'revenueLabel'           => __( 'Revenue', 'utm-attribution-tracker' ),
			'ordersLabel'            => __( 'Orders', 'utm-attribution-tracker' ),
			'qrCode'                 => __( 'QR code', 'utm-attribution-tracker' ),
			'downloadPng'            => __( 'Download PNG', 'utm-attribution-tracker' ),
			'exporting'              => __( 'Exporting…', 'utm-attribution-tracker' ),
			'exportCsv'              => __( 'Export CSV', 'utm-attribution-tracker' ),
			'exportEmpty'            => __( 'No events to export for this period.', 'utm-attribution-tracker' ),
			'settingsSaved'          => __( 'Settings saved.', 'utm-attribution-tracker' ),
			'platformGoogle'         => __( 'Google Ads', 'utm-attribution-tracker' ),
			'platformFacebook'       => __( 'Facebook / Meta', 'utm-attribution-tracker' ),
			'platformMicrosoft'      => __( 'Microsoft Ads', 'utm-attribution-tracker' ),
			'platformTiktok'         => __( 'TikTok Ads', 'utm-attribution-tracker' ),
			'platformLinkedin'       => __( 'LinkedIn Ads', 'utm-attribution-tracker' ),
			'platformTwitter'        => __( 'X / Twitter Ads', 'utm-attribution-tracker' ),
			'vsPrevious'             => __( 'vs previous period', 'utm-attribution-tracker' ),
			'conversionsSeries'      => __( 'Conversions', 'utm-attribution-tracker' ),
			'previousPeriodSeries'   => __( 'Previous period', 'utm-attribution-tracker' ),
			/* translators: %s: name of the detected page cache plugin. */
			'cacheDetected'          => __( 'Detected %s. Tracking still works correctly on cached pages via the front-end beacon.', 'utm-attribution-tracker' ),
			'deviceDesktop'          => __( 'Desktop', 'utm-attribution-tracker' ),
			'deviceMobile'           => __( 'Mobile', 'utm-attribution-tracker' ),
			'deviceTablet'           => __( 'Tablet', 'utm-attribution-tracker' ),
			'channels'               => TrafficSource::channel_labels(),
			'testEmailSending'       => __( 'Sending…', 'utm-attribution-tracker' ),
			'testEmailSent'          => __( 'Test email sent.', 'utm-attribution-tracker' ),
			'testEmailFailed'        => __( 'Sending failed — check your mail configuration.', 'utm-attribution-tracker' ),
			'diagnosticsRunning'     => __( 'Running…', 'utm-attribution-tracker' ),
			'diagnosticsFailed'      => __( 'Could not run diagnostics.', 'utm-attribution-tracker' ),
			'diagStatusPass'         => __( 'Passed', 'utm-attribution-tracker' ),
			'diagStatusWarn'         => __( 'Warning', 'utm-attribution-tracker' ),
			'diagStatusFail'         => __( 'Failed', 'utm-attribution-tracker' ),
			'diagStatusSkip'         => __( 'Skipped', 'utm-attribution-tracker' ),
			'diagFormComplete'       => __( 'Covered', 'utm-attribution-tracker' ),
			'diagFormPartial'        => __( 'Partial', 'utm-attribution-tracker' ),
			'diagFormNone'           => __( 'No fields', 'utm-attribution-tracker' ),
			'diagNone'               => __( '—', 'utm-attribution-tracker' ),
			'drawerAddTitle'         => __( 'Add tracked link', 'utm-attribution-tracker' ),
			'drawerEditTitle'        => __( 'Edit tracked link', 'utm-attribution-tracker' ),
			'drawerBuildTitle'       => __( 'Build a tagged URL', 'utm-attribution-tracker' ),
			'copyTaggedUrl'          => __( 'Copy tagged URL', 'utm-attribution-tracker' ),
			'copyLink'               => __( 'Copy link', 'utm-attribution-tracker' ),
			'copyShortLink'          => __( 'Copy short link', 'utm-attribution-tracker' ),
			'copyDestination'        => __( 'Copy destination URL', 'utm-attribution-tracker' ),
			/* translators: %s: tracked link label. */
			'selectLink'             => __( 'Select %s', 'utm-attribution-tracker' ),
			/* translators: %s: tracked link label. */
			'qrFor'                  => __( 'QR code for %s', 'utm-attribution-tracker' ),
			/* translators: %s: tracked link label. */
			'editLink'               => __( 'Edit %s', 'utm-attribution-tracker' ),
			/* translators: %s: tracked link label. */
			'duplicateLink'          => __( 'Duplicate %s', 'utm-attribution-tracker' ),
			/* translators: %s: tracked link label. */
			'copyLinkFor'            => __( 'Copy short link for %s', 'utm-attribution-tracker' ),
			/* translators: %s: tracked link label. */
			'deleteLink'             => __( 'Delete %s', 'utm-attribution-tracker' ),
			'close'                  => __( 'Close', 'utm-attribution-tracker' ),
			'showDetails'            => __( 'Show more details', 'utm-attribution-tracker' ),
			/* translators: 1: number of tracked links shown, 2: their total all-time clicks. */
			'linksCount'             => __( 'Links: %1$s · Clicks (all time): %2$s', 'utm-attribution-tracker' ),
			'shortlinkPending'       => __( 'Created from the label when you save.', 'utm-attribution-tracker' ),
			/* translators: %s: tracked link ID. */
			'shortcodeFor'           => __( 'Shortcode: [utmatra_link id="%s"]', 'utm-attribution-tracker' ),
			'shortcodeAfterSave'     => __( 'After saving, embed it with the [utmatra_link] shortcode.', 'utm-attribution-tracker' ),
			'previewEmpty'           => __( 'Enter a destination URL to see the final link.', 'utm-attribution-tracker' ),
			/* translators: %s: conversion rate as a percentage, for example 2.0%. */
			'convRate'               => __( '%s of clicks', 'utm-attribution-tracker' ),
			/* translators: %s: number of orders. */
			'revenueMeta'            => __( 'From %s orders on tagged campaigns', 'utm-attribution-tracker' ),
			/* translators: 1: number of clicks, 2: share of all clicks as a percentage. */
			'topShare'               => __( '%1$s clicks · %2$s of total', 'utm-attribution-tracker' ),
			'tileNoClicks'           => __( 'No clicks yet', 'utm-attribution-tracker' ),
			'tileWooHint'            => __( 'Appears with WooCommerce orders', 'utm-attribution-tracker' ),
			'tileConvHint'           => __( 'Appears with WooCommerce orders and form leads', 'utm-attribution-tracker' ),
			'tileWaiting'            => __( 'Waiting for data', 'utm-attribution-tracker' ),
			'tileNoData'             => __( 'No tagged clicks in this period', 'utm-attribution-tracker' ),
			/* translators: %s: number of days in the selected period. */
			'compareDays'            => __( 'Compared with the previous %s days', 'utm-attribution-tracker' ),
			'compareRange'           => __( 'Compared with the previous period of the same length', 'utm-attribution-tracker' ),
			'unsavedNone'            => __( 'No unsaved changes', 'utm-attribution-tracker' ),
			/* translators: %s: number of changed settings. */
			'unsavedCount'           => __( 'Unsaved changes: %s', 'utm-attribution-tracker' ),
			/* translators: %s: time the diagnostics last ran. */
			'diagLastRun'            => __( 'Last run at %s', 'utm-attribution-tracker' ),
			/* translators: 1: order number, 2: formatted order total. */
			'journeyOrder'           => __( 'Order #%1$s · %2$s', 'utm-attribution-tracker' ),
			/* translators: %s: formatted order total. */
			'journeyOrderNoNumber'   => __( 'Order · %s', 'utm-attribution-tracker' ),
			/* translators: 1: form title, 2: integration name. */
			'journeyLead'            => __( 'Lead · %1$s · %2$s', 'utm-attribution-tracker' ),
			'kindOrder'              => __( 'Order', 'utm-attribution-tracker' ),
			'kindLead'               => __( 'Lead', 'utm-attribution-tracker' ),
			'kindClick'              => __( 'Click', 'utm-attribution-tracker' ),
			'kindVisit'              => __( 'Visit', 'utm-attribution-tracker' ),
			'showTouches'            => __( 'Show touches', 'utm-attribution-tracker' ),
			'hideTouches'            => __( 'Hide touches', 'utm-attribution-tracker' ),
			'touchesTruncated'       => __( 'Showing the most recent 100 touches.', 'utm-attribution-tracker' ),
			'journeysFailed'         => __( 'Could not load journeys.', 'utm-attribution-tracker' ),
			/* translators: %s: number of conversions without a visitor id. */
			'journeysWithoutVisitor' => __( 'Conversions without a visitor id (not shown): %s', 'utm-attribution-tracker' ),
			'integrationLabels'      => DashboardWidget::INTEGRATION_LABELS,
		)
	);
	?>
	</script>

</div><!-- /.utmatra-wrap -->
