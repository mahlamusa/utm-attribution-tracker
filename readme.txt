=== UTM & Attribution Tracker ===
Contributors:      mahlamusa
Tags:              utm, analytics, campaign, tracking, links
Requires at least: 6.0
Tested up to:      6.7
Requires PHP:      8.0
Stable tag:        1.0.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Track UTM-tagged links with a live Chart.js dashboard — no third-party analytics required.

== Description ==

UTM & Attribution Tracker gives you self-hosted, privacy-respecting click tracking for any UTM-tagged link on or off your WordPress site.

**Features:**

* **Passive UTM tracking** — any page load arriving with `utm_campaign` in the URL is recorded automatically. No code changes needed.
* **First/last-touch attribution cookie** — campaign parameters are persisted in a first-party cookie (`utmat_attr`, 90 days) with separate first-touch and last-touch sets, ready to attach attribution to conversions.
* **Form attribution capture** — add a hidden field named `utmat_source` (or any other tracked parameter) to any form and it is auto-filled from the attribution cookie. Zero-config adapters for Contact Form 7 and Gravity Forms.
* **Redirect tracking** — create managed links (e.g. `/?utmat_track=my-link`) that record a click before forwarding the visitor to the destination with full UTM parameters intact.
* **Chart.js dashboard** — a clean admin dashboard with:
  * Clicks over time (line chart)
  * Breakdown by UTM source (doughnut)
  * Breakdown by campaign (bar)
  * Breakdown by medium (bar)
* **Date-range filter** — view 7, 30, or 90 days at a glance.
* **Campaign filter** — drill into a single campaign.
* **Tracked Links table** — add and delete managed links, and copy redirect URLs to clipboard in one click.
* **[utmat_link] shortcode** — embed tracked links in posts, pages, or widgets.
* **the companion SMTP plugin integration** — when the companion SMTP plugin is active, the tracker appears as a submenu item within the the companion SMTP plugin admin panel.
* **Privacy-safe** — IP addresses are stored as SHA-256 hashes only.
* **No external dependencies** — no Composer, no npm build step. Chart.js ships bundled with the plugin.

**Filter: `utmat_track_logged_in_users`**

By default, clicks from logged-in administrators are not recorded (to keep test traffic out of your stats). Return `true` from this filter to track admin clicks:

```php
add_filter( 'utmat_track_logged_in_users', '__return_true' );
```

== Installation ==

1. Upload the `utm-attribution-tracker` folder to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins > Installed Plugins**.
3. Go to **UTM Tracker** in the admin sidebar (or under **the companion SMTP plugin** if that plugin is active).

== Frequently Asked Questions ==

= Does this plugin store personal data? =

Raw IP addresses are never stored. Each IP address is combined with a secret per-site salt and hashed with SHA-256 before storage, so the stored hashes cannot be correlated with hashes from other sites. User agents and referrer URLs are stored as-is. Events are pruned automatically after a retention period (180 days by default). Suggested privacy policy text is provided under Settings → Privacy → Policy Guide.

= What does the attribution cookie store? =

When a visitor arrives with a UTM parameter or ad click ID, the plugin sets a first-party cookie named `utmat_attr` containing two sets of campaign data: the visitor's **first touch** (preserved once written) and **last touch** (updated on every tagged visit). Each set holds the whitelisted UTM/click-ID parameters present on that visit plus the landing page URL and a timestamp. The landing page URL is stored and may include query parameters from the address the visitor arrived on. The cookie does not store names, email addresses, or IP addresses.

The cookie lives for 90 days (`path=/`, `SameSite=Lax`, `Secure` on https sites). Change the lifetime with:

```php
add_filter( 'utmat_cookie_days', function () { return 30; } );
```

Or disable the cookie entirely:

```php
add_filter( 'utmat_enable_cookie', '__return_false' );
```

**Consent note:** the plugin does not ship a consent banner and does not integrate with consent managers. If your jurisdiction requires consent before setting non-essential cookies, gate the cookie yourself by returning `false` from `utmat_enable_cookie` until consent is given.

= How do I capture attribution in my lead forms? =

Every tracked parameter is available to forms under a `utmat_*` field name: `utmat_source`, `utmat_medium`, `utmat_campaign`, `utmat_id`, `utmat_term`, `utmat_content`, `utmat_gclid`, `utmat_fbclid`, `utmat_msclkid`, `utmat_ttclid`, and `utmat_landing` (the landing page URL). These hold the visitor's **last touch**; append `_first` (e.g. `utmat_source_first`) for the **first touch**.

**Any form plugin (generic):** add a hidden input (or text field) with one of the names above and it is auto-filled from the attribution cookie on page load. Fields that already have a value are never overwritten. Forms injected into the page after load (some popup builders) are not filled automatically.

**Contact Form 7:** zero configuration — the last-touch fields are appended to every form as hidden fields automatically and arrive with each submission. Use special mail tags in your mail templates to include them in notification emails: `[_utmat_source]`, `[_utmat_campaign]`, etc. (any field name above works, including `_first` variants).

**Gravity Forms:** add a Hidden field, enable "Allow field to be populated dynamically" under the field's Advanced tab, and set the parameter name to e.g. `utmat_source` or `utmat_source_first`.

**Fluent Forms:** add a Hidden Field and set its *Name Attribute* to e.g. `utmat_source` — the generic layer fills it on page load.

**WPForms:** WPForms does not expose a field's HTML `name`, so the generic layer cannot target it. Use a Hidden Field with the `{query_var key="utm_source"}` Smart Tag to capture parameters from the URL the form is submitted on.

= How do I create a tracked redirect link? =

Managed links are seeded on activation. Use the slug in the shortcode:
`[utmat_link id="my-link" label="Click here"]`

Or link directly to `/?utmat_track=my-link` in any HTML or email.

= Can I track outbound links to other sites? =

Yes — set the `destination` field of a managed link to any URL. The plugin records the click and then redirects the visitor to that destination with UTM parameters appended.

= Can I add or remove tracked links from the dashboard? =

Yes — use the "Add tracked link" form under Tracked Links to create a new managed link, or the Delete button on any row to remove one. Deleting a link removes the managed link definition only; previously recorded clicks are kept for historical stats.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
