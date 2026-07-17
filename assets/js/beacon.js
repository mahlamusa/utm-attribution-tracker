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
 */
( function () {
	'use strict';

	var WHITELIST = [
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_term',
		'utm_content', 'gclid', 'fbclid', 'msclkid', 'ttclid',
	];

	var config = window.utmatBeacon;
	var search = window.location.search;

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
	var found = false;
	var i, eq, key, value;

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
		}
	}

	if ( ! found ) {
		return;
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
