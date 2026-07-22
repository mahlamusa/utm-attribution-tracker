<?php
/**
 * Dashboard view — UTM & Attribution Tracker admin page.
 *
 * All dynamic data is passed via wp_localize_script as `utmatData`.
 * This file only outputs the static HTML shell; JS handles all data fetching.
 *
 * Follows the house design language: light, WP-admin-native with polish,
 * primary blue for primary actions, neutral WP grays everywhere else.
 *
 * @package UTMAT
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap utmat-wrap" id="utmat-dashboard">

	<div class="utmat-header">
		<h1><?php esc_html_e( 'UTM & Attribution Tracker', 'utm-attribution-tracker' ); ?></h1>
		<p class="utmat-header__sub">
			<?php esc_html_e( 'Campaign clicks and UTM attribution, tracked on your own site.', 'utm-attribution-tracker' ); ?>
		</p>
	</div>

	<!-- Controls bar -->
	<div class="utmat-controls">
		<div class="utmat-period-btns" role="group" aria-label="<?php esc_attr_e( 'Date range', 'utm-attribution-tracker' ); ?>">
			<button type="button" class="utmat-period-btn active" data-period="7" aria-pressed="true">
				<?php esc_html_e( '7 days', 'utm-attribution-tracker' ); ?>
			</button>
			<button type="button" class="utmat-period-btn" data-period="30" aria-pressed="false">
				<?php esc_html_e( '30 days', 'utm-attribution-tracker' ); ?>
			</button>
			<button type="button" class="utmat-period-btn" data-period="90" aria-pressed="false">
				<?php esc_html_e( '90 days', 'utm-attribution-tracker' ); ?>
			</button>
		</div>

		<div class="utmat-daterange">
			<label for="utmat-date-since" class="screen-reader-text"><?php esc_html_e( 'Custom range start date', 'utm-attribution-tracker' ); ?></label>
			<input type="date" id="utmat-date-since" />
			<span aria-hidden="true">&ndash;</span>
			<label for="utmat-date-until" class="screen-reader-text"><?php esc_html_e( 'Custom range end date', 'utm-attribution-tracker' ); ?></label>
			<input type="date" id="utmat-date-until" />
			<button type="button" class="button" id="utmat-date-apply"><?php esc_html_e( 'Apply', 'utm-attribution-tracker' ); ?></button>
			<button type="button" class="button-link" id="utmat-date-clear" hidden><?php esc_html_e( 'Clear', 'utm-attribution-tracker' ); ?></button>
		</div>

		<label for="utmat-campaign-filter" class="screen-reader-text">
			<?php esc_html_e( 'Filter by campaign', 'utm-attribution-tracker' ); ?>
		</label>
		<select id="utmat-campaign-filter">
			<option value=""><?php esc_html_e( 'All campaigns', 'utm-attribution-tracker' ); ?></option>
		</select>

		<button type="button" class="button" id="utmat-export-btn"
			title="<?php esc_attr_e( 'Download the raw events for the selected period as CSV (up to 10,000 rows).', 'utm-attribution-tracker' ); ?>">
			<?php esc_html_e( 'Export CSV', 'utm-attribution-tracker' ); ?>
		</button>

		<p class="utmat-total" aria-live="polite">
			<strong id="utmat-total-count">—</strong>
			<?php esc_html_e( 'clicks in this period', 'utm-attribution-tracker' ); ?>
			<span class="utmat-conv" id="utmat-conv" hidden></span>
			<span class="utmat-trend" id="utmat-trend" hidden></span>
		</p>
	</div>

	<!-- Loading state -->
	<div id="utmat-loading" class="utmat-loading" aria-live="polite">
		<span class="spinner is-active"></span>
		<p><?php esc_html_e( 'Loading data…', 'utm-attribution-tracker' ); ?></p>
	</div>

	<!-- Error state -->
	<div id="utmat-error" class="notice notice-error" hidden>
		<p id="utmat-error-msg"></p>
	</div>

	<!-- First-run onboarding — shown by JS when no events have ever been recorded -->
	<div class="utmat-section utmat-onboarding" id="utmat-onboarding" hidden>
		<h2><?php esc_html_e( 'Get your first tracked click', 'utm-attribution-tracker' ); ?></h2>
		<ol>
			<li>
				<?php esc_html_e( 'Open any page of your site with UTM parameters appended, for example:', 'utm-attribution-tracker' ); ?>
				<br />
				<code class="utmat-code"><?php echo esc_html( home_url( '/' ) . '?utm_source=newsletter&utm_medium=email&utm_campaign=launch' ); ?></code>
			</li>
			<li><?php esc_html_e( 'Or share a tracked link from the table below — the click is counted before the visitor is forwarded to the destination.', 'utm-attribution-tracker' ); ?></li>
			<li><?php esc_html_e( 'Ad clicks carrying gclid, fbclid, msclkid, ttclid, li_fat_id, or twclid are tracked automatically, even without UTM parameters.', 'utm-attribution-tracker' ); ?></li>
		</ol>
		<p class="description">
			<?php esc_html_e( 'Heads up: visits from logged-in administrators are not tracked by default, so test in a private browsing window. Bots and repeated reloads are filtered out automatically.', 'utm-attribution-tracker' ); ?>
		</p>
	</div>

	<!-- Charts -->
	<div class="utmat-charts" id="utmat-charts" hidden>

		<div class="utmat-card utmat-card--wide">
			<h2 class="utmat-card__title"><?php esc_html_e( 'Clicks over time', 'utm-attribution-tracker' ); ?></h2>
			<div class="utmat-card__body">
				<canvas id="utmat-chart-line"
					aria-label="<?php esc_attr_e( 'Clicks over time line chart', 'utm-attribution-tracker' ); ?>"
					role="img"></canvas>
				<p class="description utmat-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
			</div>
		</div>

		<div class="utmat-card">
			<h2 class="utmat-card__title"><?php esc_html_e( 'By source', 'utm-attribution-tracker' ); ?></h2>
			<div class="utmat-card__body utmat-card__body--center">
				<canvas id="utmat-chart-source"
					aria-label="<?php esc_attr_e( 'Clicks by source doughnut chart', 'utm-attribution-tracker' ); ?>"
					role="img"></canvas>
				<p class="description utmat-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
			</div>
		</div>

		<div class="utmat-card">
			<h2 class="utmat-card__title"><?php esc_html_e( 'By campaign', 'utm-attribution-tracker' ); ?></h2>
			<div class="utmat-card__body">
				<canvas id="utmat-chart-campaign"
					aria-label="<?php esc_attr_e( 'Clicks by campaign bar chart', 'utm-attribution-tracker' ); ?>"
					role="img"></canvas>
				<p class="description utmat-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
			</div>
		</div>

		<div class="utmat-card">
			<h2 class="utmat-card__title"><?php esc_html_e( 'By medium', 'utm-attribution-tracker' ); ?></h2>
			<div class="utmat-card__body">
				<canvas id="utmat-chart-medium"
					aria-label="<?php esc_attr_e( 'Clicks by medium bar chart', 'utm-attribution-tracker' ); ?>"
					role="img"></canvas>
				<p class="description utmat-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
			</div>
		</div>

		<div class="utmat-card">
			<h2 class="utmat-card__title"><?php esc_html_e( 'By device', 'utm-attribution-tracker' ); ?></h2>
			<div class="utmat-card__body utmat-card__body--center">
				<canvas id="utmat-chart-device"
					aria-label="<?php esc_attr_e( 'Clicks by device type doughnut chart', 'utm-attribution-tracker' ); ?>"
					role="img"></canvas>
				<p class="description utmat-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
			</div>
		</div>

		<div class="utmat-card">
			<h2 class="utmat-card__title"><?php esc_html_e( 'Traffic source', 'utm-attribution-tracker' ); ?></h2>
			<div class="utmat-card__body utmat-card__body--center">
				<canvas id="utmat-chart-traffic-source"
					aria-label="<?php esc_attr_e( 'Clicks by traffic source doughnut chart', 'utm-attribution-tracker' ); ?>"
					role="img"></canvas>
				<p class="description utmat-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
			</div>
		</div>

		<div class="utmat-card">
			<h2 class="utmat-card__title"><?php esc_html_e( 'Top landing pages', 'utm-attribution-tracker' ); ?></h2>
			<div class="utmat-card__body">
				<canvas id="utmat-chart-landing"
					aria-label="<?php esc_attr_e( 'Top landing pages bar chart', 'utm-attribution-tracker' ); ?>"
					role="img"></canvas>
				<p class="description utmat-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
			</div>
		</div>

		<div class="utmat-card">
			<h2 class="utmat-card__title"><?php esc_html_e( 'Top referrers', 'utm-attribution-tracker' ); ?></h2>
			<div class="utmat-card__body">
				<canvas id="utmat-chart-referrer"
					aria-label="<?php esc_attr_e( 'Top referrers bar chart', 'utm-attribution-tracker' ); ?>"
					role="img"></canvas>
				<p class="description utmat-nodata" hidden><?php esc_html_e( 'No external referrers recorded in this period.', 'utm-attribution-tracker' ); ?></p>
			</div>
		</div>

		<div class="utmat-card">
			<h2 class="utmat-card__title"><?php esc_html_e( 'Ad platforms', 'utm-attribution-tracker' ); ?></h2>
			<div class="utmat-card__body utmat-card__body--center">
				<canvas id="utmat-chart-platform"
					aria-label="<?php esc_attr_e( 'Clicks by ad platform doughnut chart', 'utm-attribution-tracker' ); ?>"
					role="img"></canvas>
				<p class="description utmat-nodata" hidden><?php esc_html_e( 'No ad click IDs recorded in this period.', 'utm-attribution-tracker' ); ?></p>
			</div>
		</div>

		<!-- Revenue by campaign — shown by JS only when WooCommerce is active -->
		<div class="utmat-card" id="utmat-card-revenue" hidden>
			<h2 class="utmat-card__title"><?php esc_html_e( 'Revenue by campaign', 'utm-attribution-tracker' ); ?></h2>
			<div class="utmat-card__body">
				<canvas id="utmat-chart-revenue"
					aria-label="<?php esc_attr_e( 'Revenue by campaign bar chart', 'utm-attribution-tracker' ); ?>"
					role="img"></canvas>
				<p class="description utmat-nodata" hidden><?php esc_html_e( 'No data for this period.', 'utm-attribution-tracker' ); ?></p>
			</div>
		</div>

	</div><!-- /.utmat-charts -->

	<!-- Tracked links -->
	<div class="utmat-section" id="utmat-links-section" hidden>
		<h2><?php esc_html_e( 'Tracked links', 'utm-attribution-tracker' ); ?></h2>
		<p class="utmat-section__desc">
			<?php esc_html_e( 'Use these redirect URLs in emails, ads, and banners. The click is counted before the visitor is forwarded to the destination.', 'utm-attribution-tracker' ); ?>
		</p>

		<div class="utmat-links-toolbar">
			<p class="utmat-links-search">
				<label for="utmat-links-filter" class="screen-reader-text"><?php esc_html_e( 'Search tracked links', 'utm-attribution-tracker' ); ?></label>
				<input type="search" id="utmat-links-filter" class="regular-text"
					placeholder="<?php esc_attr_e( 'Search by label, campaign, source, or medium…', 'utm-attribution-tracker' ); ?>" />
			</p>
			<button type="button" class="button utmat-bulk-delete-btn" id="utmat-bulk-delete-btn" hidden>
				<?php esc_html_e( 'Delete selected', 'utm-attribution-tracker' ); ?>
			</button>
		</div>

		<table class="wp-list-table widefat fixed striped" id="utmat-links-table">
			<thead>
				<tr>
					<td class="manage-column column-cb check-column">
						<label for="utmat-select-all" class="screen-reader-text"><?php esc_html_e( 'Select all', 'utm-attribution-tracker' ); ?></label>
						<input type="checkbox" id="utmat-select-all" />
					</td>
					<th scope="col" class="utmat-col-label"><?php esc_html_e( 'Label', 'utm-attribution-tracker' ); ?></th>
					<th scope="col" class="utmat-col-campaign"><?php esc_html_e( 'Campaign', 'utm-attribution-tracker' ); ?></th>
					<th scope="col" class="utmat-col-clicks utmat-col-sortable" id="utmat-sort-clicks" tabindex="0" role="button"
						aria-label="<?php esc_attr_e( 'Sort by clicks', 'utm-attribution-tracker' ); ?>">
						<?php esc_html_e( 'Clicks', 'utm-attribution-tracker' ); ?> <span class="utmat-sort-arrow" aria-hidden="true"></span>
					</th>
					<th scope="col" class="utmat-col-url"><?php esc_html_e( 'Redirect URL', 'utm-attribution-tracker' ); ?></th>
					<th scope="col" class="utmat-col-url"><?php esc_html_e( 'Destination', 'utm-attribution-tracker' ); ?></th>
					<th scope="col" class="utmat-col-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'utm-attribution-tracker' ); ?></span></th>
				</tr>
			</thead>
			<tbody id="utmat-links-tbody">
				<!-- Populated by JS -->
			</tbody>
		</table>

		<!-- Add link -->
		<form class="utmat-add-form" id="utmat-add-form">
			<h3><?php esc_html_e( 'Add tracked link', 'utm-attribution-tracker' ); ?></h3>

			<p class="utmat-presets-row">
				<label for="utmat-preset-select"><?php esc_html_e( 'Apply a saved preset', 'utm-attribution-tracker' ); ?></label>
				<select id="utmat-preset-select">
					<option value=""><?php esc_html_e( 'None', 'utm-attribution-tracker' ); ?></option>
				</select>
				<button type="button" class="button-link" id="utmat-preset-delete" hidden><?php esc_html_e( 'Delete preset', 'utm-attribution-tracker' ); ?></button>
				<button type="button" class="button" id="utmat-preset-save"><?php esc_html_e( 'Save current as preset', 'utm-attribution-tracker' ); ?></button>
			</p>

			<!-- Shown by JS when "Save current as preset" is clicked -->
			<p class="utmat-preset-name-row" id="utmat-preset-name-row" hidden>
				<label for="utmat-preset-name" class="screen-reader-text"><?php esc_html_e( 'Preset name', 'utm-attribution-tracker' ); ?></label>
				<input type="text" id="utmat-preset-name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Instagram Story', 'utm-attribution-tracker' ); ?>" />
				<button type="button" class="button button-primary" id="utmat-preset-name-confirm"><?php esc_html_e( 'Save', 'utm-attribution-tracker' ); ?></button>
				<button type="button" class="button" id="utmat-preset-name-cancel"><?php esc_html_e( 'Cancel', 'utm-attribution-tracker' ); ?></button>
			</p>

			<div class="utmat-add-form__grid">
				<p>
					<label for="utmat-new-label"><?php esc_html_e( 'Label', 'utm-attribution-tracker' ); ?></label>
					<input type="text" id="utmat-new-label" name="label" class="regular-text" required
						placeholder="<?php esc_attr_e( 'Spring newsletter button', 'utm-attribution-tracker' ); ?>" />
				</p>
				<p>
					<label for="utmat-new-destination"><?php esc_html_e( 'Destination URL', 'utm-attribution-tracker' ); ?></label>
					<input type="url" id="utmat-new-destination" name="destination" class="regular-text" required
						placeholder="https://example.com/landing-page" />
				</p>
				<p>
					<label for="utmat-new-campaign"><?php esc_html_e( 'Campaign', 'utm-attribution-tracker' ); ?></label>
					<input type="text" id="utmat-new-campaign" name="utm_campaign" list="utmat-list-campaign"
						placeholder="spring-sale" />
				</p>
				<p>
					<label for="utmat-new-source"><?php esc_html_e( 'Source', 'utm-attribution-tracker' ); ?></label>
					<input type="text" id="utmat-new-source" name="utm_source" list="utmat-list-source"
						placeholder="newsletter" />
				</p>
				<p>
					<label for="utmat-new-medium"><?php esc_html_e( 'Medium', 'utm-attribution-tracker' ); ?></label>
					<input type="text" id="utmat-new-medium" name="utm_medium" list="utmat-list-medium"
						placeholder="email" />
				</p>
			</div>

			<!-- Suggestion datalists — populated by JS from previously used values -->
			<datalist id="utmat-list-campaign"></datalist>
			<datalist id="utmat-list-source"></datalist>
			<datalist id="utmat-list-medium"></datalist>

			<!-- Shown by JS when a UTM value was normalized on blur/submit -->
			<div class="utmat-normalize-notice" id="utmat-normalize-notice" hidden>
				<p>
					<?php esc_html_e( 'Some values were normalized for consistent reporting (lowercase, spaces replaced with "-").', 'utm-attribution-tracker' ); ?>
				</p>
				<button type="button" class="utmat-normalize-notice__dismiss" id="utmat-normalize-dismiss"
					aria-label="<?php esc_attr_e( 'Dismiss this notice', 'utm-attribution-tracker' ); ?>">&times;</button>
			</div>

			<details class="utmat-add-form__advanced">
				<summary><?php esc_html_e( 'More UTM parameters', 'utm-attribution-tracker' ); ?></summary>
				<div class="utmat-add-form__grid">
					<p>
						<label for="utmat-new-term"><?php esc_html_e( 'Term', 'utm-attribution-tracker' ); ?></label>
						<input type="text" id="utmat-new-term" name="utm_term" />
					</p>
					<p>
						<label for="utmat-new-content"><?php esc_html_e( 'Content', 'utm-attribution-tracker' ); ?></label>
						<input type="text" id="utmat-new-content" name="utm_content" />
					</p>
					<p>
						<label for="utmat-new-utmid"><?php esc_html_e( 'Campaign ID (utm_id)', 'utm-attribution-tracker' ); ?></label>
						<input type="text" id="utmat-new-utmid" name="utm_id" />
					</p>
				</div>
			</details>

			<p class="utmat-add-form__actions">
				<button type="submit" class="button button-primary" id="utmat-submit-btn"><?php esc_html_e( 'Add link', 'utm-attribution-tracker' ); ?></button>
				<button type="button" class="button" id="utmat-cancel-edit" hidden><?php esc_html_e( 'Cancel', 'utm-attribution-tracker' ); ?></button>
				<span class="utmat-add-form__msg" id="utmat-add-msg" role="status"></span>
			</p>
		</form>
	</div>

	<!-- Settings -->
	<div class="utmat-section" id="utmat-settings-section">
		<h2><?php esc_html_e( 'Settings', 'utm-attribution-tracker' ); ?></h2>
		<p class="utmat-section__desc">
			<?php esc_html_e( 'Data retention and reporting preferences.', 'utm-attribution-tracker' ); ?>
		</p>

		<!-- Shown by JS when a known full-page cache plugin is detected -->
		<div class="notice notice-info inline utmat-cache-notice" id="utmat-cache-notice" hidden>
			<p></p>
		</div>

		<form id="utmat-settings-form" class="utmat-settings-form">
			<p>
				<label for="utmat-retention-days"><?php esc_html_e( 'Keep events for (days)', 'utm-attribution-tracker' ); ?></label>
				<input type="number" id="utmat-retention-days" name="retention_days" min="0" step="1" class="small-text" />
				<span class="description"><?php esc_html_e( 'Events older than this are deleted daily. 0 keeps events forever.', 'utm-attribution-tracker' ); ?></span>
			</p>
			<p>
				<label for="utmat-cookie-days"><?php esc_html_e( 'Attribution window (days)', 'utm-attribution-tracker' ); ?></label>
				<input type="number" id="utmat-cookie-days" name="cookie_days" min="1" max="730" step="1" class="small-text" />
				<span class="description"><?php esc_html_e( 'How long the first/last-touch attribution cookie lives. Longer windows attribute slower-converting visitors.', 'utm-attribution-tracker' ); ?></span>
			</p>
			<p>
				<label for="utmat-weekly-email">
					<input type="checkbox" id="utmat-weekly-email" name="weekly_email" />
					<?php esc_html_e( 'Send a weekly campaign summary email', 'utm-attribution-tracker' ); ?>
				</label>
				<span class="description"><?php esc_html_e( 'Sent to the site admin email address every week. Off by default.', 'utm-attribution-tracker' ); ?></span>
			</p>
			<p>
				<label for="utmat-webhook-url"><?php esc_html_e( 'Conversion webhook URL', 'utm-attribution-tracker' ); ?></label>
				<input type="url" id="utmat-webhook-url" name="webhook_url" class="regular-text" placeholder="https://example.com/webhooks/utm" />
				<span class="description"><?php esc_html_e( 'POSTs a JSON payload here whenever a WooCommerce order is attributed. Leave blank to disable.', 'utm-attribution-tracker' ); ?></span>
			</p>
			<p id="utmat-webhook-secret-row" hidden>
				<label for="utmat-webhook-secret"><?php esc_html_e( 'Webhook signing secret', 'utm-attribution-tracker' ); ?></label>
				<input type="text" id="utmat-webhook-secret" class="regular-text" readonly onclick="this.select();" />
				<span class="description"><?php esc_html_e( 'Every request carries an X-UTMAT-Signature header (sha256=HMAC of the JSON body using this secret) so your receiver can verify it came from this site.', 'utm-attribution-tracker' ); ?></span>
				<br />
				<span id="utmat-webhook-last" class="description"></span>
			</p>
			<p class="utmat-settings-form__actions">
				<button type="submit" class="button button-primary" id="utmat-settings-save"><?php esc_html_e( 'Save settings', 'utm-attribution-tracker' ); ?></button>
				<button type="button" class="button" id="utmat-test-email"
					title="<?php esc_attr_e( 'Send the weekly summary to the report recipient now, regardless of the toggle.', 'utm-attribution-tracker' ); ?>">
					<?php esc_html_e( 'Send test email', 'utm-attribution-tracker' ); ?>
				</button>
				<button type="button" class="button" id="utmat-test-webhook" hidden
					title="<?php esc_attr_e( 'Send a synthetic conversion payload to the configured webhook URL now.', 'utm-attribution-tracker' ); ?>">
					<?php esc_html_e( 'Send test webhook', 'utm-attribution-tracker' ); ?>
				</button>
				<span class="utmat-add-form__msg" id="utmat-settings-msg" role="status"></span>
			</p>
		</form>
	</div>

	<!-- Shortcode reference -->
	<div class="utmat-section utmat-section--muted">
		<h2><?php esc_html_e( 'Shortcode', 'utm-attribution-tracker' ); ?></h2>
		<p><?php esc_html_e( 'Embed a tracked link anywhere:', 'utm-attribution-tracker' ); ?></p>
		<code class="utmat-code">[utmat_link id=&quot;example-campaign&quot; label=&quot;<?php esc_html_e( 'Click here', 'utm-attribution-tracker' ); ?>&quot;]</code>
		<p class="description">
			<?php esc_html_e( 'Incoming visits that already carry UTM parameters are tracked automatically — no shortcode required.', 'utm-attribution-tracker' ); ?>
		</p>
	</div>

	<!-- Strings exposed to JS for re-use in dynamically rendered content -->
	<script id="utmat-i18n" type="application/json">
	<?php
	echo wp_json_encode(
		array(
			'copy'                 => __( 'Copy', 'utm-attribution-tracker' ),
			'copied'               => __( 'Copied!', 'utm-attribution-tracker' ),
			'errorPrefix'          => __( 'Could not load data:', 'utm-attribution-tracker' ),
			'noLinks'              => __( 'No tracked links yet — add your first one below.', 'utm-attribution-tracker' ),
			'noLinksMatch'         => __( 'No tracked links match your search.', 'utm-attribution-tracker' ),
			'deleteSelected'       => __( 'Delete selected', 'utm-attribution-tracker' ),
			'presetSaved'          => __( 'Preset saved.', 'utm-attribution-tracker' ),
			'presetNameRequired'   => __( 'Please enter a preset name.', 'utm-attribution-tracker' ),
			'confirmDeletePreset'  => __( 'Delete this preset?', 'utm-attribution-tracker' ),
			'testWebhookSending'   => __( 'Sending…', 'utm-attribution-tracker' ),
			'testWebhookSent'      => __( 'Test webhook sent.', 'utm-attribution-tracker' ),
			'testWebhookFailed'    => __( 'Delivery failed — check the URL and your receiver.', 'utm-attribution-tracker' ),
			// translators: both %s are replaced client-side — the delivery time, then the HTTP status or error detail.
			'webhookLastOk'        => __( 'Last delivery: %1$s succeeded (%2$s)', 'utm-attribution-tracker' ),
			// translators: both %s are replaced client-side — the delivery time, then the HTTP status or error detail.
			'webhookLastFailed'    => __( 'Last delivery: %1$s failed (%2$s)', 'utm-attribution-tracker' ),
			'duplicate'            => __( 'Duplicate', 'utm-attribution-tracker' ),
			'duplicateHint'        => __( 'Pre-fill the add-link form with this link’s values to create a variant.', 'utm-attribution-tracker' ),
			'copySuffix'           => __( '(copy)', 'utm-attribution-tracker' ),
			'clicksLabel'          => __( 'Clicks', 'utm-attribution-tracker' ),
			'delete'               => __( 'Delete', 'utm-attribution-tracker' ),
			'confirmDelete'        => __( 'Confirm?', 'utm-attribution-tracker' ),
			'added'                => __( 'Link added.', 'utm-attribution-tracker' ),
			'updated'              => __( 'Link updated.', 'utm-attribution-tracker' ),
			'edit'                 => __( 'Edit', 'utm-attribution-tracker' ),
			'saveChanges'          => __( 'Save changes', 'utm-attribution-tracker' ),
			'addLink'              => __( 'Add link', 'utm-attribution-tracker' ),
			'revenueLabel'         => __( 'Revenue', 'utm-attribution-tracker' ),
			'ordersLabel'          => __( 'Orders', 'utm-attribution-tracker' ),
			'qr'                   => __( 'QR', 'utm-attribution-tracker' ),
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
			'conversionsLabel'     => __( 'conversions', 'utm-attribution-tracker' ),
			'conversionsSeries'    => __( 'Conversions', 'utm-attribution-tracker' ),
			'previousPeriodSeries' => __( 'Previous period', 'utm-attribution-tracker' ),
			// translators: %s is replaced client-side with the detected cache plugin's name.
			'cacheDetected'        => __( 'Detected %s. Tracking still works correctly on cached pages via the front-end beacon.', 'utm-attribution-tracker' ),
			'deviceDesktop'        => __( 'Desktop', 'utm-attribution-tracker' ),
			'deviceMobile'         => __( 'Mobile', 'utm-attribution-tracker' ),
			'deviceTablet'         => __( 'Tablet', 'utm-attribution-tracker' ),
			'testEmailSending'     => __( 'Sending…', 'utm-attribution-tracker' ),
			'testEmailSent'        => __( 'Test email sent.', 'utm-attribution-tracker' ),
			'testEmailFailed'      => __( 'Sending failed — check your mail configuration.', 'utm-attribution-tracker' ),
		)
	);
	?>
	</script>

</div><!-- /.utmat-wrap -->
