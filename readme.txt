=== Utmatra - UTM Attribution and Tracking ===
Contributors:      mahlamusa
Tags:              utm, analytics, attribution, woocommerce, tracking
Requires at least: 6.9
Tested up to:      7.1
Requires PHP:      8.0
Stable tag:        1.0.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted UTM and campaign tracking with short links, QR codes, WooCommerce revenue attribution, and a live Chart.js dashboard.

== Description ==

Utmatra gives you self-hosted, privacy-respecting campaign tracking for any UTM-tagged link on or off your WordPress site — no third-party analytics service required.

**Tracking engine:**

* **Cache-proof tracking beacon** — a tiny front-end beacon records the first page of every visit (with its referrer) and every UTM-tagged visit, even when your pages are served from a full-page cache, where PHP never runs. Later untagged page views in the same visit send nothing. "Track the first page of every visit" in Settings switches visit recording off.
* **Channel classification** — every recorded visit is filed into a marketing channel at write time: direct, organic search, paid search, paid social, organic social, email, referral, AI assistant (ChatGPT, Perplexity, Claude, Gemini, Copilot and others), display and affiliate.
* **Passive UTM tracking (no-JS fallback)** — on uncached pages, any request arriving with a `utm_*` parameter or an ad click ID (`gclid`, `fbclid`, `msclkid`, `ttclid`, `li_fat_id`, `twclid`) is also recorded server-side.
* **Custom parameters** — name up to ten extra URL parameters of your own (a coupon code, an affiliate ID, a promo code) under Settings → Tracking, and from then on they are captured exactly like the built-in UTM and click-ID parameters: recorded on the event, carried in the attribution cookie, order data and webhook payloads, offered to forms under their own names, exported as CSV columns, and settable per tracked link (see the custom parameters FAQ).
* **Bot filtering** — crawlers, link-preview unfurlers, and monitoring agents are recognised by user agent and never pollute your stats. Bots still receive the redirect, so social link previews keep working.
* **Click deduplication** — repeat hits from the same visitor with identical parameters within a 30-minute window (filterable) are recorded once.
* **First/last-touch attribution cookie** — campaign parameters or, for visits from a search engine, social network, AI assistant or other site, the channel and referrer host are persisted in a first-party cookie (`utmatra_attr`, 90 days) with separate first-touch and last-touch sets. Direct visits never write it.

**Links & sharing:**

* **Pretty short links** — every managed link gets a clean `/go/{slug}/` URL (query-string form on plain permalinks). The click is counted before the visitor is forwarded to the destination with full UTM parameters appended.
* **QR code per link** — generate a scannable QR code for any tracked link straight from the dashboard and download it as a PNG. Generated entirely in your browser — no external service is contacted.
* **[utmatra_link] shortcode** — embed tracked links in posts, pages, or widgets; supports enclosing content: `[utmatra_link id="sale"]Shop the <strong>sale</strong>[/utmatra_link]`.
* **UTM naming conventions** — the add-link form lowercases values and replaces spaces with `-` (with a heads-up notice), and suggests previously used sources, mediums, and campaigns — so `My Newsletter` and `my-newsletter` never fragment your reports into separate rows. Save a source/medium/term/content combination as a named preset (e.g. "Instagram Story") to apply it to future links in one click.

**Reporting:**

* **Chart.js dashboard** — clicks over time, breakdowns by source, campaign, medium, device type, channel (direct, organic search, paid search, paid social, organic social, email, referral, AI assistant, display, affiliate), top landing pages, top referrers, and ad platform, filterable by date range (7/30/90 days) and campaign. The Conversions tile counts WooCommerce orders **and** attributed form leads.
* **WooCommerce revenue attribution** — orders are stamped with the buyer's first- and last-touch campaign data at checkout (visible in a metabox on the order screen), the conversion is recorded once the order is paid, and a revenue-by-campaign chart shows what your campaigns actually earn. Revenue counts orders only; form leads are conversions without a total.
* **Customer journeys** — a Journeys tab lists recent orders and form leads with every tagged visit that preceded them, built from a random first-party visitor id.
* **Lead webhooks** — every attributed form submission is recorded as a conversion and can be POSTed, with its attribution data and submitted field values, to Zapier, Make, HubSpot or GoHighLevel (see the webhook FAQ).
* **CSV export** — download the raw events for any period as a CSV (the newest 10,000 rows), each row with its channel. Conversion rows are included alongside clicks, so the export can hold more rows than the dashboard click total. Exports never include IP hashes or user agents, and cell values are guarded against spreadsheet formula injection.
* **Read-only REST API** — every dashboard dataset (stats, links, events, journeys, export, diagnostics) is available over `utmatra/v1` to administrators using WordPress Application Passwords (see the REST API section).
* **Weekly email summary** — an optional (off by default) weekly email with total clicks, top campaigns, top sources, and revenue when WooCommerce is active.
* **Tracking Doctor diagnostics** — a "Run diagnostics" panel on the settings card runs five rule-based local checks (caching, consent, cookies, per-form attribution coverage, WooCommerce order attribution) and reports pass/warn/fail/skip with a plain-language explanation for each. No AI, no external calls — every check inspects local plugin/site state only.

**Forms & integrations:**

* **Form attribution capture** — add a hidden field named `utm_source` (or any other tracked parameter) to any form and it is auto-filled from the attribution cookie. Zero-config adapters for Contact Form 7, Gravity Forms, Ninja Forms, Elementor Pro Forms, WPForms, Fluent Forms, Formidable Forms, Forminator and Bricks forms.

**Privacy & housekeeping:**

* **Privacy-safe by design** — IP addresses are stored only as salted SHA-256 hashes; the salt is unique per site.
* **WP Consent API integration** — the attribution cookie is gated in the visitor's browser on a configurable consent category, deleted on withdrawal, and declared to the API so consent banners can list it.
* **Data retention** — events are pruned automatically after a configurable retention window (180 days by default, 0 = keep forever) from the dashboard settings card.
* **Suggested privacy policy text** — provided under Settings → Privacy → Policy Guide.
* **No external services by default** — no CDN calls and no third-party analytics. Chart.js, the QR library and Action Scheduler ship bundled with the plugin. The only outbound requests are the optional conversion webhooks, which stay off until you enter a receiver URL (see External services).
* **Reliable background jobs** — retention pruning and the weekly report run on Action Scheduler (the job queue WooCommerce uses), listed under Tools → Scheduled Actions in the `utmatra` group, and they reschedule themselves if they go missing (after a reactivation, or on a multisite subsite). Action Scheduler keeps its own `actionscheduler_*` tables and a once-a-minute WP-Cron queue runner; they are shared with any other plugin that uses it (such as WooCommerce) and are left in place on uninstall.

== Installation ==

1. Upload the `utmatra-utm-attribution-and-tracking` folder to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins > Installed Plugins**.
3. Go to **Utmatra** in the admin sidebar.

== Frequently Asked Questions ==

= Does this plugin store personal data? =

Raw IP addresses are never stored. Each IP address is combined with a secret per-site salt and hashed with SHA-256 before storage, so the stored hashes cannot be correlated with hashes from other sites. User agents and referrer URLs are stored as-is. This applies to the first page of each visit (landing page and referrer, recorded while "Track the first page of every visit" is on under Settings → Tracking) and to every tagged visit. A form submission that carries attribution is recorded as a conversion event with the same data as a campaign visit (hashed IP, user agent, referrer, the page the form was on) — never the submitted field values. Events are pruned automatically after a retention period (180 days by default, adjustable from the dashboard settings card; 0 keeps events forever). CSV exports never include IP hashes or user agents. If you configure a webhook, attributed orders and form submissions, including the submitted form fields, are sent to that external service. Suggested privacy policy text is provided under Settings → Privacy → Policy Guide.

= How does tracking work on a cached site? =

Full-page caches serve HTML without running PHP, which breaks purely server-side trackers. Utmatra ships a small beacon script in every front-end page: when the visitor's URL carries a tracked parameter, or on the first page of a visit, the beacon reports it to a public REST endpoint. On uncached pages a server-side fallback records tagged visits even for visitors without JavaScript; it does not record untagged landings, because PHP has no per-session marker to tell a landing from the next page view and would count every page of a no-JS visit. Deduplication ensures a tagged visit recorded by both paths is only counted once.

Contact Form 7, Gravity Forms, Ninja Forms and Elementor Pro fill their attribution fields when the page is generated, so a cached page carries the campaign of whoever loaded it first. When the form is submitted, the values the plugin filled in are replaced with the submitter's own attribution (or the field's default when there is none), so entries and notification emails do not show another visitor's campaign. Values you supply yourself are kept: a Gravity Forms parameter in the page URL or `field_values` (and Gravity Forms REST or API submissions are left as sent), an Elementor dynamic-tag default when the visitor has no attribution, and any Contact Form 7 form tag you added under an attribution field name. Bricks resolves its tags when the page is generated and is not corrected on submit.

= How do I create a tracked short link? =

Add a link under **Tracked Links** on the dashboard. With pretty permalinks enabled the link is served at `/go/{slug}/`; on plain permalinks it falls back to `/?utmatra_track={slug}`. Either URL records the click and 302-redirects the visitor to the destination with the link's UTM parameters appended. You can copy the URL, embed it with `[utmatra_link id="my-link" label="Click here"]`, or use the QR button to download a scannable PNG for print, packaging, or slides.

You can also use the drawer as a plain UTM builder: switch on **Build a URL only** and the live preview becomes a tagged URL you copy with the submit button — no short link is created and nothing is saved. Presets apply in both modes, and link-level custom parameters (see the next FAQ) are appended too.

= Can I track my own URL parameters (coupon, affiliate ID, promo code)? =

Yes. Under **Settings → Tracking → Custom parameters**, enter up to ten names separated by commas (for example `coupon, aff, promo2`). Names must start with a letter and use lowercase letters, digits and underscores (up to 32 characters); input is lowercased on save. From then on a URL such as `/?coupon=SPRING10` is treated exactly like a tagged visit: the value is recorded on the event (in a JSON column, one key per parameter), carried in the attribution cookie and in webhook payloads, offered to forms as `<name>` and `<name>_first` fields, exported as its own CSV column, and available as an optional per-link field on tracked links — a link with a coupon value set appends `?coupon=…` to the destination on redirect. Values are stored as-is (case-sensitive, up to 255 characters), so `SPRING10` never becomes `spring10`.

Some names are reserved and rejected with a message: the built-in parameters, anything starting with `utm_`, `utmatra_` or `first_`, anything ending in `_first`, the plugin's own field and touch names (`landing_page`, `channel`, `lp`, `params`, …), events-table columns, common form and credential field names (`email`, `name`, `password`, …) and WordPress public query variables (`s`, `p`, `page`, …).

Three things to know:

* **Do not name a parameter after a form field or after anything that identifies a person** (an email address, a phone number, a name). Whatever the URL parameter carries is stored on the event, placed in the attribution cookie and written into any form input that shares the parameter's name — a visible input named like a custom parameter is pre-filled from the cookie on every page, which is the point for coupon boxes but the wrong tool for personal data. The reserved list blocks the most common collisions, but you are naming a field that lives in the same namespace as real HTML form fields.
* **Tracking Doctor may report forms as "partial".** Every form adapter that does not inject fields itself (Gravity Forms, Ninja Forms, Elementor, Fluent Forms, Formidable, Forminator, Bricks) compares the form's fields against the full field list, so once you define a custom parameter those forms count as missing `coupon` and `coupon_first` until you add the fields. Contact Form 7 and WPForms inject the fields automatically and stay "complete". This is the check working as intended: the form really does not capture the parameter yet.
* **On cached sites the list reaches visitors when the page cache regenerates.** The parameter list is baked into the page HTML, so a newly added parameter is captured on cached pages only after the cache is purged, and a removed parameter keeps being written into the attribution cookie by the stale page (the server ignores the value) until then. Adding and removing a parameter takes effect on uncached pages immediately.

= Are clicks from bots and link previews counted? =

No. Requests whose user agent looks like a crawler, link-preview unfurler (Slack, WhatsApp, Facebook, etc.), or monitoring tool are excluded from your stats. They still receive the redirect so previews resolve correctly. Adjust the verdict with the `utmatra_is_bot` filter.

= What does the attribution cookie store? =

When a visitor arrives with a UTM parameter or ad click ID, or lands from another site (a search engine, social network, AI assistant or any other referrer), the plugin sets a first-party cookie named `utmatra_attr` containing two sets of campaign data: the visitor's **first touch** (preserved once written) and **last touch** (updated on every tagged or referred visit). Each set holds the whitelisted UTM/click-ID parameters present on that visit, or the channel and referrer host of an untagged landing, plus the landing page URL and a timestamp. A direct visit without parameters never writes or updates the cookie. The cookie does not store names, email addresses, or IP addresses.

The cookie lives for 90 days (`path=/`, `SameSite=Lax`, `Secure` on https sites). Change the lifetime with `utmatra_cookie_days` or disable the cookie entirely with `utmatra_enable_cookie` (see the filter reference below).

A second cookie, `utmatra_vid`, holds a random visitor id (UUID v4) generated in the browser — from the browser's cryptographically secure random source, falling back to JavaScript's `Math.random()` only in browsers that lack it — or by the server-side fallback for visitors without JavaScript. It is not derived from your IP address or any personal data, and it is written, refreshed and deleted under exactly the same conditions and lifetime as `utmatra_attr`. It links a visitor's tagged visits to their later orders and form submissions for the Journeys report. On sites with a consent banner, visits recorded before the visitor consents carry no visitor id, so a journey begins with the first tagged visit after consent.

= How does the plugin handle cookie consent? =

The plugin does not ship a consent banner. If a plugin implementing the [WP Consent API](https://github.com/rlankhorst/wp-consent-level-api) is active (Complianz, Real Cookie Banner, Borlabs Cookie, and others implement it), the attribution cookie is gated on the visitor's consent:

* **The decision is made in the browser.** The front-end beacon asks the Consent API's JavaScript whether the visitor granted the configured category before it writes the cookie, so gating is correct even on pages served from a full-page cache. The PHP fallback writer (for visitors without JavaScript) applies the same rule per request.
* **Consent category.** Choose which category gates the cookie under Settings → Data and privacy → Consent: *Marketing* (the default — the cookie carries advertising click IDs), *Statistics*, or *Marketing or statistics*.
* **Withdrawal deletes the cookie.** When the visitor withdraws consent, the beacon deletes the attribution cookie immediately.
* **While the banner is undecided** no cookie is written. The landing visit's campaign data is kept in page memory only and written to the cookie the moment consent is granted on that page; forms are not pre-filled from an existing cookie until the visitor decides. A visitor who navigates away before deciding and grants consent on a later page is attributed from their next tagged visit. The beacon's per-tab duplicate guards for the collect request (the `utmatra_sent` marker, which holds the URL's own query string, including any UTM values and click IDs, and the `utmatra_visit` flag that records that the tab is already inside the site, having reported its landing or been opened from an internal link) are kept in session storage, which lasts only for the life of the tab, and are unaffected by consent.
* **Events are recorded regardless of consent** by default: a tagged-visit event and an untagged landing visit carry no cookie and only a salted IP hash. Return `true` from the `utmatra_consent_gate_events` filter to withhold events as well until the visitor consents.
* The plugin declares itself to the Consent API and describes the cookie (name, category, lifetime, purpose) so consent banners can list it.

Without a Consent API plugin the cookie is written on every tagged visit and every landing from another site; gate it yourself by returning `false` from `utmatra_enable_cookie`, or install a consent plugin that implements the API.

Two notes for sites with a full-page cache:

* On sites with a full-page cache, a change to the consent category reaches visitors after the cache is purged.
* After deactivating a Consent API plugin, purge the page cache: cached HTML that still advertises the API makes the beacon hold the touch until the visitor's next uncached load.

= How does WooCommerce order attribution work? =

When a visitor who previously arrived through a tracked campaign or from another site places an order, the order is stamped at checkout with their first- and last-touch attribution sets (order meta, shown in a "Campaign attribution" metabox on the order edit screen). The conversion event with the order total is recorded, and the conversion webhook sent, once the order is paid: when the gateway completes payment, or when the order moves to a paid status such as processing or completed (cash on delivery, or bank transfer once marked paid). Declined, abandoned and unpaid orders are never counted, and each order is counted once. Each touch carries `ch` (the channel) and, when known, `rh` (the referrer host) alongside the campaign parameters, in the order meta and in the conversion webhook payload. The dashboard then shows a revenue-by-campaign chart, and the weekly email includes a revenue table. No configuration needed — it activates automatically when WooCommerce is present.

= How are channels classified? =

Every recorded visit, click and conversion is filed into one channel when it is written, following the rule order of Google Analytics' default channel grouping with one addition for AI assistants: a `gclid` or `msclkid` is **paid search**; a source naming an AI assistant is **AI assistant**; a display medium (`display`, `banner`, `cpm`, …) is **display**; a paid medium (`cpc`, `ppc`, `paid-social`, …) is **paid search**, **paid social** or **paid (other)** depending on the source; a social medium or source is **organic social**; an `organic` medium or search-engine source is **organic search**; an `email` or `newsletter` source or medium is **email**; `affiliate` is **affiliate**; `referral`, `app` or `link` is **referral**; a Facebook, TikTok, LinkedIn or X click ID without a medium is **paid social**; otherwise the referrer host decides (AI assistant, search engine, social network, or **referral** for any other site); anything else with campaign data is **other** and a visit with neither is **direct**.

Three host lists drive the referrer rules and can be extended with the `utmatra_ai_assistant_hosts`, `utmatra_search_engine_hosts` and `utmatra_social_network_hosts` filters. AI assistants include ChatGPT, Perplexity, Claude, Gemini, Copilot, You.com, Phind, Poe, Meta AI, Mistral, Grok, DeepSeek, Duck.ai and Kimi; ChatGPT and Perplexity also tag their outbound links with `utm_source`, which the source rules recognise. Brave Search counts as organic search, because its AI answers render on the normal results page and the referrer cannot tell them apart. Facebook appends `fbclid` to organic clicks too, so an untagged Facebook click reads as paid social; override any verdict with the `utmatra_channel` filter. The channel is derived from the visitor's own referrer, so a Google Mail or Google Docs link is filed as organic search, as in GA4.

Untagged landings are reported by the front-end beacon only (no PHP fallback, see the cached-site FAQ) and share the collect endpoint's rate limit with tagged visits; raise it with `utmatra_rate_limit` when many visitors share one IP.

= How do I capture attribution in my lead forms? =

Every tracked parameter is available to forms under its own standard name: `utm_source`, `utm_medium`, `utm_campaign`, `utm_id`, `utm_term`, `utm_content`, `gclid`, `fbclid`, `msclkid`, `ttclid`, `li_fat_id`, `twclid`, `landing_page` (the landing page URL), `channel` and `referrer_host` — plus every custom parameter you define, under its own name (for example `coupon` and `coupon_first`). These hold the visitor's **last touch**; append `_first` (e.g. `utm_source_first`, `landing_page_first`) for the **first touch**.

**Any form plugin (generic):** add a hidden input (or text field) with one of the names above and it is auto-filled from the attribution cookie on page load. Fields that already have a value are never overwritten. Forms injected into the page after load (some popup builders) are not filled automatically. The cookie carries `channel` only for visits without campaign parameters, so the generic fill populates it for organic, social, AI-assistant and referral visitors; the server-side adapters below always have it.

**Contact Form 7:** zero configuration — the last-touch fields are appended to every form as hidden fields automatically and arrive with each submission. Use special mail tags in your mail templates to include them in notification emails: `[_utm_source]`, `[_utm_campaign]`, `[_gclid]`, `[_landing_page]`, `[_channel]`, `[_referrer_host]`, etc. (any field name above with a leading underscore works, including `_first` variants such as `[_utm_source_first]`).

**Gravity Forms:** add a Hidden field, enable "Allow field to be populated dynamically" under the field's Advanced tab, and set the parameter name to e.g. `utm_source` or `utm_source_first`.

**Ninja Forms:** add a Hidden field to your form and set its default value to `{utmatra:utm_source}` (or any other tracked parameter, e.g. `{utmatra:gclid}`, `{utmatra:landing}`, `{utmatra:channel}`, `{utmatra:referrer_host}`, `{utmatra:utm_source_first}`) to capture attribution.

**Elementor (Pro Forms):** add a Hidden field to your form (Advanced tab), set its Field ID to `utm_source` (or any other tracked parameter name, e.g. `utm_source_first` for first touch), and it is auto-filled from attribution data on render. Requires Elementor Pro — free Elementor has no form builder.

**Fluent Forms:** add a Hidden Field and set its *Name Attribute* to e.g. `utm_source` (or `utm_source_first`). It is filled from the attribution cookie by the page script on load. Submissions are listed in the dashboard widget automatically.

**WPForms (Lite and Pro):** zero configuration — every attribution field above is added to each form as an empty hidden input and filled on page load, so the values arrive with each submission. To put them in notification emails, add the smart tags `{utm_source}`, `{utm_campaign}`, `{landing_page}` … (any field name above, including `_first` variants) to the notification subject or message. To store a value in an entry (Pro), set one of those tags as the default value of a Hidden Field (Lite: a Single Line Text field); it is filled on page load, not when the page is generated. If "Optimize Email Sending" is enabled, notification emails are sent later in the background, where the tags cannot be resolved — use a field default instead.

**Formidable Forms:** add a Hidden field and, under Advanced, set its *Field Key* to e.g. `utm_source` (Formidable may append a number on other forms, such as `utm_source2` — that still works). The field is filled on page load.

**Forminator:** add a Hidden Field, set *Default Value* to "Query parameter" and the *Query parameter* to e.g. `utm_source` (or `utm_source_first`). The field is filled on page load; a matching URL parameter on the current page takes precedence. Forms set to "Load form using AJAX" are inserted after page load and are not filled.

**Bricks Builder:** add a Hidden field to the Form element and set its value to the dynamic data tag `{utm_source}` (all field names above are listed under the "Utmatra" group of the dynamic data picker). To list the submission as a lead in the dashboard widget, add the "Custom" action under "Actions after successful form submit". The tag is resolved when the page is generated, so on a fully page-cached site the stored value may be stale. Bricks support is untested against a live Bricks install; please report problems.

= Can I send leads and orders to Zapier, Make, HubSpot or GoHighLevel? =

Yes. Under **Settings → Webhooks**, paste your receiver's URL, pick a preset (Generic JSON, Zapier, Make, HubSpot Forms, GoHighLevel) and use the "Send for" switches to enable or disable WooCommerce orders and form leads independently (both are on by default; the HubSpot preset sends leads only). Because the payloads carry the submitted form fields, use an `https://` URL so the values are not sent in cleartext. Every request is a JSON POST signed with an `X-Utmatra-Signature` header (sha256 = HMAC of the body over a per-site secret shown on the same card) and tagged with an `X-Utmatra-Event` header (`conversion`, `lead` or `test`), so any receiver can verify and route it — compare the signature with a constant-time check (e.g. PHP's `hash_equals()`). **Send test webhook** posts a synthetic payload in the selected preset's shape before you rely on it. Lead webhooks are sent as the form is submitted; order webhooks are sent by the background queue (Action Scheduler) once the order is paid, usually within a minute.

A lead payload carries the integration (e.g. `contact-form-7`), the form id and title, the **submitted form field values keyed by label** (up to 50 fields of 1,000 characters each; file uploads, passwords and hidden fields are never sent), the first- and last-touch attribution sets, the page URL and a timestamp. The plugin itself never stores the submitted field values — they travel only in the webhook request. One exception: when a receiver answers with an error, a short excerpt of its response is kept under "Last delivery" so the failure can be diagnosed, and such an error message may quote a submitted value (HubSpot, for instance, echoes an invalid email address back). Values that are arrays (checkbox groups) arrive comma-joined; deeply nested values arrive as a JSON string. Field forwarding for Gravity Forms and Elementor Pro is derived from the vendors' documentation, untested against a live install.

The Generic JSON preset (also used by Zapier and Make, whose receivers parse nested JSON themselves) sends the payload as-is. The HubSpot preset converts it into a HubSpot Forms API v3 submission: it needs a HubSpot form carrying fields named `email`, `firstname`, `lastname`, `phone` and hidden `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content` (HubSpot rejects submissions with fields the form does not have — the error shows under "Last delivery"); when the visitor carries HubSpot's own `hubspotutk` tracking cookie it is forwarded so the submission links to their visitor record. The GoHighLevel preset flattens the payload into one level of snake_case keys (`email`, `phone`, `first_name`, `last_name`, `utm_*`, `first_utm_*`, `landing_page`, `field_<name>`), as its Inbound Webhook trigger requires. The HubSpot and GoHighLevel request shapes follow the vendors' documentation and have not been verified against a live account.

= How do I export my data? =

Click **Export CSV** on the dashboard to download the raw events for the selected period and campaign filter (the newest 10,000 rows are exported). The columns, in order: `clicked_at`, `link_id`, `channel`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_id`, `utm_term`, `utm_content`, `gclid`, `fbclid`, `msclkid`, `ttclid`, `li_fat_id`, `twclid`, `destination`, `referrer`, `is_conversion`, `order_total`, `visitor_id`, then one column per configured custom parameter (in saved order). The `channel` column and the custom-parameter columns carry values only once those features are active; `visitor_id` is empty on events recorded before this version. The export never includes IP hashes or user agents. Because the export also includes conversion rows (flagged in the `is_conversion` column) — WooCommerce orders (`link_id` order) and form leads (`link_id` lead) — alongside click rows, its total row count can exceed the click total shown on the dashboard. Untagged landing visits are left out unless the REST call to `utmatra/v1/export` passes `include_visits=1`. Values that could be interpreted as spreadsheet formulas are prefixed with `'` for safety.

= How do I get a weekly report by email? =

Enable "Send a weekly campaign summary email" on the dashboard settings card (it is off by default — no unsolicited mail). Once a week the site admin email receives an HTML summary with total clicks, the top 5 campaigns and sources, and revenue by campaign when WooCommerce is active. Change the recipient with the `utmatra_report_recipient` filter. Delivery goes through wp_mail(), so any SMTP plugin you use applies.

= My site is behind a reverse proxy or CDN — anything to configure? =

Yes. Behind a proxy (Cloudflare, Varnish, a load balancer), `REMOTE_ADDR` is the proxy's IP, not the visitor's. Click deduplication and the collect endpoint's rate limiting key on a hash of that IP — so with the default behaviour all visitors would share the proxy's identity, over-deduplicating clicks and tripping the rate limit for everyone. Use the `utmatra_client_ip` filter to return the real client IP from the header your proxy sets (e.g. `CF-Connecting-IP`, or the first entry of `X-Forwarded-For`). Only trust forwarded headers when every request passes through a proxy that overwrites them — they are client-spoofable otherwise. If configuring the real IP is not possible, raise the shared ceiling with the `utmatra_rate_limit` filter instead.

= Why are my UTM values being changed when I save a link? =

Values are normalized for consistent reporting: lowercased, with whitespace replaced by `-`. Analytics tools treat `Newsletter`, `newsletter`, and `News letter` as three different sources, silently fragmenting reports. Normalization (applied in the form and again server-side) keeps every report row canonical.

== Screenshots ==

1. Overview: clicks over time against the previous period, conversions, attributed revenue, and breakdowns by source, campaign, medium, device, channel, landing page, referrer and ad platform.
2. Links: tracked short links with their campaign, source and medium, click counts and redirect URLs.
3. Journeys: recent orders and form leads with their first and last touch, expandable into the full touch timeline.
4. Add tracked link: UTM fields with presets, the generated short link, and a build-a-URL-only mode.
5. Settings: tracking, custom parameters, data retention, attribution window, consent, weekly report and webhooks.
6. Tracking Doctor: local checks for caching, consent, cookies, form coverage and WooCommerce attribution.
7. Short links and QR codes: each tracked link gets a pretty /go/ short link and a QR code generated in the browser, with a PNG download.
8. UTM naming presets: a saved preset fills source and medium in one click, and values are lowercased with spaces replaced by hyphens.
9. Build a URL only: get a tagged URL to paste anywhere, without creating a short link.
10. Edit tracked link: the short link, the final tagged URL and the [utmatra_link] shortcode to embed it.
11. The [utmatra_link] shortcode in the block editor, wrapping custom link text.

== External services ==

The plugin contacts no external service on its own. Tracking, reports, QR codes and charts all run on your site. The one exception is the optional **conversion webhook**, which is off until an administrator saves a receiver URL under Settings → Webhooks. Once a URL is saved, the site sends a JSON POST request to that URL, and only to that URL:

* **When a tracked form is submitted** (lead event): the site URL, the form plugin, form id and title, the submitted field values (these can include a name, email address and phone number; file uploads, passwords and hidden fields are never sent), the visitor's first- and last-touch campaign data (UTM parameters, ad click IDs, custom parameters, channel, referrer host and landing page), the random visitor id, the page URL and a timestamp.
* **When an attributed WooCommerce order is paid** (order event): the site URL, the order id, total and currency, the first- and last-touch campaign data and a timestamp. No customer details are sent.
* **When an administrator clicks "Send test webhook"**: a synthetic sample payload.

Order and lead events can each be switched off. The presets only change the format of the request body; a service is contacted only if you paste one of its URLs. When you use one of these services, its terms and privacy policy apply to the data it receives:

* **Zapier** (Catch Hook URL, `hooks.zapier.com`): [Terms of service](https://zapier.com/legal), [Privacy policy](https://zapier.com/privacy).
* **Make** (custom webhook URL, `hook.*.make.com`): [Terms and conditions](https://www.make.com/en/terms-and-conditions), [Privacy notice](https://www.make.com/en/privacy-notice).
* **HubSpot** (Forms API v3, `api.hsforms.com`): leads only, mapped onto your HubSpot form fields. When the visitor has HubSpot's own `hubspotutk` cookie, its value is included so HubSpot can link the submission to that visitor. [Terms of service](https://legal.hubspot.com/terms-of-service), [Privacy policy](https://legal.hubspot.com/privacy-policy).
* **GoHighLevel** (Inbound Webhook trigger, `services.leadconnectorhq.com`): [Terms of service](https://www.gohighlevel.com/terms-of-service), [Privacy policy](https://www.gohighlevel.com/privacy-policy).

== Source code and third-party libraries ==

The full, human-readable source of this plugin, including the bundled libraries, is public on GitHub: [github.com/mahlamusa/utmatra-utm-attribution-and-tracking](https://github.com/mahlamusa/utmatra-utm-attribution-and-tracking).

Bundled libraries (the two JavaScript files ship minified as published by their authors; their unminified source is linked below):

* **Chart.js** 4.5.1 (`assets/js/vendor/chart.umd.min.js`), MIT license. Source: [github.com/chartjs/Chart.js](https://github.com/chartjs/Chart.js).
* **QR Code Generator** by Kazuhiko Arase (`assets/js/vendor/qrcode.min.js`), MIT license. Source: [github.com/kazuhikoarase/qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator).
* **Action Scheduler** (`vendor/woocommerce/action-scheduler`), GPL-3.0, installed with Composer from `composer.json`. Source: [github.com/woocommerce/action-scheduler](https://github.com/woocommerce/action-scheduler).

== Filter and action reference ==

All filters shipped by the plugin:

* **`utmatra_track_logged_in_users`** *(bool, default `false`)* — return `true` to record clicks from logged-in administrators (excluded by default to keep test traffic out of your stats).
    `add_filter( 'utmatra_track_logged_in_users', '__return_true' );`
* **`utmatra_is_bot`** *(bool, receives the user agent string)* — override the bot verdict for a user agent. Bots are redirected but never recorded.
* **`utmatra_client_ip`** *(string)* — the client IP used for hashing, deduplication, and rate limiting. Defaults to `REMOTE_ADDR`; see the reverse proxy FAQ above.
* **`utmatra_dedup_window`** *(int, seconds, default 1800)* — the window within which an identical click from the same visitor is recorded once. Return `0` to disable deduplication.
* **`utmatra_rate_limit`** *(int, requests/minute/IP, default 15)* — the collect endpoint's rate limit. Raise it when many visitors share one IP (see the reverse proxy FAQ above).
* **`utmatra_retention_days`** *(int, days, default from the settings card / 180)* — the event retention window. `0` keeps events forever.
* **`utmatra_cookie_days`** *(int, days, default 90)* — the lifetime of both attribution cookies (`utmatra_attr`, `utmatra_vid`). Defaults to the Attribution window setting on the dashboard; the filter overrides it.
* **`utmatra_enable_cookie`** *(bool, receives `true`)* — site-wide master switch for both attribution cookies (`utmatra_attr`, `utmatra_vid`); return `false` to never set them. Per-visitor consent is handled separately through the WP Consent API (see the consent FAQ).
* **`utmatra_consent_gate_events`** *(bool, default `false`)* — return `true` to also withhold event recording for tagged visits and untagged landing visits (the collect request and the PHP fallback) until the visitor grants the configured consent category.
* **`utmatra_report_recipient`** *(string, default the admin email)* — the recipient address of the weekly summary email.
* **`utmatra_channel`** *(string, receives the computed channel slug and the event row)* — override the channel a visit, click or conversion is filed under; return one of `direct`, `organic_search`, `paid_search`, `paid_social`, `organic_social`, `email`, `referral`, `ai_assistant`, `display`, `affiliate`, `paid_other` or `other` (anything else keeps the computed value).
* **`utmatra_search_engine_hosts`** *(string[])* — the referrer hosts classified as search engines. An entry with a dot matches the host and its subdomains; an entry without a dot (`google`) matches any label of the host.
* **`utmatra_social_network_hosts`** *(string[])* — the referrer hosts classified as social networks, same matching rule.
* **`utmatra_ai_assistant_hosts`** *(string[])* — the referrer hosts classified as AI assistants, same matching rule; checked before the search-engine list.

Actions:

* **`utmatra_lead_recorded`** *(receives the integration slug and the lead record)* — fires after an attributed form submission has been recorded as a lead conversion. The record holds `form_id`, `form_title`, `posted` (the attribution data), `created_at`, `page_url` and `fields` (the cleaned submitted values, which the plugin itself never stores). The lead webhook listens on this action.

== REST API ==

Every dataset the dashboard shows is available read-only over the `utmatra/v1` REST namespace. All routes are GET, require an administrator (`manage_options`), and work with [Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/): create one under **Users → Profile → Application Passwords** (needs HTTPS, or `WP_ENVIRONMENT_TYPE=local` on a development site), then authenticate with it as the user:

`curl -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" "https://example.com/wp-json/utmatra/v1/events?period=30&kind=order&per_page=100"`

Logged-in administrators can also call the routes from the dashboard (cookie plus nonce authentication) — that is how the plugin's own JS loads its data.

**Conventions.** All timestamps are UTC `YYYY-MM-DD HH:MM:SS`. The `custom` and `meta` columns are always JSON objects (`{}` when empty). `ip_hash` and `user_agent` are never returned by any route. Invalid arguments return HTTP 400 with WordPress's standard envelope, e.g. `{ "code": "rest_invalid_param", "message": "Invalid parameter(s): kind", "data": { "status": 400, "params": { "kind": "…" } } }`. Unauthenticated requests get 401; authenticated non-administrators get 403. `visit` rows appear only while "Track the first page of every visit" is on; `channel` values and `custom` parameter keys appear on rows recorded since those features were configured.

**`GET /stats`** — args `period` (days, default 30), `campaign`, `since`/`until` (`Y-m-d`, a custom range overriding `period`). Response: `clicks_by_day[]` (`date`, `count`, `conversions`), `by_source[]`, `by_campaign[]`, `by_medium[]`, `by_device[]`, `by_channel[]`, `by_landing[]`, `by_platform[]`, `by_referrer[]` (each `label` + `count`), `revenue_by_campaign[]` (`label`, `orders`, `revenue`), `total`, `previous_total`, `previous_clicks_by_day[]`, `conversions`, `all_time_total`, `campaigns[]`.

**`GET /links`** — response `links[]` (`id`, `label`, `destination`, `destination_raw`, `redirect_url`, `clicks`, `utm_campaign`, `utm_source`, `utm_medium`, `utm_id`, `utm_term`, `utm_content`, `custom` object), `suggestions` (`sources`, `mediums`, `campaigns`), `presets[]`.

**`GET /export`** — args `period`, `campaign`, `since`, `until`, `include_visits` (bool, default false). Response `rows[]` with the CSV column set listed in the export FAQ (plus one key per configured custom parameter), capped at the newest 10,000 rows.

**`GET /diagnostics`** — response `checks[]`: `id`, `label`, `status` (one of pass/warn/fail/skip), `message`, `details`.

**`GET /events`** — args `page` (default 1), `per_page` (default 50, capped at 200), `period`, `campaign`, `since`, `until`, `channel` (matches the stored channel slug only), `kind` (one of click, visit, order, lead), `visitor_id` (UUID format; any version — a non-v4 value simply matches no rows). Response `events[]` (newest first), `total`, `page`, `per_page`, `total_pages`. Each event carries `id`, `link_id`, `visitor_id`, `clicked_at`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_id`, `utm_term`, `utm_content`, `gclid`, `fbclid`, `msclkid`, `ttclid`, `li_fat_id`, `twclid`, `destination`, `referrer`, `is_conversion` (bool), `touch`, `order_total` (number or null), `device`, `channel`, `custom` (object), `meta` (object) and the derived `kind` (click, visit, order or lead — `link_id` `lead` is a form submission, `order` or any legacy conversion row is an order, `visit` is an untagged landing, everything else is a click).

**`GET /journeys`** — args `page` (default 1), `per_page` (default 50, capped at 100), `period`, `since`, `until`. Response `journeys[]` (newest conversion first), `total`, `page`, `per_page`, `total_pages`, `without_visitor` (conversions in range with no visitor id). Each journey: `conversion` (an event object), `visitor_id`, `first_touch` and `last_touch` (event objects or null), `touch_count` (clicks and visits only), `touches[]` (the most recent 100 preceding events, ascending, earlier conversions included as steps) and `touches_truncated`.

**`GET /journeys/{visitor_id}`** — the path parameter is a UUID v4 (anything else misses the route and 404s at the router). Response `visitor_id`, `events[]` (ascending, at most 500), `conversions`, `first_seen`, `last_seen`, `truncated`. An unknown visitor id returns HTTP 404 with `code: utmatra_not_found`.

== Changelog ==

= 1.0.0 =
* Initial release.
* Cache-proof tracking beacon with a no-JS PHP fallback, bot filtering and click deduplication.
* Ad click-ID capture (gclid, fbclid, msclkid, ttclid, li_fat_id, twclid) alongside UTM tags.
* First/last-touch attribution cookie, gated on the WP Consent API.
* Channel classification, including AI assistants, and untagged landing-visit recording.
* Pretty short links with QR codes, the [utmatra_link] shortcode, UTM naming presets and a build-a-URL mode.
* Custom URL parameters captured alongside UTM tags.
* WooCommerce revenue attribution with an attribution metabox on the order screen, and form lead capture for Contact Form 7, Gravity Forms, Ninja Forms, Elementor Pro, WPForms, Fluent Forms, Formidable, Forminator and Bricks.
* Lead and order webhooks for Zapier, Make, HubSpot and GoHighLevel.
* Customer journeys built from a consent-gated visitor id, and a read-only REST API.
* Tabbed Chart.js dashboard with breakdowns by source, campaign, medium, channel, device, landing page, referrer and ad platform, plus a revenue-by-campaign chart.
* Recent leads widget on the WordPress admin Dashboard.
* CSV export, weekly email summary, Tracking Doctor diagnostics and data retention.
* IP addresses stored only as salted SHA-256 hashes, and suggested privacy policy text.
* Background jobs on bundled Action Scheduler, and no external services or CDN calls by default.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
