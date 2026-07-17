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
