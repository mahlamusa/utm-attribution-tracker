/* global utmatBeacon */
/**
 * UTM & Attribution Tracker — front-end beacon.
 *
 * Enqueued on EVERY front-end page (cached HTML must always contain it) and
 * self-gates here: it exits unless the current URL carries at least one
 * whitelisted UTM parameter or ad click ID. Matching params are POSTed to
 * the public collect endpoint via navigator.sendBeacon() (fetch keepalive
 * fallback), so tracking works even when a page cache/CDN serves the HTML
 * without running PHP. Keep the whitelist in sync with
 * Tracker::PARAM_WHITELIST.
 *
 * The beacon is also the primary writer of the first-party attribution
 * cookie (first/last touch, see Attribution.php) — the cookie is written
 * BEFORE the collect beacon is sent so a quick navigation away cannot lose
 * the cookie write. Rules mirror the PHP fallback in Tracker.php: `first`
 * is preserved once set, `last` is overwritten, values capped at 255 chars.
 */
( function () {
	'use strict';

	var WHITELIST = [
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_term',
		'utm_content', 'gclid', 'fbclid', 'msclkid', 'ttclid',
	];

	var COOKIE_NAME = 'utmat_attr';

	var config = window.utmatBeacon;
	var search = window.location.search;

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

	if ( ! config || ! config.endpoint || search.length < 2 ) {
		return;
	}

	// Fire once per page load — skip soft reloads and bfcache restores.
	try {
		if ( window.sessionStorage.getItem( 'utmat_sent' ) === search ) {
			return;
		}
	} catch ( e ) {
		// sessionStorage unavailable (privacy mode) — send anyway.
	}

	var pairs = search.substring( 1 ).split( '&' );
	var fields = [];
	var touch = {};
	var found = false;
	var i, eq, key, value, attr, first;

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

	if ( ! found ) {
		return;
	}

	// --- Attribution cookie: write BEFORE sending the beacon. ---
	if ( config.cookieEnabled ) {
		touch.lp = window.location.href.slice( 0, 255 );
		touch.ts = Math.floor( Date.now() / 1000 );

		attr = null;
		value = readCookie( COOKIE_NAME );
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
		var cookieVal = JSON.stringify( { first: first, last: touch } );
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

	fields.push( 'page_url=' + encodeURIComponent( window.location.href ) );
	fields.push( 'referrer=' + encodeURIComponent( document.referrer || '' ) );

	var body = fields.join( '&' );
	var sent = false;

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
}() );
