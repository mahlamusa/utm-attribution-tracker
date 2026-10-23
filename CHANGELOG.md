# Changelog

All notable changes to Utmatra - UTM Attribution and Tracking are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-23

### Added

- Initial release.
- Cache-proof tracking beacon with a no-JS PHP fallback, bot filtering and click deduplication.
- Ad click-ID capture (`gclid`, `fbclid`, `msclkid`, `ttclid`, `li_fat_id`, `twclid`) alongside UTM tags.
- First/last-touch attribution cookie, gated on the WP Consent API.
- Channel classification, including AI assistants, and untagged landing-visit recording.
- Pretty short links with QR codes, the `[utmatra_link]` shortcode, UTM naming presets and a build-a-URL mode.
- Custom URL parameters captured alongside UTM tags.
- WooCommerce revenue attribution with an attribution metabox on the order screen, and form lead capture for Contact Form 7, Gravity Forms, Ninja Forms, Elementor Pro, WPForms, Fluent Forms, Formidable, Forminator and Bricks.
- Lead and order webhooks for Zapier, Make, HubSpot and GoHighLevel.
- Customer journeys built from a consent-gated visitor id, and a read-only REST API.
- Tabbed Chart.js dashboard with breakdowns by source, campaign, medium, channel, device, landing page, referrer and ad platform, plus a revenue-by-campaign chart.
- Recent leads widget on the WordPress admin Dashboard.
- CSV export, weekly email summary, Tracking Doctor diagnostics and data retention.
- IP addresses stored only as salted SHA-256 hashes, and suggested privacy policy text.
- Background jobs on bundled Action Scheduler, and no external services or CDN calls by default.

[1.0.0]: https://github.com/mahlamusa/utmatra-utm-attribution-and-tracking/releases/tag/v1.0.0
