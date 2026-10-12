/* global utmatBeacon */
/**
 * UTM & Attribution Tracker — front-end beacon.
 *
 * Enqueued on EVERY front-end page (cached HTML must always contain it) and
 * self-gates here. A URL carrying at least one whitelisted UTM parameter or
 * ad click ID is a tagged visit: the matching params are POSTed to the
 * public collect endpoint via navigator.sendBeacon() (fetch keepalive
 * fallback), so tracking works even when a page cache/CDN serves the HTML
 * without running PHP. When utmatBeacon.visitEnabled is on, an untagged
 * page load that is the first page of the visit (nothing sent yet in this
 * tab, referrer not this site) is reported once as a landing visit with
 * its referrer, classified here into a channel (AI assistant, search
 * engine, social network, referral or direct) from the host lists in
 * utmatBeacon.hosts; later untagged page views send nothing. The whitelist
 * comes from utmatBeacon.params (Tracker::param_whitelist(): built-ins plus
 * custom parameters); the literal list below is only a fallback for cached
 * HTML without config.
 *
 * The beacon is also the primary writer of the first-party attribution
 * cookie (first/last touch, see Attribution.php) — the cookie is written
 * BEFORE the collect beacon is sent so a quick navigation away cannot lose
 * the cookie write. Rules mirror the PHP fallback in Tracker.php: `first`
 * is preserved once set, `last` is overwritten, values capped at 255 chars.
 * A tagged touch also carries the external referrer host (`rh`); an
 * untagged landing writes `{ ch, rh, lp, ts }` unless it is direct, which
 * never writes or updates the cookie.
 *
 * Consent is decided here, in the browser, so it is correct on cached
 * pages: consentState() returns `off` (the utmatBeacon cookieEnabled
 * master switch is off), `allowed`, `undecided` or `denied`, using the WP
 * Consent API JavaScript (window.wp_has_consent) when a consent plugin
 * provides it and the raw consent cookies to tell "not yet chosen" from
 * "refused". Every cookie write goes through recordTouch(), the single
 * entry point: `allowed` writes, `undecided` holds the touch in page memory
 * only (nothing is placed on the device before consent) and writes it once
 * consent is granted on that page, `denied`/`off` drop it. A withdrawal
 * deletes the attribution cookies on the spot.
 *
 * Finally the beacon fills attribution form fields — named after the
 * standard parameters (`utm_source`, `gclid`, `landing_page`, `channel`,
 * `referrer_host`, plus `_first` variants), or carrying a
 * `data-utmat-field="<name>"` marker when a form plugin controls the name
 * attribute — from the attribution cookie (fillFormFields below) so any
 * form can capture attribution. This runs on every page, after the cookie
 * write, only fills empty fields, and is skipped while consent is
 * undecided. The cookie carries a channel only for untagged landings, so
 * the client-side fill of `channel` covers those visitors; server-side
 * adapters derive it for every touch.
 * Forms injected into the DOM after page load are not filled (documented
 * limitation — no MutationObserver).
 */
( function () {
	'use strict';

	// Resolved once at module scope: addTouch(), fieldSelector() and
	// trackVisit() all read it.
	var config = window.utmatBeacon || {};
	var WHITELIST = ( config.params && config.params.length ) ? config.params : [
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_term',
		'utm_content', 'gclid', 'fbclid', 'msclkid', 'ttclid',
		'li_fat_id', 'twclid',
	];
	// Every key a cookie touch may carry (apart from `ts`): the params plus
	// the landing page, the channel and the referrer host. Mirrors
	// Attribution::touch_keys().
	var TOUCH_KEYS = WHITELIST.concat( [ 'lp', 'ch', 'rh' ] );

	var COOKIE_NAME = 'utmat_attr';
	var VID_COOKIE = 'utmat_vid';

	// Touch held in page memory while consent is undecided; one slot, newest
	// wins, dies with the page. Never persisted to storage or a cookie. A
	// parked landing visit is wrapped as { kind: 'visit', touch, ref } so
	// flushPending() can rebuild its collect request.
	var pending = null;

	/**
	 * Read a cookie value (URL-decoded) by name, '' when absent/undecodable.
	 *
	 * @param {string} name Cookie name.
	 * @return {string}
	 */
	function readCookie( name ) {
		var parts = document.cookie.split( '; ' );
		var i, eq;
		for ( i = 0; i < parts.length; i++ ) {
			eq = parts[ i ].indexOf( '=' );
			if ( eq > 0 && parts[ i ].substring( 0, eq ) === name ) {
				try {
					// The PHP fallback writes via setcookie() (urlencode: space
					// as '+'), so decode '+' too — matches the query parse above.
					return decodeURIComponent( parts[ i ].substring( eq + 1 ).replace( /\+/g, ' ' ) );
				} catch ( e ) {
					return '';
				}
			}
		}
		return '';
	}

	/**
	 * Write a first-party cookie: path=/, SameSite=Lax, optional Secure.
	 *
	 * @param {string}  name   Cookie name.
	 * @param {string}  value  Raw value (URL-encoded here).
	 * @param {number}  days   Lifetime in days.
	 * @param {boolean} secure Add the Secure attribute.
	 */
	function writeCookie( name, value, days, secure ) {
		document.cookie = name + '=' + encodeURIComponent( value ) +
			'; expires=' + new Date( Date.now() + ( days * 86400000 ) ).toUTCString() +
			'; path=/; SameSite=Lax' + ( secure ? '; Secure' : '' );
	}

	/**
	 * Expire a first-party cookie with the same attributes both writers use
	 * (path=/, SameSite=Lax, optional Secure, no domain) so the delete matches.
	 *
	 * @param {string} name Cookie name.
	 */
	function deleteCookie( name ) {
		document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT' +
			'; path=/; SameSite=Lax' + ( config.secure ? '; Secure' : '' );
	}

	/**
	 * The WP Consent API categories that gate the cookie, from config.
	 *
	 * @return {string[]}
	 */
	function consentCategories() {
		if ( 'statistics' === config.consentCategory ) {
			return [ 'statistics' ];
		}
		if ( 'either' === config.consentCategory ) {
			return [ 'marketing', 'statistics' ];
		}
		return [ 'marketing' ];
	}

	/**
	 * Current consent state: 'off', 'allowed', 'undecided' or 'denied'.
	 *
	 * 'off' is the master switch. Without the WP Consent API script the
	 * state is 'allowed' unless the server advertised the API (consentApi),
	 * in which case the script has not run yet and the touch is held. With
	 * the script, wp_has_consent() decides 'allowed'; it cannot tell "not
	 * yet chosen" from "refused", so the raw consent cookies distinguish
	 * 'denied' (every gating category refused) from 'undecided'.
	 *
	 * @return {string}
	 */
	function consentState() {
		var cats, prefix, i, denied;

		if ( ! config.cookieEnabled ) {
			return 'off';
		}
		if ( typeof window.wp_has_consent !== 'function' ) {
			return config.consentApi ? 'undecided' : 'allowed';
		}
		if ( window.waitfor_consent_hook && typeof window.wp_consent_type === 'undefined' ) {
			return 'undecided';
		}

		cats = consentCategories();
		for ( i = 0; i < cats.length; i++ ) {
			if ( window.wp_has_consent( cats[ i ] ) ) {
				return 'allowed';
			}
		}

		prefix = ( window.consent_api && window.consent_api.cookie_prefix ) || 'wp_consent';
		denied = true;
		for ( i = 0; i < cats.length; i++ ) {
			if ( 'deny' !== readCookie( prefix + '_' + cats[ i ] ) ) {
				denied = false;
			}
		}
		return denied ? 'denied' : 'undecided';
	}

	/**
	 * Map an attribution param to its form-field name.
	 *
	 * Mirrors Attribution::field_name(): the field name is the standard
	 * parameter name itself (`utm_source` → `utm_source`, `gclid` → `gclid`);
	 * only the touch-level keys are renamed: `lp` → `landing_page`, `ch` →
	 * `channel`, `rh` → `referrer_host`.
	 *
	 * @param {string} param Whitelisted param key, or 'lp', 'ch' or 'rh'.
	 * @return {string}
	 */
	function fieldName( param ) {
		if ( 'lp' === param ) {
			return 'landing_page';
		}
		if ( 'ch' === param ) {
			return 'channel';
		}
		if ( 'rh' === param ) {
			return 'referrer_host';
		}
		return param;
	}

	/**
	 * Collect one attribution set's values into the field-name map.
	 *
	 * @param {Object} map    Field name → value accumulator (mutated).
	 * @param {Object} touch  The `first` or `last` set from the cookie.
	 * @param {string} suffix '' for last touch, '_first' for first touch.
	 */
	function addTouch( map, touch, suffix ) {
		var i, param;
		if ( ! touch || typeof touch !== 'object' ) {
			return;
		}
		for ( i = 0; i < TOUCH_KEYS.length; i++ ) {
			param = TOUCH_KEYS[ i ];
			if ( typeof touch[ param ] === 'string' && touch[ param ] ) {
				map[ fieldName( param ) + suffix ] = touch[ param ].slice( 0, 255 );
			}
		}
	}

	/**
	 * Build the selector matching every recognised attribution field.
	 *
	 * Targets inputs and textareas by exact name — the whitelisted params
	 * plus `landing_page`, `channel` and `referrer_host`, each with and
	 * without the `_first` suffix — rather than a name prefix, since the
	 * standard names share no common prefix.
	 *
	 * @return {string}
	 */
	function fieldSelector() {
		var params = TOUCH_KEYS;
		var parts = [ '[data-utmat-field]' ];
		var i, name;
		for ( i = 0; i < params.length; i++ ) {
			name = fieldName( params[ i ] );
			parts.push(
				'input[name="' + name + '"]',
				'input[name="' + name + '_first"]',
				'textarea[name="' + name + '"]',
				'textarea[name="' + name + '_first"]'
			);
		}
		return parts.join( ', ' );
	}

	/**
	 * Fill attribution form fields from the attribution cookie.
	 *
	 * Any input/textarea whose name is a tracked parameter (`utm_source` …
	 * `twclid`) or `landing_page` receives the last-touch value; the
	 * `_first`-suffixed names receive the first-touch value. A field whose
	 * name the form plugin controls is matched by its `data-utmat-field`
	 * marker instead. Only empty fields are filled — a user-entered or
	 * server-rendered value is never overwritten. Values are assigned via
	 * the .value property (no HTML parsing), so no escaping is needed here.
	 */
	function fillFormFields() {
		var raw = readCookie( COOKIE_NAME );
		var attr, map, nodes, i, value;
		if ( ! raw ) {
			return;
		}
		try {
			attr = JSON.parse( raw );
		} catch ( e ) {
			return;
		}
		if ( ! attr || typeof attr !== 'object' ) {
			return;
		}

		map = {};
		addTouch( map, attr.last, '' );
		addTouch( map, attr.first, '_first' );

		nodes = document.querySelectorAll( fieldSelector() );
		for ( i = 0; i < nodes.length; i++ ) {
			value = map[ nodes[ i ].getAttribute( 'data-utmat-field' ) || nodes[ i ].name ];
			if ( value && '' === nodes[ i ].value ) {
				nodes[ i ].value = value;
			}
		}
	}

	/**
	 * Write a touch into the attribution cookie as the new last touch.
	 *
	 * Only ever reached from the `allowed` branch of recordTouch(): this is
	 * the one place that writes the attribution cookies. `first` is
	 * preserved once set; a cookie over 4000 bytes sheds the landing page,
	 * and is skipped entirely if still oversized (the existing cookie stays).
	 *
	 * @param {Object} touch Touch to record (params plus `lp` and `ts`).
	 */
	function writeTouch( touch ) {
		var attr = null;
		var value = readCookie( COOKIE_NAME );
		var first, cookieVal;

		if ( value ) {
			try {
				attr = JSON.parse( value );
			} catch ( e ) {
				attr = null; // Malformed cookie — rebuild from scratch.
			}
		}
		first = ( attr && typeof attr.first === 'object' && attr.first ) ? attr.first : touch;

		// Total-cookie-size guard: shed the landing page if oversized, then
		// skip the write entirely if still over the limit (existing cookie stays).
		cookieVal = JSON.stringify( { first: first, last: touch } );
		if ( cookieVal.length > 4000 ) {
			delete first.lp;
			delete touch.lp;
			cookieVal = JSON.stringify( { first: first, last: touch } );
		}
		if ( cookieVal.length <= 4000 ) {
			writeCookie(
				COOKIE_NAME,
				cookieVal,
				parseInt( config.cookieDays, 10 ) || 90,
				!! config.secure
			);
		}
	}

	/**
	 * Single entry point for every cookie write, dispatching on consent.
	 *
	 * `allowed` writes now; `undecided` holds the touch in page memory until
	 * consent is granted on this page (newest touch wins); `denied` and
	 * `off` drop it.
	 *
	 * @param {Object} touch Touch to record.
	 */
	function recordTouch( touch ) {
		var state = consentState();
		if ( 'allowed' === state ) {
			writeTouch( touch );
		} else if ( 'undecided' === state ) {
			pending = touch;
		}
	}

	/**
	 * Write the held touch once consent is granted.
	 *
	 * The collect request is rebuilt from the touch only when events are
	 * gated on consent; otherwise it already went out at landing. A parked
	 * landing visit ({ kind: 'visit' }) writes its touch only when it is
	 * not direct and sends a visit request rather than a tagged one (a
	 * visit touch has no whitelisted keys, so a tagged rebuild would be an
	 * empty body the endpoint rejects).
	 */
	function flushPending() {
		var touch = pending;
		var fields = [];
		var key;

		if ( ! touch ) {
			return;
		}
		pending = null;

		if ( 'visit' === touch.kind ) {
			if ( 'direct' !== touch.touch.ch ) {
				recordTouch( touch.touch );
			}
			if ( config.gateEvents ) {
				sendVisit( touch.touch.lp, touch.ref );
			}
			return;
		}

		recordTouch( touch );

		if ( config.gateEvents ) {
			for ( key in touch ) {
				if ( Object.prototype.hasOwnProperty.call( touch, key ) && WHITELIST.indexOf( key ) > -1 ) {
					fields.push( encodeURIComponent( key ) + '=' + encodeURIComponent( touch[ key ] ) );
				}
			}
			fields.push( 'page_url=' + encodeURIComponent( window.location.href ) );
			fields.push( 'referrer=' + encodeURIComponent( document.referrer || '' ) );
			sendCollect( fields, window.location.search );
		}
	}

	/**
	 * POST the collect beacon once per tagged URL per tab.
	 *
	 * The per-tab dedup marker (session-scoped `utmat_sent`) lives here so a
	 * reload of a tagged URL still reaches recordTouch() while the collect
	 * is sent only once; the marker is set at the moment a collect goes out.
	 *
	 * @param {string[]} fields Encoded key=value pairs.
	 * @param {string}   search The query string the collect describes.
	 */
	function sendCollect( fields, search ) {
		var body, sent;

		// Fire once per page load — skip soft reloads and bfcache restores.
		try {
			if ( window.sessionStorage.getItem( 'utmat_sent' ) === search ) {
				return;
			}
		} catch ( e ) {
			// sessionStorage unavailable (privacy mode) — send anyway.
		}

		body = fields.join( '&' );
		sent = false;

		if ( navigator.sendBeacon ) {
			sent = navigator.sendBeacon(
				config.endpoint,
				new Blob( [ body ], { type: 'application/x-www-form-urlencoded' } )
			);
		}
		if ( ! sent && window.fetch ) {
			window.fetch( config.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body,
				keepalive: true,
				credentials: 'omit',
			} );
		}

		try {
			window.sessionStorage.setItem( 'utmat_sent', search );
		} catch ( e ) {
			// Ignore — worst case the beacon re-fires and dedup absorbs it.
		}
	}

	/**
	 * Remember that this tab already reported its landing, so later untagged
	 * page views in the tab are never counted as landings.
	 */
	function markVisit() {
		try {
			window.sessionStorage.setItem( 'utmat_visit', '1' );
		} catch ( e ) {
			// sessionStorage unavailable — the server-side dedup absorbs repeats.
		}
	}

	/**
	 * POST an untagged landing visit (page URL and referrer) and mark the tab.
	 *
	 * @param {string} pageUrl  The landing page URL.
	 * @param {string} referrer The document referrer at landing ('' when none).
	 */
	function sendVisit( pageUrl, referrer ) {
		sendCollect(
			[
				'visit=1',
				'page_url=' + encodeURIComponent( pageUrl ),
				'referrer=' + encodeURIComponent( referrer || '' ),
			],
			window.location.search
		);
		markVisit();
	}

	/**
	 * The host of the document referrer, '' when absent or unparsable.
	 *
	 * Parsed through an anchor element (no URL constructor) so the beacon
	 * stays ES5.
	 *
	 * @return {string} Lower-cased host.
	 */
	function referrerHost() {
		var a;
		if ( ! document.referrer ) {
			return '';
		}
		a = document.createElement( 'a' );
		a.href = document.referrer;
		return ( a.hostname || '' ).toLowerCase();
	}

	/**
	 * Whether a host matches a host list — the mirror of
	 * TrafficSource::host_in_list().
	 *
	 * An entry with a dot matches when the host equals it or ends with
	 * `.` + entry (`l.facebook.com` matches `facebook.com`); an entry without
	 * a dot matches any dot-separated label of the host (`google` matches
	 * `www.google.co.uk`).
	 *
	 * @param {string}   host Lower-cased host.
	 * @param {string[]} list Host list entries.
	 * @return {boolean}
	 */
	function hostIn( host, list ) {
		var labels = host.split( '.' );
		var i, entry;
		if ( ! host || ! list || ! list.length ) {
			return false;
		}
		for ( i = 0; i < list.length; i++ ) {
			entry = String( list[ i ] || '' ).toLowerCase();
			if ( ! entry ) {
				continue;
			}
			if ( entry.indexOf( '.' ) < 0 ) {
				if ( labels.indexOf( entry ) > -1 ) {
					return true;
				}
			} else if ( host === entry || host.slice( -( entry.length + 1 ) ) === '.' + entry ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Channel of an untagged landing from its referrer host, in the order
	 * AI assistant, search engine, social network, else referral; no host
	 * means direct.
	 *
	 * @param {string} host Lower-cased external referrer host ('' when none).
	 * @return {string} Channel slug.
	 */
	function referrerChannel( host ) {
		var hosts = config.hosts || {};
		if ( ! host ) {
			return 'direct';
		}
		if ( hostIn( host, hosts.ai ) ) {
			return 'ai_assistant';
		}
		if ( hostIn( host, hosts.search ) ) {
			return 'organic_search';
		}
		if ( hostIn( host, hosts.social ) ) {
			return 'organic_social';
		}
		return 'referral';
	}

	/**
	 * Apply a consent state: a withdrawal deletes the attribution cookies
	 * and drops the held touch; a grant writes the held touch.
	 *
	 * @param {string} state Result of consentState().
	 */
	function applyConsentState( state ) {
		if ( 'denied' === state ) {
			deleteCookie( COOKIE_NAME );
			deleteCookie( VID_COOKIE );
			pending = null;
		} else if ( 'allowed' === state ) {
			flushPending();
		}
	}

	/**
	 * Consent changed (or the API arrived late): recompute and apply, then
	 * fill forms that were skipped or empty when the page first loaded.
	 */
	function onConsentEvent() {
		var state = consentState();
		applyConsentState( state );
		if ( 'allowed' === state ) {
			fillFormFields();
		}
	}

	/**
	 * Record the visit: record the attribution touch, POST the collect beacon.
	 *
	 * A URL with whitelisted params is a tagged visit: the params are always
	 * parsed and the touch handed to recordTouch(), so a reload while
	 * consent is undecided still holds the touch; the collect is
	 * deduplicated per tab inside sendCollect() and, only when events are
	 * gated on consent, deferred with the touch until consent is granted.
	 *
	 * An untagged load is a landing visit when visit tracking is on, the
	 * tab has not reported a landing yet (`utmat_visit`) and the referrer
	 * is not this site (so a tab opened from an internal link is never a
	 * landing). Its touch is { ch, rh, lp, ts }; a direct landing never
	 * writes the cookie. The visit request waits with the touch when events
	 * are gated and consent is undecided, and is dropped when denied.
	 *
	 * @param {string} state Result of consentState() at load.
	 */
	function trackVisit( state ) {
		var config = window.utmatBeacon;
		var search = window.location.search;
		var pairs, fields, touch, found, i, eq, key, value, host, landed;

		if ( ! config || ! config.endpoint ) {
			return;
		}

		pairs = search.length < 2 ? [] : search.substring( 1 ).split( '&' );
		fields = [];
		touch = {};
		found = false;
		host = referrerHost();

		for ( i = 0; i < pairs.length; i++ ) {
			eq = pairs[ i ].indexOf( '=' );
			if ( eq < 1 ) {
				continue;
			}
			try {
				key = decodeURIComponent( pairs[ i ].substring( 0, eq ) );
				value = decodeURIComponent( pairs[ i ].substring( eq + 1 ).replace( /\+/g, ' ' ) );
			} catch ( e ) {
				continue;
			}
			if ( value && WHITELIST.indexOf( key ) > -1 ) {
				found = true;
				fields.push( encodeURIComponent( key ) + '=' + encodeURIComponent( value ) );
				touch[ key ] = value.slice( 0, 255 );
			}
		}

		if ( found ) {
			// --- Tagged visit. Attribution cookie: record BEFORE sending. ---
			if ( host && host !== window.location.hostname.toLowerCase() ) {
				touch.rh = host.slice( 0, 255 );
			}
			touch.lp = window.location.href.slice( 0, 255 );
			touch.ts = Math.floor( Date.now() / 1000 );
			recordTouch( touch );
			markVisit();

			// Events carry no cookie, so the collect goes out regardless of
			// consent unless the site gated events; then it waits with the touch.
			if ( ! config.gateEvents || 'allowed' === state || 'off' === state ) {
				fields.push( 'page_url=' + encodeURIComponent( window.location.href ) );
				fields.push( 'referrer=' + encodeURIComponent( document.referrer || '' ) );
				sendCollect( fields, search );
			}
			return;
		}

		// --- Untagged load: a landing visit, once per tab. ---
		if ( ! config.visitEnabled || host === window.location.hostname.toLowerCase() ) {
			return;
		}
		landed = false;
		try {
			landed = !! window.sessionStorage.getItem( 'utmat_visit' );
		} catch ( e ) {
			// sessionStorage unavailable — rely on the server-side dedup.
		}
		if ( landed ) {
			return;
		}

		touch = {
			ch: referrerChannel( host ),
			lp: window.location.href.slice( 0, 255 ),
			ts: Math.floor( Date.now() / 1000 ),
		};
		if ( host ) {
			touch.rh = host.slice( 0, 255 );
		}
		if ( 'direct' !== touch.ch ) {
			recordTouch( touch );
		}

		if ( ! config.gateEvents || 'allowed' === state || 'off' === state ) {
			sendVisit( touch.lp, document.referrer || '' );
		} else if ( 'undecided' === state ) {
			pending = { kind: 'visit', touch: touch, ref: document.referrer || '' };
		}
	}

	var state = consentState();

	// Withdrawal cleanup first, then track so a cookie write lands before
	// the form fill reads it.
	applyConsentState( state );
	trackVisit( state );

	document.addEventListener( 'wp_listen_for_consent_change', onConsentEvent );
	document.addEventListener( 'wp_consent_type_defined', onConsentEvent );
	if ( 'undecided' === state && typeof window.wp_has_consent !== 'function' ) {
		// The server advertised the Consent API but its script has not run
		// yet (an optimiser deferred it behind us): re-check once at load.
		window.addEventListener( 'load', onConsentEvent );
	}

	// Reading the cookie into a form is itself an operation that needs
	// consent, so the fill waits while the decision is pending; the consent
	// event handler fills the form once consent is granted.
	if ( 'undecided' !== state ) {
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', fillFormFields );
		} else {
			// Deferred scripts run with the DOM already parsed — fill immediately.
			fillFormFields();
		}
	}
}() );
