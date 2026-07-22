=== UTM & Attribution Tracker ===
Contributors:      mahlamusa
Tags:              utm, analytics, attribution, woocommerce, tracking
Requires at least: 6.0
Tested up to:      6.7
Requires PHP:      8.0
Stable tag:        1.0.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted UTM and campaign tracking with short links, QR codes, WooCommerce revenue attribution, and a live Chart.js dashboard.

== Description ==

UTM & Attribution Tracker gives you self-hosted, privacy-respecting campaign tracking for any UTM-tagged link on or off your WordPress site — no third-party analytics service required.

**Tracking engine:**

* **Cache-proof tracking beacon** — a tiny front-end beacon records UTM-tagged visits even when your pages are served from a full-page cache, where PHP never runs. It self-gates in JS, so untagged page views send nothing.
* **Passive UTM tracking (no-JS fallback)** — on uncached pages, any request arriving with a `utm_*` parameter or an ad click ID (`gclid`, `fbclid`, `msclkid`, `ttclid`, `li_fat_id`, `twclid`) is also recorded server-side.
* **Bot filtering** — crawlers, link-preview unfurlers, and monitoring agents are recognised by user agent and never pollute your stats. Bots still receive the redirect, so social link previews keep working.
* **Click deduplication** — repeat hits from the same visitor with identical parameters within a 30-minute window (filterable) are recorded once.
* **First/last-touch attribution cookie** — campaign parameters are persisted in a first-party cookie (`utmat_attr`, 90 days) with separate first-touch and last-touch sets.

**Links & sharing:**

* **Pretty short links** — every managed link gets a clean `/go/{slug}/` URL (query-string form on plain permalinks). The click is counted before the visitor is forwarded to the destination with full UTM parameters appended.
* **QR code per link** — generate a scannable QR code for any tracked link straight from the dashboard and download it as a PNG. Generated entirely in your browser — no external service is contacted.
* **[utmat_link] shortcode** — embed tracked links in posts, pages, or widgets; supports enclosing content: `[utmat_link id="sale"]Shop the <strong>sale</strong>[/utmat_link]`.
* **UTM naming conventions** — the add-link form lowercases values and replaces spaces with `-` (with a heads-up notice), and suggests previously used sources, mediums, and campaigns — so `My Newsletter` and `my-newsletter` never fragment your reports into separate rows. Save a source/medium/term/content combination as a named preset (e.g. "Instagram Story") to apply it to future links in one click.

**Reporting:**

* **Chart.js dashboard** — clicks over time, breakdowns by source, campaign, medium, device type, traffic source (Direct/Organic-Referral/Paid/Google Ads/Tagged), top landing pages, top referrers, and ad platform, filterable by date range (7/30/90 days) and campaign.
* **WooCommerce revenue attribution** — orders are stamped with the buyer's first- and last-touch campaign data (visible in a metabox on the order screen), and a revenue-by-campaign chart shows what your campaigns actually earn.
* **CSV export** — download the raw events for any period as a CSV (the newest 10,000 rows). Conversion rows are included alongside clicks, so the export can hold more rows than the dashboard click total. Exports never include IP hashes or user agents, and cell values are guarded against spreadsheet formula injection.
* **Weekly email summary** — an optional (off by default) weekly email with total clicks, top campaigns, top sources, and revenue when WooCommerce is active. Pairs well with the companion SMTP plugin for reliable delivery.
* **Tracking Doctor diagnostics** — a "Run diagnostics" panel on the settings card runs five rule-based local checks (caching, consent, cookies, per-form attribution coverage, WooCommerce order attribution) and reports pass/warn/fail/skip with a plain-language explanation for each. No AI, no external calls — every check inspects local plugin/site state only.

**Forms & integrations:**

* **Form attribution capture** — add a hidden field named `utmat_source` (or any other tracked parameter) to any form and it is auto-filled from the attribution cookie. Zero-config adapters for Contact Form 7 and Gravity Forms.
* **the companion SMTP plugin integration** — when the companion SMTP plugin is active, the tracker appears as a submenu item within the the companion SMTP plugin admin panel.

**Privacy & housekeeping:**

* **Privacy-safe by design** — IP addresses are stored only as salted SHA-256 hashes; the salt is unique per site.
* **Data retention** — events are pruned automatically after a configurable retention window (180 days by default, 0 = keep forever) from the dashboard settings card.
* **Suggested privacy policy text** — provided under Settings → Privacy → Policy Guide.
* **No external dependencies** — no Composer, no npm build step, no CDN calls. Chart.js and the QR library ship bundled with the plugin.

== Installation ==

1. Upload the `utm-attribution-tracker` folder to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins > Installed Plugins**.
3. Go to **UTM & Attribution** in the admin sidebar (or under **the companion SMTP plugin** if that plugin is active).

== Frequently Asked Questions ==

= Does this plugin store personal data? =

Raw IP addresses are never stored. Each IP address is combined with a secret per-site salt and hashed with SHA-256 before storage, so the stored hashes cannot be correlated with hashes from other sites. User agents and referrer URLs are stored as-is. Events are pruned automatically after a retention period (180 days by default, adjustable from the dashboard settings card; 0 keeps events forever). CSV exports never include IP hashes or user agents. Suggested privacy policy text is provided under Settings → Privacy → Policy Guide.

= How does tracking work on a cached site? =

Full-page caches serve HTML without running PHP, which breaks purely server-side trackers. UTM & Attribution Tracker ships a small beacon script in every front-end page: when the visitor's URL carries a tracked parameter, the beacon reports it to a public REST endpoint. On uncached pages a server-side fallback records the visit even for visitors without JavaScript. Deduplication ensures a visit recorded by both paths is only counted once.

= How do I create a tracked short link? =

Add a link under **Tracked Links** on the dashboard. With pretty permalinks enabled the link is served at `/go/{slug}/`; on plain permalinks it falls back to `/?utmat_track={slug}`. Either URL records the click and 302-redirects the visitor to the destination with the link's UTM parameters appended. You can copy the URL, embed it with `[utmat_link id="my-link" label="Click here"]`, or use the QR button to download a scannable PNG for print, packaging, or slides.

= Are clicks from bots and link previews counted? =

No. Requests whose user agent looks like a crawler, link-preview unfurler (Slack, WhatsApp, Facebook, etc.), or monitoring tool are excluded from your stats. They still receive the redirect so previews resolve correctly. Adjust the verdict with the `utmat_is_bot` filter.

= What does the attribution cookie store? =

When a visitor arrives with a UTM parameter or ad click ID, the plugin sets a first-party cookie named `utmat_attr` containing two sets of campaign data: the visitor's **first touch** (preserved once written) and **last touch** (updated on every tagged visit). Each set holds the whitelisted UTM/click-ID parameters present on that visit plus the landing page URL and a timestamp. The cookie does not store names, email addresses, or IP addresses.

The cookie lives for 90 days (`path=/`, `SameSite=Lax`, `Secure` on https sites). Change the lifetime with `utmat_cookie_days` or disable the cookie entirely with `utmat_enable_cookie` (see the filter reference below).

**Consent note:** the plugin does not ship a consent banner. If a plugin implementing the [WP Consent API](https://github.com/rlankhorst/wp-consent-level-api) is active (Complianz, Real Cookie Banner, Borlabs Cookie, and others implement it), the cookie is only written once the visitor has granted marketing or statistics consent — no configuration needed. Without a Consent API plugin, the cookie is written by default; gate it yourself by returning `false` from `utmat_enable_cookie` until consent is given.

**Caching caveat:** the consent decision is read server-side and baked into the page at render time, including into the front-end beacon's cached HTML. On a full-page cache, the consent state served to every visitor is whatever it was when that page was cached, until the cache entry regenerates. If your consent banner can change a visitor's stored consent without a full page reload, exclude logged-out page loads from the cache for a short window after consent changes, or configure your cache plugin to vary by your consent cookie, so the served consent state stays current.

= How does WooCommerce order attribution work? =

When a visitor who previously arrived through a tracked campaign places an order, the order is stamped with their first- and last-touch attribution sets (order meta, shown in a "Campaign attribution" metabox on the order edit screen), and a conversion event with the order total is recorded. The dashboard then shows a revenue-by-campaign chart, and the weekly email includes a revenue table. No configuration needed — it activates automatically when WooCommerce is present.

= How do I capture attribution in my lead forms? =

Every tracked parameter is available to forms under a `utmat_*` field name: `utmat_source`, `utmat_medium`, `utmat_campaign`, `utmat_id`, `utmat_term`, `utmat_content`, `utmat_gclid`, `utmat_fbclid`, `utmat_msclkid`, `utmat_ttclid`, `utmat_li_fat_id`, `utmat_twclid`, and `utmat_landing` (the landing page URL). These hold the visitor's **last touch**; append `_first` (e.g. `utmat_source_first`) for the **first touch**.

**Any form plugin (generic):** add a hidden input (or text field) with one of the names above and it is auto-filled from the attribution cookie on page load. Fields that already have a value are never overwritten. Forms injected into the page after load (some popup builders) are not filled automatically.

**Contact Form 7:** zero configuration — the last-touch fields are appended to every form as hidden fields automatically and arrive with each submission. Use special mail tags in your mail templates to include them in notification emails: `[_utmat_source]`, `[_utmat_campaign]`, etc. (any field name above works, including `_first` variants).

**Gravity Forms:** add a Hidden field, enable "Allow field to be populated dynamically" under the field's Advanced tab, and set the parameter name to e.g. `utmat_source` or `utmat_source_first`.

**Fluent Forms:** add a Hidden Field and set its *Name Attribute* to e.g. `utmat_source` — the generic layer fills it on page load.

**WPForms:** WPForms does not expose a field's HTML `name`, so the generic layer cannot target it. Use a Hidden Field with the `{query_var key="utm_source"}` Smart Tag to capture parameters from the URL the form is submitted on.

= How do I export my data? =

Click **Export CSV** on the dashboard to download the raw events for the selected period and campaign filter (the newest 10,000 rows are exported). The CSV contains the click date, link slug, all UTM parameters, ad click IDs, destination, referrer, conversion flag, and order total — but never IP hashes or user agents. Because the export also includes conversion rows (flagged in the `is_conversion` column) alongside click rows, its total row count can exceed the click total shown on the dashboard. Values that could be interpreted as spreadsheet formulas are prefixed with `'` for safety.

= How do I get a weekly report by email? =

Enable "Send a weekly campaign summary email" on the dashboard settings card (it is off by default — no unsolicited mail). Once a week the site admin email receives an HTML summary with total clicks, the top 5 campaigns and sources, and revenue by campaign when WooCommerce is active. Change the recipient with the `utmat_report_recipient` filter. If the companion SMTP plugin is installed, delivery transparently goes through your configured SMTP server.

= My site is behind a reverse proxy or CDN — anything to configure? =

Yes. Behind a proxy (Cloudflare, Varnish, a load balancer), `REMOTE_ADDR` is the proxy's IP, not the visitor's. Click deduplication and the collect endpoint's rate limiting key on a hash of that IP — so with the default behaviour all visitors would share the proxy's identity, over-deduplicating clicks and tripping the rate limit for everyone. Use the `utmat_client_ip` filter to return the real client IP from the header your proxy sets (e.g. `CF-Connecting-IP`, or the first entry of `X-Forwarded-For`). Only trust forwarded headers when every request passes through a proxy that overwrites them — they are client-spoofable otherwise. If configuring the real IP is not possible, raise the shared ceiling with the `utmat_rate_limit` filter instead.

= Why are my UTM values being changed when I save a link? =

Values are normalized for consistent reporting: lowercased, with whitespace replaced by `-`. Analytics tools treat `Newsletter`, `newsletter`, and `News letter` as three different sources, silently fragmenting reports. Normalization (applied in the form and again server-side) keeps every report row canonical.

== Filter reference ==

All filters shipped by the plugin:

* **`utmat_track_logged_in_users`** *(bool, default `false`)* — return `true` to record clicks from logged-in administrators (excluded by default to keep test traffic out of your stats).
    `add_filter( 'utmat_track_logged_in_users', '__return_true' );`
* **`utmat_is_bot`** *(bool, receives the user agent string)* — override the bot verdict for a user agent. Bots are redirected but never recorded.
* **`utmat_client_ip`** *(string)* — the client IP used for hashing, deduplication, and rate limiting. Defaults to `REMOTE_ADDR`; see the reverse proxy FAQ above.
* **`utmat_dedup_window`** *(int, seconds, default 1800)* — the window within which an identical click from the same visitor is recorded once. Return `0` to disable deduplication.
* **`utmat_rate_limit`** *(int, requests/minute/IP, default 15)* — the collect endpoint's rate limit. Raise it when many visitors share one IP (see the reverse proxy FAQ above).
* **`utmat_retention_days`** *(int, days, default from the settings card / 180)* — the event retention window. `0` keeps events forever.
* **`utmat_cookie_days`** *(int, days, default 90)* — the attribution cookie lifetime. Defaults to the Attribution window setting on the dashboard; the filter overrides it.
* **`utmat_enable_cookie`** *(bool, default `true`)* — master switch for the attribution cookie; return `false` to never set it (e.g. until consent is given).
* **`utmat_report_recipient`** *(string, default the admin email)* — the recipient address of the weekly summary email.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
