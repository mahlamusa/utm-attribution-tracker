<?php
/**
 * TrafficSource — shared traffic-source classification rule set.
 *
 * Single source of truth for the Direct / Organic-Referral / Paid / Google
 * Ads / Tagged bucketing rule order, consumed two ways: sql_case_expression()
 * as the legacy-row fallback inside sql_channel_expression(), and classify()
 * for per-lead classification (e.g. the recent-leads dashboard widget). Both
 * must stay derived from the same rule order and the same PAID_MEDIA
 * whitelist so the two evaluation paths cannot silently diverge.
 *
 * It also owns the marketing-channel taxonomy (CHANNELS): channel() files
 * an event row into one channel at write time from its UTM values, click
 * IDs and referrer host, following GA4's default channel grouping for
 * manual traffic plus an AI-assistant channel; the three referrer host
 * lists (search engines, social networks, AI assistants) are filterable;
 * sql_channel_expression() reads the stored channel and maps legacy rows
 * (written before the column existed) through the legacy rule set.
 *
 * @since 1.0.0
 *
 * @package Utmatra\Tracking
 */

namespace Utmatra\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * TrafficSource
 *
 * @since 1.0.0
 */
class TrafficSource {

	/**
	 * Channel slug: no campaign data and no external referrer.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const CHANNEL_DIRECT = 'direct';

	/**
	 * Channel slug: unpaid search-engine traffic.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const CHANNEL_ORGANIC_SEARCH = 'organic_search';

	/**
	 * Channel slug: paid search (Google or Microsoft click ID, or a paid medium from a search engine).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const CHANNEL_PAID_SEARCH = 'paid_search';

	/**
	 * Channel slug: paid social (social click ID, or a paid medium from a social network).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const CHANNEL_PAID_SOCIAL = 'paid_social';

	/**
	 * Channel slug: unpaid social-network traffic.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const CHANNEL_ORGANIC_SOCIAL = 'organic_social';

	/**
	 * Channel slug: email and newsletter traffic.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const CHANNEL_EMAIL = 'email';

	/**
	 * Channel slug: a link from another site, or a referral medium.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const CHANNEL_REFERRAL = 'referral';

	/**
	 * Channel slug: traffic from an AI assistant (ChatGPT, Perplexity, Claude, Gemini, ...).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const CHANNEL_AI_ASSISTANT = 'ai_assistant';

	/**
	 * Channel slug: display and banner advertising.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const CHANNEL_DISPLAY = 'display';

	/**
	 * Channel slug: affiliate medium.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const CHANNEL_AFFILIATE = 'affiliate';

	/**
	 * Channel slug: paid medium from a source that is neither search nor social.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const CHANNEL_PAID_OTHER = 'paid_other';

	/**
	 * Channel slug: campaign data present but no rule matched (e.g. a QR or print medium).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const CHANNEL_OTHER = 'other';

	/**
	 * Every channel slug, in display order. All fit the 20-character column.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	public const CHANNELS = array(
		self::CHANNEL_DIRECT,
		self::CHANNEL_ORGANIC_SEARCH,
		self::CHANNEL_PAID_SEARCH,
		self::CHANNEL_PAID_SOCIAL,
		self::CHANNEL_ORGANIC_SOCIAL,
		self::CHANNEL_EMAIL,
		self::CHANNEL_REFERRAL,
		self::CHANNEL_AI_ASSISTANT,
		self::CHANNEL_DISPLAY,
		self::CHANNEL_AFFILIATE,
		self::CHANNEL_PAID_OTHER,
		self::CHANNEL_OTHER,
	);

	/**
	 * Default AI-assistant referrer hosts.
	 *
	 * Checked before the search-engine list so `gemini.google.com` is never
	 * swallowed by the `google` label match. Entries with a dot match the
	 * host and its subdomains; see host_in_list().
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private const AI_ASSISTANT_HOSTS = array(
		'chatgpt.com',
		'chat.openai.com',
		'perplexity.ai',
		'claude.ai',
		'gemini.google.com',
		'bard.google.com',
		'copilot.microsoft.com',
		'you.com',
		'phind.com',
		'poe.com',
		'meta.ai',
		'mistral.ai',
		'grok.com',
		'x.ai',
		'deepseek.com',
		'duck.ai',
		'kimi.com',
	);

	/**
	 * Default search-engine referrer hosts.
	 *
	 * Entries without a dot (`google`, `yahoo`, `yandex`) match any label of
	 * the host so every country domain counts. Brave Search is filed here on
	 * purpose: its AI answers render on the normal results page and the
	 * referrer carries no path to tell them apart.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private const SEARCH_ENGINE_HOSTS = array(
		'google',
		'bing.com',
		'yahoo',
		'duckduckgo.com',
		'baidu.com',
		'yandex',
		'ecosia.org',
		'ask.com',
		'aol.com',
		'naver.com',
		'seznam.cz',
		'qwant.com',
		'startpage.com',
		'kagi.com',
		'search.brave.com',
	);

	/**
	 * Default social-network referrer hosts.
	 *
	 * `meta.com` is listed so the utm_source `meta` that Meta Ads tags with
	 * resolves as a social source rather than through the AI list's
	 * `meta.ai` entry.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private const SOCIAL_NETWORK_HOSTS = array(
		'facebook.com',
		'fb.com',
		'fb.me',
		'instagram.com',
		'linkedin.com',
		'lnkd.in',
		'twitter.com',
		't.co',
		'x.com',
		'meta.com',
		'tiktok.com',
		'pinterest',
		'reddit.com',
		'youtube.com',
		'youtu.be',
		'snapchat.com',
		'threads.net',
		'threads.com',
		'bsky.app',
		'mastodon.social',
		'whatsapp.com',
		't.me',
		'telegram.org',
		'discord.com',
		'quora.com',
		'tumblr.com',
		'vk.com',
		'weibo.com',
		'line.me',
		'nextdoor.com',
	);

	/**
	 * The utm_medium values filed as display advertising.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private const DISPLAY_MEDIA = array( 'display', 'banner', 'expandable', 'interstitial', 'cpm' );

	/**
	 * The utm_medium values filed as organic social.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private const SOCIAL_MEDIA = array( 'social', 'social-network', 'social-media', 'sm', 'social network', 'social media' );

	/**
	 * The utm_source or utm_medium values filed as email.
	 *
	 * `newsletter` is an addition to GA4's list: it is the most common
	 * medium in this plugin's own presets.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private const EMAIL_VALUES = array( 'email', 'e-mail', 'e_mail', 'e mail', 'newsletter' );

	/**
	 * The utm_medium values treated as paid media (case-insensitive).
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	public const PAID_MEDIA = array( 'cpc', 'ppc', 'paid', 'paidsearch', 'cpm', 'display' );

	/**
	 * Machine-readable bucket: Google Ads.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const GOOGLE_ADS = 'google_ads';

	/**
	 * Machine-readable bucket: paid media (non-Google Ads).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const PAID = 'paid';

	/**
	 * Machine-readable bucket: direct (no UTM, no click ID, no referrer).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const DIRECT = 'direct';

	/**
	 * Machine-readable bucket: organic/referral (no UTM, no click ID, has referrer).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const ORGANIC_REFERRAL = 'organic_referral';

	/**
	 * Machine-readable bucket: tagged fallback (UTM present, no other rule matched).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const TAGGED = 'tagged';

	/**
	 * Chart-facing display label per machine-readable bucket.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string, string>
	 */
	public const DISPLAY_LABELS = array(
		self::GOOGLE_ADS       => 'Google Ads',
		self::PAID             => 'Paid',
		self::DIRECT           => 'Direct',
		self::ORGANIC_REFERRAL => 'Organic / Referral',
		self::TAGGED           => 'Tagged',
	);

	/**
	 * Build the R1 rule order as a single SQL `CASE WHEN ... END` fragment.
	 *
	 * Column names are hardcoded to the `events` table's actual columns; the
	 * whitelist values are fixed class constants (never user input), so they
	 * are inlined directly rather than passed through `$wpdb->prepare()`.
	 * Callers alias the expression (`{$expression} AS label`) and GROUP BY it.
	 *
	 * @since 1.0.0
	 * @return string SQL `CASE WHEN ... END` expression (without an alias).
	 */
	public static function sql_case_expression(): string {
		$paid_media_list = "'" . implode( "','", array_map( 'esc_sql', self::PAID_MEDIA ) ) . "'";

		$no_utm      = "utm_source = '' AND utm_medium = '' AND utm_campaign = ''";
		$no_click_id = "gclid = '' AND fbclid = '' AND msclkid = '' AND ttclid = '' AND li_fat_id = '' AND twclid = ''";

		return "CASE
			WHEN ( gclid != '' AND utm_campaign != '' ) OR ( LOWER( utm_source ) LIKE '%google%' AND LOWER( utm_medium ) IN ({$paid_media_list}) ) THEN '" . esc_sql( self::DISPLAY_LABELS[ self::GOOGLE_ADS ] ) . "'
			WHEN LOWER( utm_medium ) IN ({$paid_media_list}) THEN '" . esc_sql( self::DISPLAY_LABELS[ self::PAID ] ) . "'
			WHEN {$no_utm} AND {$no_click_id} AND referrer = '' THEN '" . esc_sql( self::DISPLAY_LABELS[ self::DIRECT ] ) . "'
			WHEN {$no_utm} AND {$no_click_id} AND referrer != '' THEN '" . esc_sql( self::DISPLAY_LABELS[ self::ORGANIC_REFERRAL ] ) . "'
			ELSE '" . esc_sql( self::DISPLAY_LABELS[ self::TAGGED ] ) . "'
		END";
	}

	/**
	 * Translated display label per channel slug.
	 *
	 * The single label source for the dashboard's Channels card (through
	 * the view's i18n block), the WooCommerce order screens and any other
	 * PHP renderer.
	 *
	 * @since 1.0.0
	 * @return array<string, string> Channel slug => label, in CHANNELS order.
	 */
	public static function channel_labels(): array {
		return array(
			self::CHANNEL_DIRECT         => __( 'Direct', 'utm-attribution-tracker' ),
			self::CHANNEL_ORGANIC_SEARCH => __( 'Organic search', 'utm-attribution-tracker' ),
			self::CHANNEL_PAID_SEARCH    => __( 'Paid search', 'utm-attribution-tracker' ),
			self::CHANNEL_PAID_SOCIAL    => __( 'Paid social', 'utm-attribution-tracker' ),
			self::CHANNEL_ORGANIC_SOCIAL => __( 'Organic social', 'utm-attribution-tracker' ),
			self::CHANNEL_EMAIL          => __( 'Email', 'utm-attribution-tracker' ),
			self::CHANNEL_REFERRAL       => __( 'Referral', 'utm-attribution-tracker' ),
			self::CHANNEL_AI_ASSISTANT   => __( 'AI assistant', 'utm-attribution-tracker' ),
			self::CHANNEL_DISPLAY        => __( 'Display', 'utm-attribution-tracker' ),
			self::CHANNEL_AFFILIATE      => __( 'Affiliate', 'utm-attribution-tracker' ),
			self::CHANNEL_PAID_OTHER     => __( 'Paid (other)', 'utm-attribution-tracker' ),
			self::CHANNEL_OTHER          => __( 'Other', 'utm-attribution-tracker' ),
		);
	}

	/**
	 * The search-engine referrer hosts, filterable.
	 *
	 * @since 1.0.0
	 * @return string[]
	 */
	public static function search_engine_hosts(): array {
		/**
		 * Filter the referrer hosts classified as search engines.
		 *
		 * @var string[] $hosts
		 */
		return (array) apply_filters( 'utmatra_search_engine_hosts', self::SEARCH_ENGINE_HOSTS );
	}

	/**
	 * The social-network referrer hosts, filterable.
	 *
	 * @since 1.0.0
	 * @return string[]
	 */
	public static function social_network_hosts(): array {
		/**
		 * Filter the referrer hosts classified as social networks.
		 *
		 * @var string[] $hosts
		 */
		return (array) apply_filters( 'utmatra_social_network_hosts', self::SOCIAL_NETWORK_HOSTS );
	}

	/**
	 * The AI-assistant referrer hosts, filterable.
	 *
	 * @since 1.0.0
	 * @return string[]
	 */
	public static function ai_assistant_hosts(): array {
		/**
		 * Filter the referrer hosts classified as AI assistants.
		 *
		 * @var string[] $hosts
		 */
		return (array) apply_filters( 'utmatra_ai_assistant_hosts', self::AI_ASSISTANT_HOSTS );
	}

	/**
	 * Whether a referrer host matches an entry of a host list.
	 *
	 * The host is lower-cased. An entry containing a dot matches when the
	 * host equals it or ends with `.` plus the entry (`l.facebook.com`
	 * matches `facebook.com`); an entry without a dot matches when it
	 * equals any dot-separated label of the host (`google` matches
	 * `www.google.co.uk`). The front-end beacon mirrors this rule.
	 *
	 * @since 1.0.0
	 * @param string   $host Bare referrer host.
	 * @param string[] $hosts Host list entries.
	 * @return bool
	 */
	public static function host_in_list( string $host, array $hosts ): bool {
		$host = strtolower( trim( $host ) );
		if ( '' === $host ) {
			return false;
		}
		$labels = explode( '.', $host );

		foreach ( $hosts as $entry ) {
			$entry = strtolower( trim( (string) $entry ) );
			if ( '' === $entry ) {
				continue;
			}
			if ( false === strpos( $entry, '.' ) ) {
				if ( in_array( $entry, $labels, true ) ) {
					return true;
				}
			} elseif ( $host === $entry || str_ends_with( $host, '.' . $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a utm_source value names a host of a host list.
	 *
	 * Applies host_in_list() to the source after stripping a scheme and a
	 * leading `www.`, and additionally matches when the source equals an
	 * entry's first label (`perplexity` for `perplexity.ai`, `facebook` for
	 * `facebook.com`), because AI assistants and social networks tag their
	 * outbound links with either form. The first-label shortcut skips entries
	 * that carry a subdomain (`chat.openai.com`, `search.brave.com`): their
	 * first label is a generic word, not a brand, and a campaign tagged
	 * `utm_source=chat` (a live-chat tool) or `utm_source=search` must not be
	 * filed as an AI assistant or a search engine.
	 *
	 * @since 1.0.0
	 * @param string   $source The utm_source value.
	 * @param string[] $hosts  Host list entries.
	 * @return bool
	 */
	public static function source_in_list( string $source, array $hosts ): bool {
		$source = strtolower( trim( $source ) );
		$source = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $source );
		$source = (string) preg_replace( '#^www\.#', '', $source );
		$source = rtrim( $source, '/' );
		if ( '' === $source ) {
			return false;
		}
		if ( self::host_in_list( $source, $hosts ) ) {
			return true;
		}

		foreach ( $hosts as $entry ) {
			$entry = strtolower( trim( (string) $entry ) );
			if ( '' !== $entry && substr_count( $entry, '.' ) < 2 && explode( '.', $entry )[0] === $source ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Classify an event-shaped row into a marketing channel.
	 *
	 * Pure function over the row's UTM values, click IDs and referrer. The
	 * referrer host is read from an `rh` key when present, else parsed from
	 * `referrer`; no other key is consulted, so a custom parameter named
	 * `ref` can never be mistaken for a referrer. A referrer on the site's
	 * own host counts as no referrer. Rules run in order, first match wins;
	 * medium rules follow GA4's default channel grouping for manual traffic:
	 *
	 *  1. gclid or msclkid present: paid search (those IDs only appear on
	 *     ad clicks, so they outrank any utm_medium).
	 *  2. source names an AI assistant and not a social network:
	 *     ai_assistant. The social exclusion keeps the sources `x` and
	 *     `meta`, which the first-label shortcut would otherwise read as
	 *     x.ai and meta.ai, with the social platforms that tag that way.
	 *  3. display medium: display.
	 *  4. paid medium (cp*, ppc, retargeting, paid*): paid_search when the
	 *     source is a search engine or the medium contains "search",
	 *     paid_social when the source is a social network or the medium
	 *     contains "social", else paid_other.
	 *  5. social medium or social source: organic_social.
	 *  6. medium "organic" or search-engine source: organic_search.
	 *  7. email source or medium: email.
	 *  8. affiliate medium: affiliate.
	 *  9. referral, app or link medium: referral.
	 * 10. social click ID (fbclid, ttclid, li_fat_id, twclid): paid_social.
	 *     Below the medium rules because Facebook appends fbclid to organic
	 *     clicks too, so a tagged organic post keeps its medium.
	 * 11. external referrer host: ai_assistant, organic_search,
	 *     organic_social or referral by host list.
	 * 12. any campaign data left: other; otherwise direct.
	 *
	 * The verdict passes through the `utmatra_channel` filter; a value outside
	 * CHANNELS falls back to the computed one.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $row Event-shaped row (utm_*, click IDs, `referrer` URL or `rh` host). Missing keys are treated as empty.
	 * @return string One of the CHANNELS slugs.
	 */
	public static function channel( array $row ): string {
		$get = static function ( string $key ) use ( $row ): string {
			return isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? (string) $row[ $key ] : '';
		};

		$source = strtolower( trim( $get( 'utm_source' ) ) );
		$medium = strtolower( trim( $get( 'utm_medium' ) ) );

		$host = strtolower( trim( $get( 'rh' ) ) );
		if ( '' === $host && '' !== $get( 'referrer' ) ) {
			$host = strtolower( (string) wp_parse_url( $get( 'referrer' ), PHP_URL_HOST ) );
		}
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' !== $host && $host === $site_host ) {
			$host = '';
		}

		$ai     = self::ai_assistant_hosts();
		$search = self::search_engine_hosts();
		$social = self::social_network_hosts();

		$has_social_click_id = '' !== $get( 'fbclid' ) || '' !== $get( 'ttclid' ) || '' !== $get( 'li_fat_id' ) || '' !== $get( 'twclid' );
		$has_campaign_data   = '' !== $source || '' !== $medium || '' !== trim( $get( 'utm_campaign' ) ) || $has_social_click_id;

		if ( '' !== $get( 'gclid' ) || '' !== $get( 'msclkid' ) ) {
			$channel = self::CHANNEL_PAID_SEARCH;
		} elseif ( '' !== $source && self::source_in_list( $source, $ai ) && ! self::source_in_list( $source, $social ) ) {
			$channel = self::CHANNEL_AI_ASSISTANT;
		} elseif ( in_array( $medium, self::DISPLAY_MEDIA, true ) ) {
			$channel = self::CHANNEL_DISPLAY;
		} elseif ( '' !== $medium && 1 === preg_match( '/^(.*cp.*|ppc|retargeting|paid.*)$/', $medium ) ) {
			if ( self::source_in_list( $source, $search ) || false !== strpos( $medium, 'search' ) ) {
				$channel = self::CHANNEL_PAID_SEARCH;
			} elseif ( self::source_in_list( $source, $social ) || false !== strpos( $medium, 'social' ) ) {
				$channel = self::CHANNEL_PAID_SOCIAL;
			} else {
				$channel = self::CHANNEL_PAID_OTHER;
			}
		} elseif ( in_array( $medium, self::SOCIAL_MEDIA, true ) || self::source_in_list( $source, $social ) ) {
			$channel = self::CHANNEL_ORGANIC_SOCIAL;
		} elseif ( 'organic' === $medium || self::source_in_list( $source, $search ) ) {
			$channel = self::CHANNEL_ORGANIC_SEARCH;
		} elseif ( in_array( $source, self::EMAIL_VALUES, true ) || in_array( $medium, self::EMAIL_VALUES, true ) ) {
			$channel = self::CHANNEL_EMAIL;
		} elseif ( in_array( $medium, array( 'affiliate', 'affiliates' ), true ) ) {
			$channel = self::CHANNEL_AFFILIATE;
		} elseif ( in_array( $medium, array( 'referral', 'app', 'link' ), true ) ) {
			$channel = self::CHANNEL_REFERRAL;
		} elseif ( $has_social_click_id ) {
			$channel = self::CHANNEL_PAID_SOCIAL;
		} elseif ( '' !== $host ) {
			if ( self::host_in_list( $host, $ai ) ) {
				$channel = self::CHANNEL_AI_ASSISTANT;
			} elseif ( self::host_in_list( $host, $search ) ) {
				$channel = self::CHANNEL_ORGANIC_SEARCH;
			} elseif ( self::host_in_list( $host, $social ) ) {
				$channel = self::CHANNEL_ORGANIC_SOCIAL;
			} else {
				$channel = self::CHANNEL_REFERRAL;
			}
		} else {
			$channel = $has_campaign_data ? self::CHANNEL_OTHER : self::CHANNEL_DIRECT;
		}

		/**
		 * Filter the channel computed for an event row.
		 *
		 * @var string               $channel One of TrafficSource::CHANNELS.
		 * @var array<string, mixed> $row     The row that was classified.
		 */
		$filtered = apply_filters( 'utmatra_channel', $channel, $row );

		return ( is_string( $filtered ) && in_array( $filtered, self::CHANNELS, true ) ) ? $filtered : $channel;
	}

	/**
	 * SQL expression yielding a channel slug for every row, legacy rows included.
	 *
	 * Rows written since the channel column exists carry their channel;
	 * older rows (empty channel) are mapped coarsely through the legacy
	 * rule set by wrapping sql_case_expression(), so that rule set exists
	 * exactly once: Google Ads becomes paid_search, Paid becomes
	 * paid_other, Direct stays direct, Organic / Referral becomes referral
	 * and Tagged becomes other. Callers alias the expression and GROUP BY it.
	 *
	 * @since 1.0.0
	 * @return string SQL `CASE ... END` expression (without an alias).
	 */
	public static function sql_channel_expression(): string {
		return "CASE WHEN channel != '' THEN channel ELSE (
			CASE ( " . self::sql_case_expression() . " )
				WHEN '" . esc_sql( self::DISPLAY_LABELS[ self::GOOGLE_ADS ] ) . "' THEN '" . self::CHANNEL_PAID_SEARCH . "'
				WHEN '" . esc_sql( self::DISPLAY_LABELS[ self::PAID ] ) . "' THEN '" . self::CHANNEL_PAID_OTHER . "'
				WHEN '" . esc_sql( self::DISPLAY_LABELS[ self::DIRECT ] ) . "' THEN '" . self::CHANNEL_DIRECT . "'
				WHEN '" . esc_sql( self::DISPLAY_LABELS[ self::ORGANIC_REFERRAL ] ) . "' THEN '" . self::CHANNEL_REFERRAL . "'
				ELSE '" . self::CHANNEL_OTHER . "'
			END
		) END";
	}

	/**
	 * Classify a single event row using the identical R1 rule order.
	 *
	 * A touch that carries only a referrer host (`rh`, written for untagged
	 * organic, social, AI-assistant and referral visits) counts as having a
	 * referrer, so such leads read as organic/referral rather than direct.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $event Associative event row; keys match the `events` table columns. Missing keys are treated as empty strings.
	 * @return string One of the machine-readable bucket constants (GOOGLE_ADS, PAID, DIRECT, ORGANIC_REFERRAL, TAGGED).
	 */
	public static function classify( array $event ): string {
		$get = static function ( array $event, string $key ): string {
			return isset( $event[ $key ] ) ? (string) $event[ $key ] : '';
		};

		$utm_source   = $get( $event, 'utm_source' );
		$utm_medium   = strtolower( $get( $event, 'utm_medium' ) );
		$utm_campaign = $get( $event, 'utm_campaign' );
		$gclid        = $get( $event, 'gclid' );
		$referrer     = $get( $event, 'referrer' );
		if ( '' === $referrer ) {
			$referrer = $get( $event, 'rh' );
		}

		$no_utm = '' === $utm_source && '' === $utm_medium && '' === $utm_campaign;

		$no_click_id = '' === $gclid
			&& '' === $get( $event, 'fbclid' )
			&& '' === $get( $event, 'msclkid' )
			&& '' === $get( $event, 'ttclid' )
			&& '' === $get( $event, 'li_fat_id' )
			&& '' === $get( $event, 'twclid' );

		$is_paid_medium = in_array( $utm_medium, self::PAID_MEDIA, true );

		if ( ( '' !== $gclid && '' !== $utm_campaign ) || ( false !== stripos( $utm_source, 'google' ) && $is_paid_medium ) ) {
			return self::GOOGLE_ADS;
		}

		if ( $is_paid_medium ) {
			return self::PAID;
		}

		if ( $no_utm && $no_click_id && '' === $referrer ) {
			return self::DIRECT;
		}

		if ( $no_utm && $no_click_id && '' !== $referrer ) {
			return self::ORGANIC_REFERRAL;
		}

		return self::TAGGED;
	}
}
