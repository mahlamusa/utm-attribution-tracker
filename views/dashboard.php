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
		<h1><?php esc_html_e( 'UTM Tracker', 'utm-attribution-tracker' ); ?></h1>
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

		<label for="utmat-campaign-filter" class="screen-reader-text">
			<?php esc_html_e( 'Filter by campaign', 'utm-attribution-tracker' ); ?>
		</label>
		<select id="utmat-campaign-filter">
			<option value=""><?php esc_html_e( 'All campaigns', 'utm-attribution-tracker' ); ?></option>
		</select>

		<p class="utmat-total" aria-live="polite">
			<strong id="utmat-total-count">—</strong>
			<?php esc_html_e( 'clicks in this period', 'utm-attribution-tracker' ); ?>
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

		<table class="wp-list-table widefat fixed striped" id="utmat-links-table">
			<thead>
				<tr>
					<th scope="col" class="utmat-col-label"><?php esc_html_e( 'Label', 'utm-attribution-tracker' ); ?></th>
					<th scope="col" class="utmat-col-campaign"><?php esc_html_e( 'Campaign', 'utm-attribution-tracker' ); ?></th>
					<th scope="col" class="utmat-col-clicks"><?php esc_html_e( 'Clicks', 'utm-attribution-tracker' ); ?></th>
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
					<input type="text" id="utmat-new-campaign" name="utm_campaign"
						placeholder="spring-sale" />
				</p>
				<p>
					<label for="utmat-new-source"><?php esc_html_e( 'Source', 'utm-attribution-tracker' ); ?></label>
					<input type="text" id="utmat-new-source" name="utm_source"
						placeholder="newsletter" />
				</p>
				<p>
					<label for="utmat-new-medium"><?php esc_html_e( 'Medium', 'utm-attribution-tracker' ); ?></label>
					<input type="text" id="utmat-new-medium" name="utm_medium"
						placeholder="email" />
				</p>
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
			'copy'          => __( 'Copy', 'utm-attribution-tracker' ),
			'copied'        => __( 'Copied!', 'utm-attribution-tracker' ),
			'errorPrefix'   => __( 'Could not load data:', 'utm-attribution-tracker' ),
			'noLinks'       => __( 'No tracked links yet — add your first one below.', 'utm-attribution-tracker' ),
			'clicksLabel'   => __( 'Clicks', 'utm-attribution-tracker' ),
			'delete'        => __( 'Delete', 'utm-attribution-tracker' ),
			'confirmDelete' => __( 'Confirm?', 'utm-attribution-tracker' ),
			'added'         => __( 'Link added.', 'utm-attribution-tracker' ),
			'updated'       => __( 'Link updated.', 'utm-attribution-tracker' ),
			'edit'          => __( 'Edit', 'utm-attribution-tracker' ),
			'saveChanges'   => __( 'Save changes', 'utm-attribution-tracker' ),
			'addLink'       => __( 'Add link', 'utm-attribution-tracker' ),
			'revenueLabel'  => __( 'Revenue', 'utm-attribution-tracker' ),
			'ordersLabel'   => __( 'Orders', 'utm-attribution-tracker' ),
		)
	);
	?>
	</script>

</div><!-- /.utmat-wrap -->
