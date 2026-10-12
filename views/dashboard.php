<?php
/**
 * Dashboard view
 *
 * Tabbed admin page (Overview, Links, Settings, Diagnostics) rendered by
 * Dashboard::render_page(). All data is loaded by assets/js/dashboard.js
 * through the utmat/v1 REST routes.
 *
 * @since 1.0.0
 * @since 1.0.0 Added the Consent group with its server-rendered WP Consent API status hint.
 *
 * @package UTMAT\Admin
 */

use UTMAT\Tracking\Attribution;
use UTMAT\Tracking\TrafficSource;

defined( 'ABSPATH' ) || exit;

// Tags allowed inside translated strings that carry inline code or links.
$utmat_kses = array(
	'code' => array(),
	'a'    => array(
		'href'                       => array(),
		'data-utmat-run-diagnostics' => array(),
	),
);
?>
<div class="wrap utmat-wrap" id="utmat-dashboard">

	<div class="utmat-header">
		<div class="utmat-header__titles">
			<h1><?php esc_html_e( 'UTM & Attribution Tracker', 'utm-attribution-tracker' ); ?></h1>
			<p class="utmat-header__sub"><?php esc_html_e( 'Campaign clicks, conversions and first/last-touch attribution, kept on your own site.', 'utm-attribution-tracker' ); ?></p>
		</div>
		<div class="utmat-header__actions">
			<button type="button" class="button" id="utmat-export-btn"
				title="<?php esc_attr_e( 'Download the raw events for the selected period as CSV (up to 10,000 rows).', 'utm-attribution-tracker' ); ?>"><?php esc_html_e( 'Export CSV', 'utm-attribution-tracker' ); ?></button>
			<button type="button" class="button button-primary" id="utmat-add-link-btn"><?php esc_html_e( 'Add tracked link', 'utm-attribution-tracker' ); ?></button>
		</div>
	</div>

	<hr class="wp-header-end" />

	<div class="nav-tab-wrapper utmat-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Dashboard sections', 'utm-attribution-tracker' ); ?>">
		<a href="#overview" class="nav-tab nav-tab-active" id="utmat-tab-overview" role="tab" aria-selected="true" aria-controls="utmat-panel-overview" tabindex="0"><?php esc_html_e( 'Overview', 'utm-attribution-tracker' ); ?></a>
		<a href="#links" class="nav-tab" id="utmat-tab-links" role="tab" aria-selected="false" aria-controls="utmat-panel-links" tabindex="-1"><?php esc_html_e( 'Links', 'utm-attribution-tracker' ); ?></a>
		<a href="#settings" class="nav-tab" id="utmat-tab-settings" role="tab" aria-selected="false" aria-controls="utmat-panel-settings" tabindex="-1"><?php esc_html_e( 'Settings', 'utm-attribution-tracker' ); ?></a>
		<a href="#diagnostics" class="nav-tab" id="utmat-tab-diagnostics" role="tab" aria-selected="false" aria-controls="utmat-panel-diagnostics" tabindex="-1"><?php esc_html_e( 'Diagnostics', 'utm-attribution-tracker' ); ?></a>
	</div>

	<!-- Error state -->
	<div id="utmat-error" class="notice notice-error" hidden>
		<p id="utmat-error-msg"></p>
	</div>

	<!-- Screen reader announcements for copy actions -->
	<div id="utmat-live" class="screen-reader-text" role="status" aria-live="polite"></div>

	<!-- Overview -->
	<div class="utmat-panel" id="utmat-panel-overview" role="tabpanel" aria-labelledby="utmat-tab-overview" tabindex="0">

		<div class="utmat-filters" id="utmat-filters">
			<div class="utmat-period-btns utmat-segmented" role="group" aria-label="<?php esc_attr_e( 'Date range', 'utm-attribution-tracker' ); ?>">
				<button type="button" class="utmat-period-btn active" data-period="7" aria-pressed="true"><?php esc_html_e( '7 days', 'utm-attribution-tracker' ); ?></button>
				<button type="button" class="utmat-period-btn" data-period="30" aria-pressed="false"><?php esc_html_e( '30 days', 'utm-attribution-tracker' ); ?></button>
				<button type="button" class="utmat-period-btn" data-period="90" aria-pressed="false"><?php esc_html_e( '90 days', 'utm-attribution-tracker' ); ?></button>
			</div>

			<div class="utmat-daterange">
				<label for="utmat-date-since" class="utmat-filters__label"><?php esc_html_e( 'Custom', 'utm-attribution-tracker' ); ?></label>
				<input type="date" id="utmat-date-since" />
				<span aria-hidden="true"><?php esc_html_e( 'to', 'utm-attribution-tracker' ); ?></span>
				<label for="utmat-date-until" class="screen-reader-text"><?php esc_html_e( 'Custom range end date', 'utm-attribution-tracker' ); ?></label>
				<input type="date" id="utmat-date-until" />
				<button type="button" class="button" id="utmat-date-apply"><?php esc_html_e( 'Apply', 'utm-attribution-tracker' ); ?></button>
				<button type="button" class="button-link" id="utmat-date-clear" hidden><?php esc_html_e( 'Clear', 'utm-attribution-tracker' ); ?></button>
			</div>

			<div class="utmat-filters__campaign">
				<label for="utmat-campaign-filter" class="utmat-filters__label"><?php esc_html_e( 'Campaign', 'utm-attribution-tracker' ); ?></label>
				<select id="utmat-campaign-filter">
					<option value=""><?php esc_html_e( 'All campaigns', 'utm-attribution-tracker' ); ?></option>
				</select>
			</div>

			<p class="utmat-filters__caption" id="utmat-compare-caption"></p>
		</div>

		<!-- Loading state -->
		<div id="utmat-loading" class="utmat-loading" aria-live="polite">
			<span class="spinner is-active"></span>
			<p><?php esc_html_e( 'Loading data…', 'utm-attribution-tracker' ); ?></p>
		</div>

		<div class="utmat-tiles" id="utmat-tiles">
			<div class="utmat-tile" id="utmat-tile-clicks">
				<p class="utmat-tile__label"><?php esc_html_e( 'Total clicks', 'utm-attribution-tracker' ); ?></p>
				<p class="utmat-tile__value" id="utmat-total-count" aria-live="polite">—</p>
				<p class="utmat-tile__meta">
					<span class="utmat-trend" id="utmat-trend" hidden></span>
					<span id="utmat-clicks-meta"></span>
				</p>
			</div>
			<div class="utmat-tile" id="utmat-tile-conversions" hidden>
				<p class="utmat-tile__label"><?php esc_html_e( 'Conversions', 'utm-attribution-tracker' ); ?></p>
				<p class="utmat-tile__value" id="utmat-conv-count">—</p>
				<p class="utmat-tile__meta" id="utmat-conv"></p>
			</div>
			<div class="utmat-tile" id="utmat-tile-revenue" hidden>
				<p class="utmat-tile__label"><?php esc_html_e( 'Attributed revenue', 'utm-attribution-tracker' ); ?></p>
				<p class="utmat-tile__value" id="utmat-revenue-total">—</p>
				<p class="utmat-tile__meta" id="utmat-revenue-meta"></p>
			</div>
			<div class="utmat-tile utmat-tile--text" id="utmat-tile-source">
				<p class="utmat-tile__label"><?php esc_html_e( 'Top source', 'utm-attribution-tracker' ); ?></p>
				<p class="utmat-tile__value" id="utmat-top-source">—</p>
				<p class="utmat-tile__meta" id="utmat-top-source-meta"></p>
			</div>
			<div class="utmat-tile utmat-tile--text" id="utmat-tile-campaign">
				<p class="utmat-tile__label"><?php esc_html_e( 'Top campaign', 'utm-attribution-tracker' ); ?></p>
				<p class="utmat-tile__value" id="utmat-top-campaign">—</p>
				<p class="utmat-tile__meta" id="utmat-top-campaign-meta"></p>
			</div>
		</div>

		<!-- First-run onboarding, shown by JS when no events have ever been recorded -->
		<div class="utmat-onboarding" id="utmat-onboarding" hidden>
			<div class="utmat-onboarding__main">
				<div>
					<h2><?php esc_html_e( 'Get your first tracked click', 'utm-attribution-tracker' ); ?></h2>
					<p class="utmat-onboarding__lead"><?php esc_html_e( 'Tracking is already on. Nothing has been recorded yet because no tagged visit has arrived.', 'utm-attribution-tracker' ); ?></p>
				</div>
				<ol class="utmat-steps">
					<li class="utmat-step">
						<span class="utmat-step__num" aria-hidden="true">1</span>
						<div>
							<h3><?php esc_html_e( 'Open a tagged URL in a private window', 'utm-attribution-tracker' ); ?></h3>
							<p><?php esc_html_e( 'Visits from logged-in administrators are not tracked, so use a private window or another browser.', 'utm-attribution-tracker' ); ?></p>
							<div class="utmat-step__copy">
								<code class="utmat-code" id="utmat-onboarding-url"><?php echo esc_html( home_url( '/' ) . '?utm_source=newsletter&utm_medium=email&utm_campaign=launch' ); ?></code>
								<button type="button" class="button button-small" id="utmat-onboarding-copy"><?php esc_html_e( 'Copy', 'utm-attribution-tracker' ); ?></button>
							</div>
						</div>
					</li>
					<li class="utmat-step">
						<span class="utmat-step__num" aria-hidden="true">2</span>
						<div>
							<h3><?php esc_html_e( 'Or create a tracked short link', 'utm-attribution-tracker' ); ?></h3>
							<p><?php esc_html_e( 'A tracked link counts the click before forwarding the visitor, and comes with a QR code.', 'utm-attribution-tracker' ); ?></p>
							<p class="utmat-step__action">
								<button type="button" class="button" id="utmat-onboarding-add"><?php esc_html_e( 'Create a tracked link', 'utm-attribution-tracker' ); ?></button>
							</p>
						</div>
					</li>
					<li class="utmat-step">
						<span class="utmat-step__num" aria-hidden="true">3</span>
						<div>
							<h3><?php esc_html_e( 'Come back in a minute', 'utm-attribution-tracker' ); ?></h3>
							<p><?php esc_html_e( 'Ad clicks carrying gclid, fbclid, msclkid, ttclid, li_fat_id or twclid are tracked automatically, even without UTM parameters. Bots and repeated reloads are filtered out.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</li>
				</ol>
			</div>
			<div class="utmat-onboarding__aside">
				<div>
					<h3><?php esc_html_e( 'Already sent traffic?', 'utm-attribution-tracker' ); ?></h3>
					<p><?php esc_html_e( 'If tagged visits should have shown up, a page cache or consent plugin is the usual reason.', 'utm-attribution-tracker' ); ?></p>
				</div>
				<a class="button" href="#diagnostics" data-utmat-run-diagnostics="1"><?php esc_html_e( 'Run diagnostics', 'utm-attribution-tracker' ); ?></a>
				<div class="utmat-onboarding__tracked">
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

		<div class="utmat-placeholder" id="utmat-charts-placeholder" hidden>
			<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false"><path d="M3 3v18h18"></path><path d="M7 15l4-5 4 3 5-7"></path></svg>
			<p><?php esc_html_e( 'Clicks over time, traffic breakdown, reach and revenue charts appear here after the first click.', 'utm-attribution-tracker' ); ?></p>
		</div>

		<div class="utmat-charts" id="utmat-charts" hidden>

			<div class="utmat-card utmat-card--chart">
				<div class="utmat-card__header">
					<h3 class="utmat-card__title"><?php esc_html_e( 'Clicks over time', 'utm-attribution-tracker' ); ?></h3>
				</div>
				<div class="utmat-card__body utmat-card__body--chart">
					<canvas id="utmat-chart-line" role="img"
						aria-label="<?php esc_attr_e( 'Clicks over time line chart', 'utm-attribution-tracker' ); ?>"></canvas>
					<p class="description utmat-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
				</div>
			</div>

			<section class="utmat-group" aria-labelledby="utmat-group-traffic">
				<div class="utmat-group__header">
					<h2 class="utmat-group__title" id="utmat-group-traffic"><?php esc_html_e( 'Traffic breakdown', 'utm-attribution-tracker' ); ?></h2>
					<p class="utmat-group__desc"><?php esc_html_e( 'Where the clicks in this period came from', 'utm-attribution-tracker' ); ?></p>
				</div>
				<div class="utmat-group__grid utmat-group__grid--4">
					<div class="utmat-card utmat-card--bars">
						<div class="utmat-card__header">
							<h3 class="utmat-card__title" id="utmat-title-source"><?php esc_html_e( 'By source', 'utm-attribution-tracker' ); ?></h3>
							<span class="utmat-card__caption"><?php esc_html_e( 'clicks', 'utm-attribution-tracker' ); ?></span>
						</div>
						<div class="utmat-card__body">
							<ol class="utmat-bars" id="utmat-bars-source" aria-labelledby="utmat-title-source"></ol>
							<p class="description utmat-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmat-card utmat-card--bars">
						<div class="utmat-card__header">
							<h3 class="utmat-card__title" id="utmat-title-campaign"><?php esc_html_e( 'By campaign', 'utm-attribution-tracker' ); ?></h3>
							<span class="utmat-card__caption"><?php esc_html_e( 'clicks', 'utm-attribution-tracker' ); ?></span>
						</div>
						<div class="utmat-card__body">
							<ol class="utmat-bars" id="utmat-bars-campaign" aria-labelledby="utmat-title-campaign"></ol>
							<p class="description utmat-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmat-card utmat-card--bars">
						<div class="utmat-card__header">
							<h3 class="utmat-card__title" id="utmat-title-medium"><?php esc_html_e( 'By medium', 'utm-attribution-tracker' ); ?></h3>
							<span class="utmat-card__caption"><?php esc_html_e( 'clicks', 'utm-attribution-tracker' ); ?></span>
						</div>
						<div class="utmat-card__body">
							<ol class="utmat-bars" id="utmat-bars-medium" aria-labelledby="utmat-title-medium"></ol>
							<p class="description utmat-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmat-card utmat-card--bars">
						<div class="utmat-card__header">
							<h3 class="utmat-card__title" id="utmat-title-device"><?php esc_html_e( 'By device', 'utm-attribution-tracker' ); ?></h3>
							<span class="utmat-card__caption"><?php esc_html_e( 'share', 'utm-attribution-tracker' ); ?></span>
						</div>
						<div class="utmat-card__body">
							<ol class="utmat-bars" id="utmat-bars-device" aria-labelledby="utmat-title-device"></ol>
							<p class="description utmat-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
				</div>
			</section>

			<section class="utmat-group" aria-labelledby="utmat-group-reach">
				<div class="utmat-group__header">
					<h2 class="utmat-group__title" id="utmat-group-reach"><?php esc_html_e( 'Reach', 'utm-attribution-tracker' ); ?></h2>
					<p class="utmat-group__desc"><?php esc_html_e( 'Channels, landing pages, referrers and ad platforms', 'utm-attribution-tracker' ); ?></p>
				</div>
				<div class="utmat-group__grid utmat-group__grid--4">
					<div class="utmat-card utmat-card--bars">
						<div class="utmat-card__header">
							<h3 class="utmat-card__title" id="utmat-title-channel"><?php esc_html_e( 'Channels', 'utm-attribution-tracker' ); ?></h3>
							<span class="utmat-card__caption"><?php esc_html_e( 'visits', 'utm-attribution-tracker' ); ?></span>
						</div>
						<div class="utmat-card__body">
							<ol class="utmat-bars" id="utmat-bars-channel" aria-labelledby="utmat-title-channel"></ol>
							<p class="description utmat-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
							<p class="utmat-field__hint" id="utmat-channel-hint" hidden><?php esc_html_e( 'Untagged visit tracking is off, so only tagged traffic is classified. Turn it on in Settings to see direct, organic, social, AI-assistant and referral visits.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmat-card utmat-card--bars">
						<div class="utmat-card__header">
							<h3 class="utmat-card__title" id="utmat-title-landing"><?php esc_html_e( 'Top landing pages', 'utm-attribution-tracker' ); ?></h3>
							<span class="utmat-card__caption"><?php esc_html_e( 'clicks', 'utm-attribution-tracker' ); ?></span>
						</div>
						<div class="utmat-card__body">
							<ol class="utmat-bars" id="utmat-bars-landing" aria-labelledby="utmat-title-landing"></ol>
							<p class="description utmat-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmat-card utmat-card--bars">
						<div class="utmat-card__header">
							<h3 class="utmat-card__title" id="utmat-title-referrer"><?php esc_html_e( 'Top referrers', 'utm-attribution-tracker' ); ?></h3>
							<span class="utmat-card__caption"><?php esc_html_e( 'clicks', 'utm-attribution-tracker' ); ?></span>
						</div>
						<div class="utmat-card__body">
							<ol class="utmat-bars" id="utmat-bars-referrer" aria-labelledby="utmat-title-referrer"></ol>
							<p class="description utmat-nodata" hidden><?php esc_html_e( 'No external referrers recorded in this period.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmat-card utmat-card--bars">
						<div class="utmat-card__header">
							<h3 class="utmat-card__title" id="utmat-title-platform"><?php esc_html_e( 'Ad platforms', 'utm-attribution-tracker' ); ?></h3>
							<span class="utmat-card__caption"><?php esc_html_e( 'click IDs', 'utm-attribution-tracker' ); ?></span>
						</div>
						<div class="utmat-card__body">
							<ol class="utmat-bars" id="utmat-bars-platform" aria-labelledby="utmat-title-platform"></ol>
							<p class="description utmat-nodata" hidden><?php esc_html_e( 'No ad click IDs recorded in this period.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
				</div>
			</section>

			<!-- Revenue, shown by JS only when WooCommerce is active -->
			<section class="utmat-group" id="utmat-card-revenue" aria-labelledby="utmat-group-revenue" hidden>
				<div class="utmat-group__header">
					<h2 class="utmat-group__title" id="utmat-group-revenue"><?php esc_html_e( 'Revenue', 'utm-attribution-tracker' ); ?></h2>
					<p class="utmat-group__desc"><?php esc_html_e( 'WooCommerce orders attributed to tagged campaigns', 'utm-attribution-tracker' ); ?></p>
				</div>
				<div class="utmat-card">
					<div class="utmat-card__header">
						<h3 class="utmat-card__title"><?php esc_html_e( 'Revenue by campaign', 'utm-attribution-tracker' ); ?></h3>
						<span class="utmat-card__caption"><?php esc_html_e( 'Tagged campaigns', 'utm-attribution-tracker' ); ?></span>
					</div>
					<div class="utmat-revenue">
						<div class="utmat-card__body utmat-card__body--chart utmat-revenue__chart">
							<canvas id="utmat-chart-revenue" role="img"
								aria-label="<?php esc_attr_e( 'Revenue by campaign bar chart', 'utm-attribution-tracker' ); ?>"></canvas>
							<p class="description utmat-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
						</div>
						<dl class="utmat-revenue__summary" id="utmat-revenue-summary">
							<dt><?php esc_html_e( 'Orders', 'utm-attribution-tracker' ); ?></dt>
							<dd id="utmat-revenue-orders">—</dd>
							<dt><?php esc_html_e( 'Average order', 'utm-attribution-tracker' ); ?></dt>
							<dd id="utmat-revenue-aov">—</dd>
						</dl>
					</div>
				</div>
			</section>

		</div><!-- /.utmat-charts -->

		<p class="utmat-footnote">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: link to the Settings tab. */
					__( 'Logged-in administrators are not tracked, and bots and repeated reloads are filtered out automatically. Data retention is set under %s.', 'utm-attribution-tracker' ),
					'<a href="#settings">' . esc_html__( 'Settings', 'utm-attribution-tracker' ) . '</a>'
				),
				$utmat_kses
			);
			?>
		</p>
	</div><!-- /#utmat-panel-overview -->

	<!-- Links -->
	<div class="utmat-panel" id="utmat-panel-links" role="tabpanel" aria-labelledby="utmat-tab-links" tabindex="0" hidden>
		<div class="utmat-section utmat-links" id="utmat-links-section" hidden>
			<p class="utmat-section__desc"><?php esc_html_e( 'Use these redirect URLs in emails, ads, and banners. The click is counted before the visitor is forwarded to the destination.', 'utm-attribution-tracker' ); ?></p>

			<div class="utmat-links-toolbar">
				<label for="utmat-links-filter" class="screen-reader-text"><?php esc_html_e( 'Search tracked links', 'utm-attribution-tracker' ); ?></label>
				<input type="search" id="utmat-links-filter" class="utmat-links-toolbar__search"
					placeholder="<?php esc_attr_e( 'Search by label, campaign, source, or medium…', 'utm-attribution-tracker' ); ?>" />
				<label for="utmat-links-campaign" class="screen-reader-text"><?php esc_html_e( 'Filter links by campaign', 'utm-attribution-tracker' ); ?></label>
				<select id="utmat-links-campaign">
					<option value=""><?php esc_html_e( 'All campaigns', 'utm-attribution-tracker' ); ?></option>
				</select>
				<span class="utmat-links-toolbar__count" id="utmat-links-count"></span>
				<span class="utmat-add-form__msg" id="utmat-links-msg" role="status"></span>
				<div class="utmat-links-toolbar__bulk">
					<button type="button" class="button utmat-bulk-delete-btn" id="utmat-bulk-delete-btn" hidden><?php esc_html_e( 'Delete selected', 'utm-attribution-tracker' ); ?></button>
				</div>
			</div>

			<table class="wp-list-table widefat fixed striped utmat-links-table" id="utmat-links-table">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<label for="utmat-select-all" class="screen-reader-text"><?php esc_html_e( 'Select all', 'utm-attribution-tracker' ); ?></label>
							<input type="checkbox" id="utmat-select-all" />
						</td>
						<th scope="col" class="utmat-col-label column-primary"><?php esc_html_e( 'Label', 'utm-attribution-tracker' ); ?></th>
						<th scope="col" class="utmat-col-campaign"><?php esc_html_e( 'Campaign', 'utm-attribution-tracker' ); ?></th>
						<th scope="col" class="utmat-col-source"><?php esc_html_e( 'Source / medium', 'utm-attribution-tracker' ); ?></th>
						<th scope="col" class="utmat-col-clicks" aria-sort="none">
							<button type="button" class="button-link utmat-col-sortable" id="utmat-sort-clicks"><?php esc_html_e( 'Clicks', 'utm-attribution-tracker' ); ?> <span class="utmat-sort-arrow" aria-hidden="true"></span></button>
						</th>
						<th scope="col" class="utmat-col-url utmat-col-shortlink"><?php esc_html_e( 'Short link', 'utm-attribution-tracker' ); ?></th>
						<th scope="col" class="utmat-col-url utmat-col-destination"><?php esc_html_e( 'Destination', 'utm-attribution-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody id="utmat-links-tbody">
					<!-- Populated by JS -->
				</tbody>
			</table>

			<p class="utmat-footnote"><?php esc_html_e( 'Hover over or tab to a row for Edit, Duplicate, QR code, Copy link and Delete. Click counts are all time.', 'utm-attribution-tracker' ); ?></p>
		</div>
	</div><!-- /#utmat-panel-links -->

	<!-- Settings -->
	<div class="utmat-panel" id="utmat-panel-settings" role="tabpanel" aria-labelledby="utmat-tab-settings" tabindex="0" hidden>

		<!-- Shown by JS when a known full-page cache plugin is detected -->
		<div class="notice notice-info inline utmat-cache-notice" id="utmat-cache-notice" hidden>
			<p></p>
		</div>

		<form id="utmat-settings-form" class="utmat-settings-form">

			<section class="utmat-settings-section" aria-labelledby="utmat-settings-tracking">
				<div class="utmat-settings-section__intro">
					<h2 id="utmat-settings-tracking"><?php esc_html_e( 'Tracking', 'utm-attribution-tracker' ); ?></h2>
					<p><?php esc_html_e( 'What the beacon records on each visit.', 'utm-attribution-tracker' ); ?></p>
				</div>
				<div class="utmat-card utmat-settings-card">
					<div class="utmat-field">
						<span class="utmat-field__label"><?php esc_html_e( 'Untagged visits', 'utm-attribution-tracker' ); ?></span>
						<div class="utmat-field__control">
							<label class="utmat-switch" for="utmat-track-visits">
								<input type="checkbox" id="utmat-track-visits" name="track_visits" class="utmat-switch__input" />
								<span class="utmat-switch__track" aria-hidden="true"></span>
								<span class="utmat-switch__text"><?php esc_html_e( 'Track the first page of every visit', 'utm-attribution-tracker' ); ?></span>
							</label>
							<p class="utmat-field__hint"><?php esc_html_e( 'Records the landing page and referrer of visits that arrive without campaign parameters, so organic, social, AI-assistant and referral traffic appear in the Channels report. On by default; about one event per visit.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
				</div>
			</section>

			<section class="utmat-settings-section" aria-labelledby="utmat-settings-privacy">
				<div class="utmat-settings-section__intro">
					<h2 id="utmat-settings-privacy"><?php esc_html_e( 'Data and privacy', 'utm-attribution-tracker' ); ?></h2>
					<p><?php esc_html_e( 'How long events are kept and how long a visitor stays attributed to a campaign.', 'utm-attribution-tracker' ); ?></p>
				</div>
				<div class="utmat-card utmat-settings-card">
					<div class="utmat-field">
						<label class="utmat-field__label" for="utmat-retention-days"><?php esc_html_e( 'Keep events for', 'utm-attribution-tracker' ); ?></label>
						<div class="utmat-field__control">
							<div class="utmat-inline">
								<input type="number" id="utmat-retention-days" name="retention_days" min="0" step="1" class="small-text" />
								<span><?php esc_html_e( 'days', 'utm-attribution-tracker' ); ?></span>
							</div>
							<p class="utmat-field__hint"><?php esc_html_e( 'Events older than this are deleted daily. 0 keeps events forever.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmat-field">
						<label class="utmat-field__label" for="utmat-cookie-days"><?php esc_html_e( 'Attribution window', 'utm-attribution-tracker' ); ?></label>
						<div class="utmat-field__control">
							<div class="utmat-inline">
								<input type="number" id="utmat-cookie-days" name="cookie_days" min="1" max="730" step="1" class="small-text" />
								<span><?php esc_html_e( 'days', 'utm-attribution-tracker' ); ?></span>
							</div>
							<p class="utmat-field__hint"><?php esc_html_e( 'How long the first/last-touch attribution cookie lives. Longer windows attribute slower-converting visitors.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmat-settings-card__group">
						<h3 class="utmat-card__title"><?php esc_html_e( 'Consent', 'utm-attribution-tracker' ); ?></h3>
						<div class="utmat-field">
							<label class="utmat-field__label" for="utmat-consent-category"><?php esc_html_e( 'Consent category', 'utm-attribution-tracker' ); ?></label>
							<div class="utmat-field__control">
								<select id="utmat-consent-category" name="consent_category">
									<option value="marketing"><?php esc_html_e( 'Marketing (recommended)', 'utm-attribution-tracker' ); ?></option>
									<option value="statistics"><?php esc_html_e( 'Statistics', 'utm-attribution-tracker' ); ?></option>
									<option value="either"><?php esc_html_e( 'Marketing or statistics', 'utm-attribution-tracker' ); ?></option>
								</select>
								<p class="utmat-field__hint"><?php esc_html_e( 'The attribution cookie is written only after the visitor grants this consent category. Applies when a plugin implementing the WP Consent API is active.', 'utm-attribution-tracker' ); ?></p>
								<p class="utmat-field__hint" id="utmat-consent-status">
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

			<section class="utmat-settings-section" aria-labelledby="utmat-settings-report">
				<div class="utmat-settings-section__intro">
					<h2 id="utmat-settings-report"><?php esc_html_e( 'Weekly report', 'utm-attribution-tracker' ); ?></h2>
					<p><?php esc_html_e( 'A short summary email once a week with clicks, top campaigns, top sources and, with WooCommerce, revenue.', 'utm-attribution-tracker' ); ?></p>
				</div>
				<div class="utmat-card utmat-settings-card">
					<div class="utmat-field">
						<span class="utmat-field__label"><?php esc_html_e( 'Weekly email', 'utm-attribution-tracker' ); ?></span>
						<div class="utmat-field__control">
							<label class="utmat-switch" for="utmat-weekly-email">
								<input type="checkbox" id="utmat-weekly-email" name="weekly_email" class="utmat-switch__input" />
								<span class="utmat-switch__track" aria-hidden="true"></span>
								<span class="utmat-switch__text"><?php esc_html_e( 'Send a weekly campaign summary email', 'utm-attribution-tracker' ); ?></span>
							</label>
							<p class="utmat-field__hint"><?php esc_html_e( 'Sent through wp_mail(), so your SMTP plugin applies. Off by default.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmat-field">
						<span class="utmat-field__label"><?php esc_html_e( 'Recipient', 'utm-attribution-tracker' ); ?></span>
						<div class="utmat-field__control">
							<p class="utmat-readonly" id="utmat-report-recipient"><?php echo esc_html( get_option( 'admin_email' ) ); ?></p>
							<p class="utmat-field__hint">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: name of the filter that changes the report recipient. */
										__( 'The site admin email. Developers can change the recipient with the %s filter.', 'utm-attribution-tracker' ),
										'<code>utmat_report_recipient</code>'
									),
									$utmat_kses
								);
								?>
							</p>
						</div>
					</div>
					<div class="utmat-field utmat-field--actions">
						<span class="utmat-field__label" aria-hidden="true"></span>
						<div class="utmat-field__control utmat-inline">
							<button type="button" class="button" id="utmat-test-email"
								title="<?php esc_attr_e( 'Send the weekly summary to the report recipient now, regardless of the toggle.', 'utm-attribution-tracker' ); ?>"><?php esc_html_e( 'Send test email', 'utm-attribution-tracker' ); ?></button>
							<span class="utmat-add-form__msg" id="utmat-test-email-msg" role="status"></span>
						</div>
					</div>
				</div>
			</section>

			<section class="utmat-settings-section" aria-labelledby="utmat-settings-webhook">
				<div class="utmat-settings-section__intro">
					<h2 id="utmat-settings-webhook"><?php esc_html_e( 'Conversion webhook', 'utm-attribution-tracker' ); ?></h2>
					<p><?php esc_html_e( 'Send each attributed WooCommerce order, with its attribution data, to your CRM or automation tool.', 'utm-attribution-tracker' ); ?></p>
				</div>
				<div class="utmat-card utmat-settings-card">
					<div class="utmat-field">
						<label class="utmat-field__label" for="utmat-webhook-url"><?php esc_html_e( 'Webhook URL', 'utm-attribution-tracker' ); ?></label>
						<div class="utmat-field__control">
							<input type="url" id="utmat-webhook-url" name="webhook_url" class="regular-text" placeholder="https://example.com/webhooks/utm" />
							<p class="utmat-field__hint"><?php esc_html_e( 'POSTs a JSON payload here whenever a WooCommerce order is attributed. Leave blank to disable.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div id="utmat-webhook-secret-row" class="utmat-settings-card__group" hidden>
						<div class="utmat-field">
							<label class="utmat-field__label" for="utmat-webhook-secret"><?php esc_html_e( 'Signing secret', 'utm-attribution-tracker' ); ?></label>
							<div class="utmat-field__control">
								<div class="utmat-inline">
									<input type="text" id="utmat-webhook-secret" class="regular-text utmat-mono" readonly />
									<button type="button" class="button" id="utmat-webhook-secret-copy"><?php esc_html_e( 'Copy', 'utm-attribution-tracker' ); ?></button>
								</div>
								<p class="utmat-field__hint"><?php esc_html_e( 'Every request carries an X-UTMAT-Signature header (sha256=HMAC of the JSON body using this secret) so your receiver can verify it came from this site.', 'utm-attribution-tracker' ); ?></p>
							</div>
						</div>
						<div class="utmat-field">
							<span class="utmat-field__label"><?php esc_html_e( 'Last delivery', 'utm-attribution-tracker' ); ?></span>
							<div class="utmat-field__control utmat-inline">
								<span id="utmat-webhook-last" class="description"></span>
								<button type="button" class="button" id="utmat-test-webhook" hidden
									title="<?php esc_attr_e( 'Send a synthetic conversion payload to the configured webhook URL now.', 'utm-attribution-tracker' ); ?>"><?php esc_html_e( 'Send test webhook', 'utm-attribution-tracker' ); ?></button>
								<span class="utmat-add-form__msg" id="utmat-test-webhook-msg" role="status"></span>
							</div>
						</div>
					</div>
				</div>
			</section>

			<section class="utmat-settings-section utmat-settings-section--last" aria-labelledby="utmat-settings-embed">
				<div class="utmat-settings-section__intro">
					<h2 id="utmat-settings-embed"><?php esc_html_e( 'Embedding', 'utm-attribution-tracker' ); ?></h2>
					<p><?php esc_html_e( 'Put tracked links in content and pass attribution into forms.', 'utm-attribution-tracker' ); ?></p>
				</div>
				<div class="utmat-card utmat-settings-card">
					<div class="utmat-field">
						<span class="utmat-field__label"><?php esc_html_e( 'Shortcode', 'utm-attribution-tracker' ); ?></span>
						<div class="utmat-field__control">
							<code class="utmat-code">[utmat_link id=&quot;example-campaign&quot;]<?php esc_html_e( 'Click here', 'utm-attribution-tracker' ); ?>[/utmat_link]</code>
							<p class="utmat-field__hint"><?php esc_html_e( 'Enclosed text becomes the link text. You can also pass label="…", or leave both out to use the link’s label. Incoming visits that already carry UTM parameters are tracked automatically, no shortcode required.', 'utm-attribution-tracker' ); ?></p>
						</div>
					</div>
					<div class="utmat-field">
						<span class="utmat-field__label"><?php esc_html_e( 'Form fields', 'utm-attribution-tracker' ); ?></span>
						<div class="utmat-field__control">
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
									$utmat_kses
								);
								?>
							</p>
							<p class="utmat-field__hint">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: link to the Diagnostics tab. */
										__( 'Check which forms are covered under %s.', 'utm-attribution-tracker' ),
										'<a href="#diagnostics" data-utmat-run-diagnostics="1">' . esc_html__( 'Diagnostics', 'utm-attribution-tracker' ) . '</a>'
									),
									$utmat_kses
								);
								?>
							</p>
						</div>
					</div>
					<div class="utmat-field">
						<span class="utmat-field__label"><?php esc_html_e( 'Privacy policy', 'utm-attribution-tracker' ); ?></span>
						<div class="utmat-field__control">
							<p>
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: link to the Privacy Policy Guide screen. */
										__( 'Suggested wording is added to the %s.', 'utm-attribution-tracker' ),
										'<a href="' . esc_url( admin_url( 'privacy-policy-guide.php' ) ) . '">' . esc_html__( 'Privacy Policy Guide', 'utm-attribution-tracker' ) . '</a>'
									),
									$utmat_kses
								);
								?>
							</p>
						</div>
					</div>
				</div>
			</section>

			<div class="utmat-savebar" id="utmat-savebar">
				<span class="utmat-savebar__count" id="utmat-unsaved-count" aria-live="polite"></span>
				<span class="utmat-add-form__msg" id="utmat-settings-msg" role="status"></span>
				<div class="utmat-savebar__actions">
					<button type="button" class="button" id="utmat-settings-discard" disabled><?php esc_html_e( 'Discard', 'utm-attribution-tracker' ); ?></button>
					<button type="submit" class="button button-primary" id="utmat-settings-save"><?php esc_html_e( 'Save settings', 'utm-attribution-tracker' ); ?></button>
				</div>
			</div>
		</form>
	</div><!-- /#utmat-panel-settings -->

	<!-- Diagnostics -->
	<div class="utmat-panel" id="utmat-panel-diagnostics" role="tabpanel" aria-labelledby="utmat-tab-diagnostics" tabindex="0" hidden>
		<div class="utmat-diag-header">
			<div class="utmat-diag-header__text">
				<h2><?php esc_html_e( 'Tracking Doctor', 'utm-attribution-tracker' ); ?></h2>
				<p class="utmat-section__desc"><?php esc_html_e( 'Local checks on caching, consent, cookies, forms and WooCommerce attribution. Nothing is sent to any external service.', 'utm-attribution-tracker' ); ?></p>
			</div>
			<div class="utmat-diag-header__actions">
				<span class="utmat-diag-header__last" id="utmat-diagnostics-last"><?php esc_html_e( 'Not run yet', 'utm-attribution-tracker' ); ?></span>
				<span class="utmat-add-form__msg" id="utmat-diagnostics-msg" role="status"></span>
				<button type="button" class="button button-primary" id="utmat-run-diagnostics"><?php esc_html_e( 'Run diagnostics', 'utm-attribution-tracker' ); ?></button>
			</div>
		</div>

		<div class="utmat-placeholder" id="utmat-diagnostics-placeholder">
			<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false"><path d="M9 11l3 3 8-8"></path><path d="M20 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
			<p><?php esc_html_e( 'Run the checks to see whether caching, consent, cookies, forms and WooCommerce attribution are set up correctly.', 'utm-attribution-tracker' ); ?></p>
		</div>

		<div class="utmat-diag-summary" id="utmat-diag-summary" role="list" hidden>
			<div class="utmat-diag-summary__item is-pass" role="listitem">
				<span><?php esc_html_e( 'Passed', 'utm-attribution-tracker' ); ?></span>
				<strong id="utmat-diag-count-pass">0</strong>
			</div>
			<div class="utmat-diag-summary__item is-warn" role="listitem">
				<span><?php esc_html_e( 'Warnings', 'utm-attribution-tracker' ); ?></span>
				<strong id="utmat-diag-count-warn">0</strong>
			</div>
			<div class="utmat-diag-summary__item is-fail" role="listitem">
				<span><?php esc_html_e( 'Failed', 'utm-attribution-tracker' ); ?></span>
				<strong id="utmat-diag-count-fail">0</strong>
			</div>
			<div class="utmat-diag-summary__item is-skip" role="listitem">
				<span><?php esc_html_e( 'Skipped', 'utm-attribution-tracker' ); ?></span>
				<strong id="utmat-diag-count-skip">0</strong>
			</div>
		</div>

		<ul class="utmat-card utmat-diagnostics-list" id="utmat-diagnostics-list" hidden></ul>

		<!-- Forms coverage matrix, populated by JS only when the forms check has rows -->
		<div class="utmat-card utmat-diagnostics-forms" id="utmat-diagnostics-forms" hidden>
			<div class="utmat-card__header">
				<h3 class="utmat-card__title"><?php esc_html_e( 'Forms coverage', 'utm-attribution-tracker' ); ?></h3>
				<span class="utmat-card__caption"><?php esc_html_e( 'Attribution fields found on each form', 'utm-attribution-tracker' ); ?></span>
			</div>
			<div class="utmat-table-scroll">
				<table class="utmat-table" id="utmat-diagnostics-forms-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Form', 'utm-attribution-tracker' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Integration', 'utm-attribution-tracker' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'utm-attribution-tracker' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Present', 'utm-attribution-tracker' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Missing', 'utm-attribution-tracker' ); ?></th>
						</tr>
					</thead>
					<tbody id="utmat-diagnostics-forms-tbody">
						<!-- Populated by JS -->
					</tbody>
				</table>
			</div>
			<p class="description" id="utmat-diagnostics-unsupported" hidden></p>
		</div>
	</div><!-- /#utmat-panel-diagnostics -->

	<!-- Add / edit link drawer (outside the panels so loading never hides it) -->
	<div class="utmat-drawer-backdrop" id="utmat-drawer-backdrop" hidden></div>
	<aside class="utmat-drawer" id="utmat-drawer" role="dialog" aria-modal="true" aria-labelledby="utmat-drawer-title" tabindex="-1" hidden>
		<form class="utmat-add-form utmat-drawer__form" id="utmat-add-form">
			<div class="utmat-drawer__header">
				<h2 class="utmat-drawer__title" id="utmat-drawer-title"><?php esc_html_e( 'Add tracked link', 'utm-attribution-tracker' ); ?></h2>
				<button type="button" class="utmat-icon-btn utmat-drawer__close" id="utmat-drawer-close" aria-label="<?php esc_attr_e( 'Close', 'utm-attribution-tracker' ); ?>">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M18 6 6 18M6 6l12 12"></path></svg>
				</button>
			</div>

			<div class="utmat-drawer__body">
				<div class="utmat-drawer__field">
					<label for="utmat-new-label"><?php esc_html_e( 'Label', 'utm-attribution-tracker' ); ?></label>
					<input type="text" id="utmat-new-label" name="label" required
						placeholder="<?php esc_attr_e( 'Spring newsletter button', 'utm-attribution-tracker' ); ?>" />
					<p class="utmat-field__hint"><?php esc_html_e( 'Shown in this table and used as the default link text of the shortcode.', 'utm-attribution-tracker' ); ?></p>
				</div>

				<div class="utmat-drawer__field">
					<label for="utmat-new-destination"><?php esc_html_e( 'Destination URL', 'utm-attribution-tracker' ); ?></label>
					<input type="url" id="utmat-new-destination" name="destination" required
						placeholder="https://example.com/landing-page" />
					<p class="utmat-field__hint"><?php esc_html_e( 'Where visitors land. The UTM parameters below are added to it on redirect.', 'utm-attribution-tracker' ); ?></p>
				</div>

				<div class="utmat-presets-row">
					<label for="utmat-preset-select"><?php esc_html_e( 'Preset', 'utm-attribution-tracker' ); ?></label>
					<select id="utmat-preset-select">
						<option value=""><?php esc_html_e( 'None', 'utm-attribution-tracker' ); ?></option>
					</select>
					<button type="button" class="button-link" id="utmat-preset-delete" hidden><?php esc_html_e( 'Delete preset', 'utm-attribution-tracker' ); ?></button>
					<button type="button" class="button-link" id="utmat-preset-save"><?php esc_html_e( 'Save current as preset', 'utm-attribution-tracker' ); ?></button>
				</div>

				<!-- Shown by JS when "Save current as preset" is clicked -->
				<div class="utmat-preset-name-row" id="utmat-preset-name-row" hidden>
					<label for="utmat-preset-name" class="screen-reader-text"><?php esc_html_e( 'Preset name', 'utm-attribution-tracker' ); ?></label>
					<input type="text" id="utmat-preset-name" placeholder="<?php esc_attr_e( 'e.g. Instagram Story', 'utm-attribution-tracker' ); ?>" />
					<button type="button" class="button button-primary" id="utmat-preset-name-confirm"><?php esc_html_e( 'Save', 'utm-attribution-tracker' ); ?></button>
					<button type="button" class="button" id="utmat-preset-name-cancel"><?php esc_html_e( 'Cancel', 'utm-attribution-tracker' ); ?></button>
				</div>

				<div class="utmat-drawer__grid">
					<div class="utmat-drawer__field">
						<label for="utmat-new-campaign"><?php esc_html_e( 'Campaign', 'utm-attribution-tracker' ); ?></label>
						<input type="text" id="utmat-new-campaign" name="utm_campaign" list="utmat-list-campaign" placeholder="spring-sale" />
					</div>
					<div class="utmat-drawer__field">
						<label for="utmat-new-source"><?php esc_html_e( 'Source', 'utm-attribution-tracker' ); ?></label>
						<input type="text" id="utmat-new-source" name="utm_source" list="utmat-list-source" placeholder="newsletter" />
					</div>
					<div class="utmat-drawer__field">
						<label for="utmat-new-medium"><?php esc_html_e( 'Medium', 'utm-attribution-tracker' ); ?></label>
						<input type="text" id="utmat-new-medium" name="utm_medium" list="utmat-list-medium" placeholder="email" />
					</div>
				</div>

				<!-- Suggestion datalists, populated by JS from previously used values -->
				<datalist id="utmat-list-campaign"></datalist>
				<datalist id="utmat-list-source"></datalist>
				<datalist id="utmat-list-medium"></datalist>

				<!-- Shown by JS when a UTM value was normalized on blur/submit -->
				<div class="utmat-normalize-notice" id="utmat-normalize-notice" hidden>
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"></circle><path d="M12 8v4m0 4h.01"></path></svg>
					<p><?php esc_html_e( 'Some values were normalized for consistent reporting (lowercase, spaces replaced with "-").', 'utm-attribution-tracker' ); ?></p>
					<button type="button" class="utmat-normalize-notice__dismiss" id="utmat-normalize-dismiss"
						aria-label="<?php esc_attr_e( 'Dismiss this notice', 'utm-attribution-tracker' ); ?>">&times;</button>
				</div>

				<details class="utmat-add-form__advanced">
					<summary><?php esc_html_e( 'Advanced: term, content, campaign ID', 'utm-attribution-tracker' ); ?></summary>
					<div class="utmat-drawer__grid">
						<div class="utmat-drawer__field">
							<label for="utmat-new-term"><?php esc_html_e( 'Term', 'utm-attribution-tracker' ); ?></label>
							<input type="text" id="utmat-new-term" name="utm_term" />
						</div>
						<div class="utmat-drawer__field">
							<label for="utmat-new-content"><?php esc_html_e( 'Content', 'utm-attribution-tracker' ); ?></label>
							<input type="text" id="utmat-new-content" name="utm_content" />
						</div>
						<div class="utmat-drawer__field">
							<label for="utmat-new-utmid"><?php esc_html_e( 'Campaign ID (utm_id)', 'utm-attribution-tracker' ); ?></label>
							<input type="text" id="utmat-new-utmid" name="utm_id" />
						</div>
					</div>
				</details>

				<div class="utmat-drawer__generated">
					<p class="utmat-field__label"><?php esc_html_e( 'Short link', 'utm-attribution-tracker' ); ?></p>
					<p class="utmat-readonly" id="utmat-drawer-shortlink"></p>
					<p class="utmat-field__label"><?php esc_html_e( 'Visitors are forwarded to', 'utm-attribution-tracker' ); ?></p>
					<p class="utmat-readonly utmat-readonly--wrap" id="utmat-drawer-preview"></p>
				</div>
			</div>

			<div class="utmat-drawer__footer">
				<p class="utmat-drawer__shortcode" id="utmat-drawer-shortcode"></p>
				<div class="utmat-drawer__actions">
					<span class="utmat-add-form__msg" id="utmat-add-msg" role="status"></span>
					<button type="button" class="button" id="utmat-cancel-edit"><?php esc_html_e( 'Cancel', 'utm-attribution-tracker' ); ?></button>
					<button type="submit" class="button button-primary" id="utmat-submit-btn"><?php esc_html_e( 'Add link', 'utm-attribution-tracker' ); ?></button>
				</div>
			</div>
		</form>
	</aside>

	<!-- Strings exposed to JS for re-use in dynamically rendered content -->
	<script id="utmat-i18n" type="application/json">
	<?php
	echo wp_json_encode(
		array(
			'copied'               => __( 'Copied!', 'utm-attribution-tracker' ),
			'copyFailed'           => __( 'Copy failed', 'utm-attribution-tracker' ),
			'errorPrefix'          => __( 'Could not load data:', 'utm-attribution-tracker' ),
			'noLinks'              => __( 'No tracked links yet. Use Add tracked link to create one.', 'utm-attribution-tracker' ),
			'noLinksMatch'         => __( 'No tracked links match your search.', 'utm-attribution-tracker' ),
			'deleteSelected'       => __( 'Delete selected', 'utm-attribution-tracker' ),
			'presetSaved'          => __( 'Preset saved.', 'utm-attribution-tracker' ),
			'presetNameRequired'   => __( 'Please enter a preset name.', 'utm-attribution-tracker' ),
			'confirmDeletePreset'  => __( 'Delete this preset?', 'utm-attribution-tracker' ),
			'testWebhookSending'   => __( 'Sending…', 'utm-attribution-tracker' ),
			'testWebhookSent'      => __( 'Test webhook sent.', 'utm-attribution-tracker' ),
			'testWebhookFailed'    => __( 'Delivery failed — check the URL and your receiver.', 'utm-attribution-tracker' ),
			/* translators: 1: date and time of the delivery, 2: response detail. */
			'webhookLastOk'        => __( 'Last delivery: %1$s succeeded (%2$s)', 'utm-attribution-tracker' ),
			/* translators: 1: date and time of the delivery, 2: error detail. */
			'webhookLastFailed'    => __( 'Last delivery: %1$s failed (%2$s)', 'utm-attribution-tracker' ),
			'duplicate'            => __( 'Duplicate', 'utm-attribution-tracker' ),
			'duplicateHint'        => __( 'Pre-fill the add-link form with this link’s values to create a variant.', 'utm-attribution-tracker' ),
			'copySuffix'           => __( '(copy)', 'utm-attribution-tracker' ),
			'clicksLabel'          => __( 'Clicks', 'utm-attribution-tracker' ),
			'delete'               => __( 'Delete', 'utm-attribution-tracker' ),
			'confirmDelete'        => __( 'Confirm?', 'utm-attribution-tracker' ),
			'added'                => __( 'Link added.', 'utm-attribution-tracker' ),
			'updated'              => __( 'Link updated.', 'utm-attribution-tracker' ),
			'deleted'              => __( 'Link deleted.', 'utm-attribution-tracker' ),
			'edit'                 => __( 'Edit', 'utm-attribution-tracker' ),
			'saveChanges'          => __( 'Save changes', 'utm-attribution-tracker' ),
			'addLink'              => __( 'Add link', 'utm-attribution-tracker' ),
			'revenueLabel'         => __( 'Revenue', 'utm-attribution-tracker' ),
			'ordersLabel'          => __( 'Orders', 'utm-attribution-tracker' ),
			'qrCode'               => __( 'QR code', 'utm-attribution-tracker' ),
			'downloadPng'          => __( 'Download PNG', 'utm-attribution-tracker' ),
			'exporting'            => __( 'Exporting…', 'utm-attribution-tracker' ),
			'exportCsv'            => __( 'Export CSV', 'utm-attribution-tracker' ),
			'exportEmpty'          => __( 'No events to export for this period.', 'utm-attribution-tracker' ),
			'settingsSaved'        => __( 'Settings saved.', 'utm-attribution-tracker' ),
			'platformGoogle'       => __( 'Google Ads', 'utm-attribution-tracker' ),
			'platformFacebook'     => __( 'Facebook / Meta', 'utm-attribution-tracker' ),
			'platformMicrosoft'    => __( 'Microsoft Ads', 'utm-attribution-tracker' ),
			'platformTiktok'       => __( 'TikTok Ads', 'utm-attribution-tracker' ),
			'platformLinkedin'     => __( 'LinkedIn Ads', 'utm-attribution-tracker' ),
			'platformTwitter'      => __( 'X / Twitter Ads', 'utm-attribution-tracker' ),
			'vsPrevious'           => __( 'vs previous period', 'utm-attribution-tracker' ),
			'conversionsSeries'    => __( 'Conversions', 'utm-attribution-tracker' ),
			'previousPeriodSeries' => __( 'Previous period', 'utm-attribution-tracker' ),
			/* translators: %s: name of the detected page cache plugin. */
			'cacheDetected'        => __( 'Detected %s. Tracking still works correctly on cached pages via the front-end beacon.', 'utm-attribution-tracker' ),
			'deviceDesktop'        => __( 'Desktop', 'utm-attribution-tracker' ),
			'deviceMobile'         => __( 'Mobile', 'utm-attribution-tracker' ),
			'deviceTablet'         => __( 'Tablet', 'utm-attribution-tracker' ),
			'channels'             => TrafficSource::channel_labels(),
			'testEmailSending'     => __( 'Sending…', 'utm-attribution-tracker' ),
			'testEmailSent'        => __( 'Test email sent.', 'utm-attribution-tracker' ),
			'testEmailFailed'      => __( 'Sending failed — check your mail configuration.', 'utm-attribution-tracker' ),
			'diagnosticsRunning'   => __( 'Running…', 'utm-attribution-tracker' ),
			'diagnosticsFailed'    => __( 'Could not run diagnostics.', 'utm-attribution-tracker' ),
			'diagStatusPass'       => __( 'Passed', 'utm-attribution-tracker' ),
			'diagStatusWarn'       => __( 'Warning', 'utm-attribution-tracker' ),
			'diagStatusFail'       => __( 'Failed', 'utm-attribution-tracker' ),
			'diagStatusSkip'       => __( 'Skipped', 'utm-attribution-tracker' ),
			'diagFormComplete'     => __( 'Covered', 'utm-attribution-tracker' ),
			'diagFormPartial'      => __( 'Partial', 'utm-attribution-tracker' ),
			'diagFormNone'         => __( 'No fields', 'utm-attribution-tracker' ),
			'diagNone'             => __( '—', 'utm-attribution-tracker' ),
			'drawerAddTitle'       => __( 'Add tracked link', 'utm-attribution-tracker' ),
			'drawerEditTitle'      => __( 'Edit tracked link', 'utm-attribution-tracker' ),
			'copyLink'             => __( 'Copy link', 'utm-attribution-tracker' ),
			'copyShortLink'        => __( 'Copy short link', 'utm-attribution-tracker' ),
			'copyDestination'      => __( 'Copy destination URL', 'utm-attribution-tracker' ),
			/* translators: %s: tracked link label. */
			'selectLink'           => __( 'Select %s', 'utm-attribution-tracker' ),
			/* translators: %s: tracked link label. */
			'qrFor'                => __( 'QR code for %s', 'utm-attribution-tracker' ),
			/* translators: %s: tracked link label. */
			'editLink'             => __( 'Edit %s', 'utm-attribution-tracker' ),
			/* translators: %s: tracked link label. */
			'duplicateLink'        => __( 'Duplicate %s', 'utm-attribution-tracker' ),
			/* translators: %s: tracked link label. */
			'copyLinkFor'          => __( 'Copy short link for %s', 'utm-attribution-tracker' ),
			/* translators: %s: tracked link label. */
			'deleteLink'           => __( 'Delete %s', 'utm-attribution-tracker' ),
			'close'                => __( 'Close', 'utm-attribution-tracker' ),
			'showDetails'          => __( 'Show more details', 'utm-attribution-tracker' ),
			/* translators: 1: number of tracked links shown, 2: their total all-time clicks. */
			'linksCount'           => __( 'Links: %1$s · Clicks (all time): %2$s', 'utm-attribution-tracker' ),
			'shortlinkPending'     => __( 'Created from the label when you save.', 'utm-attribution-tracker' ),
			/* translators: %s: tracked link ID. */
			'shortcodeFor'         => __( 'Shortcode: [utmat_link id="%s"]', 'utm-attribution-tracker' ),
			'shortcodeAfterSave'   => __( 'After saving, embed it with the [utmat_link] shortcode.', 'utm-attribution-tracker' ),
			'previewEmpty'         => __( 'Enter a destination URL to see the final link.', 'utm-attribution-tracker' ),
			/* translators: %s: conversion rate as a percentage, for example 2.0%. */
			'convRate'             => __( '%s of clicks', 'utm-attribution-tracker' ),
			/* translators: %s: number of orders. */
			'revenueMeta'          => __( 'From %s orders on tagged campaigns', 'utm-attribution-tracker' ),
			/* translators: 1: number of clicks, 2: share of all clicks as a percentage. */
			'topShare'             => __( '%1$s clicks · %2$s of total', 'utm-attribution-tracker' ),
			'tileNoClicks'         => __( 'No clicks yet', 'utm-attribution-tracker' ),
			'tileWooHint'          => __( 'Appears with WooCommerce orders', 'utm-attribution-tracker' ),
			'tileWaiting'          => __( 'Waiting for data', 'utm-attribution-tracker' ),
			'tileNoData'           => __( 'No tagged clicks in this period', 'utm-attribution-tracker' ),
			/* translators: %s: number of days in the selected period. */
			'compareDays'          => __( 'Compared with the previous %s days', 'utm-attribution-tracker' ),
			'compareRange'         => __( 'Compared with the previous period of the same length', 'utm-attribution-tracker' ),
			'unsavedNone'          => __( 'No unsaved changes', 'utm-attribution-tracker' ),
			/* translators: %s: number of changed settings. */
			'unsavedCount'         => __( 'Unsaved changes: %s', 'utm-attribution-tracker' ),
			/* translators: %s: time the diagnostics last ran. */
			'diagLastRun'          => __( 'Last run at %s', 'utm-attribution-tracker' ),
		)
	);
	?>
	</script>

</div><!-- /.utmat-wrap -->
