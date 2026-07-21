/* global Chart, qrcode, utmatData */
/**
 * UTM & Attribution Tracker — Dashboard JS
 *
 * Fetches stats from the REST API and renders Chart.js charts, manages the
 * tracked-links table (list, add, edit, delete, QR), the CSV export, and the
 * settings card. All user-visible strings come from the utmat-i18n JSON
 * block so they pass through WordPress i18n on the PHP side.
 */
( function () {
	'use strict';

	// ── Config ────────────────────────────────────────────────────────────────
	const { apiBase, nonce, wooActive, currencySymbol, settings } = utmatData;

	// Translated strings from the PHP JSON block.
	const i18nEl = document.getElementById( 'utmat-i18n' );
	const i18n   = i18nEl ? JSON.parse( i18nEl.textContent ) : {};

	// ── Palette — primary blue as the primary series, neutral accents after ──
	const COLORS = [
		'#0d5ea3', '#2e8540', '#b32d2e', '#996800',
		'#5b3ea3', '#0a7c8c', '#8a4b08', '#646970',
	];

	// ── State ─────────────────────────────────────────────────────────────────
	let activePeriod   = 7;
	let activeCampaign = '';
	let charts         = {};
	let editingId      = null;
	let allLinks       = [];
	let linksFilter    = '';
	let clicksSortDir  = ''; // '' (server order), 'asc', or 'desc'.

	// ── DOM refs ──────────────────────────────────────────────────────────────
	const elLoading      = document.getElementById( 'utmat-loading' );
	const elError        = document.getElementById( 'utmat-error' );
	const elErrorMsg     = document.getElementById( 'utmat-error-msg' );
	const elCharts       = document.getElementById( 'utmat-charts' );
	const elLinksSection = document.getElementById( 'utmat-links-section' );
	const elTotal        = document.getElementById( 'utmat-total-count' );
	const elCampaignSel  = document.getElementById( 'utmat-campaign-filter' );
	const elLinksTbody   = document.getElementById( 'utmat-links-tbody' );
	const elAddForm      = document.getElementById( 'utmat-add-form' );
	const elAddMsg       = document.getElementById( 'utmat-add-msg' );
	const elRevenueCard  = document.getElementById( 'utmat-card-revenue' );
	const elSubmitBtn    = document.getElementById( 'utmat-submit-btn' );
	const elCancelBtn    = document.getElementById( 'utmat-cancel-edit' );
	const elExportBtn    = document.getElementById( 'utmat-export-btn' );
	const elNormNotice   = document.getElementById( 'utmat-normalize-notice' );
	const elNormDismiss  = document.getElementById( 'utmat-normalize-dismiss' );
	const elSettingsForm = document.getElementById( 'utmat-settings-form' );
	const elRetention    = document.getElementById( 'utmat-retention-days' );
	const elWeekly       = document.getElementById( 'utmat-weekly-email' );
	const elCookieDays   = document.getElementById( 'utmat-cookie-days' );
	const elSettingsMsg  = document.getElementById( 'utmat-settings-msg' );
	const elLinksFilter  = document.getElementById( 'utmat-links-filter' );
	const elSortClicks   = document.getElementById( 'utmat-sort-clicks' );

	// ── Chart.js global defaults (light theme) ───────────────────────────────
	Chart.defaults.color       = '#646970';
	Chart.defaults.borderColor = '#dcdcde';
	Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';
	Chart.defaults.font.size   = 12;

	// ── Helpers ───────────────────────────────────────────────────────────────
	function show( el ) { el.hidden = false; }
	function hide( el ) { el.hidden = true; }

	function setLoading( on ) {
		if ( on ) {
			show( elLoading );
			hide( elCharts );
			hide( elLinksSection );
			hide( elError );
		} else {
			hide( elLoading );
		}
	}

	function showError( msg ) {
		elErrorMsg.textContent = ( i18n.errorPrefix || 'Error:' ) + ' ' + msg;
		show( elError );
		hide( elCharts );
		hide( elLinksSection );
	}

	function apiFetch( path, options ) {
		return fetch( apiBase + path, Object.assign( {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': nonce },
		}, options || {} ) ).then( function ( res ) {
			return res.json().then( function ( body ) {
				if ( ! res.ok ) {
					throw new Error( body.message || res.statusText );
				}
				return body;
			} );
		} );
	}

	// Toggle between a chart canvas and its permanent "no data" sibling.
	// Never removes nodes, so the canvas survives empty periods (R1).
	function toggleNoData( canvasId, hasData ) {
		const canvas = document.getElementById( canvasId );
		if ( ! canvas ) return;
		const nodata = canvas.parentElement.querySelector( '.utmat-nodata' );
		canvas.hidden = ! hasData;
		if ( nodata ) {
			nodata.hidden = hasData;
		}
	}

	// ── Chart factories ───────────────────────────────────────────────────────
	function makeLineChart( canvasId, labels, values, conversions ) {
		const ctx = document.getElementById( canvasId );
		if ( ! ctx ) return;
		if ( charts[ canvasId ] ) charts[ canvasId ].destroy();

		toggleNoData( canvasId, true );

		const datasets = [ {
			label:           i18n.clicksLabel || 'Clicks',
			data:            values,
			borderColor:     COLORS[ 0 ],
			backgroundColor: 'rgba(13,94,163,0.08)',
			borderWidth:     2,
			pointRadius:     3,
			pointHoverRadius: 5,
			fill:            true,
			tension:         0.3,
		} ];

		// Overlay conversions only when the period has any — a permanent flat
		// zero line would just add noise on content sites without WooCommerce.
		const hasConversions = ( conversions || [] ).some( function ( v ) { return v > 0; } );
		if ( hasConversions ) {
			datasets.push( {
				label:           i18n.conversionsSeries || 'Conversions',
				data:            conversions,
				borderColor:     COLORS[ 1 ],
				backgroundColor: 'rgba(46,133,64,0.08)',
				borderWidth:     2,
				pointRadius:     3,
				pointHoverRadius: 5,
				fill:            true,
				tension:         0.3,
			} );
		}

		charts[ canvasId ] = new Chart( ctx, {
			type: 'line',
			data: {
				labels,
				datasets: datasets,
			},
			options: {
				responsive:          true,
				maintainAspectRatio: true,
				interaction:         { mode: 'index', intersect: false },
				plugins: {
					legend: { display: hasConversions, position: 'bottom', labels: { boxWidth: 10 } },
				},
				scales: {
					x: {
						grid:  { display: false },
						ticks: { maxTicksLimit: 10, maxRotation: 0 },
					},
					y: {
						beginAtZero: true,
						ticks:      { precision: 0 },
					},
				},
			},
		} );
	}

	function makeDoughnutChart( canvasId, data ) {
		const ctx = document.getElementById( canvasId );
		if ( ! ctx ) return;
		if ( charts[ canvasId ] ) {
			charts[ canvasId ].destroy();
			delete charts[ canvasId ];
		}

		toggleNoData( canvasId, data.length > 0 );
		if ( ! data.length ) {
			return;
		}

		charts[ canvasId ] = new Chart( ctx, {
			type: 'doughnut',
			data: {
				labels:   data.map( function ( d ) { return d.label; } ),
				datasets: [ {
					data:            data.map( function ( d ) { return d.count; } ),
					backgroundColor: COLORS.slice( 0, data.length ),
					borderColor:     '#fff',
					borderWidth:     2,
					hoverOffset:     6,
				} ],
			},
			options: {
				responsive:          true,
				maintainAspectRatio: true,
				cutout:              '65%',
				plugins: {
					legend: {
						position: 'bottom',
						labels:   { boxWidth: 10, padding: 14 },
					},
				},
			},
		} );
	}

	function makeBarChart( canvasId, data ) {
		const ctx = document.getElementById( canvasId );
		if ( ! ctx ) return;
		if ( charts[ canvasId ] ) {
			charts[ canvasId ].destroy();
			delete charts[ canvasId ];
		}

		toggleNoData( canvasId, data.length > 0 );
		if ( ! data.length ) {
			return;
		}

		charts[ canvasId ] = new Chart( ctx, {
			type: 'bar',
			data: {
				labels:   data.map( function ( d ) { return d.label; } ),
				datasets: [ {
					label:           i18n.clicksLabel || 'Clicks',
					data:            data.map( function ( d ) { return d.count; } ),
					backgroundColor: COLORS.slice( 0, data.length ),
					borderRadius:    2,
				} ],
			},
			options: {
				indexAxis:           'y',
				responsive:          true,
				maintainAspectRatio: true,
				plugins: {
					legend: { display: false },
				},
				scales: {
					x: {
						beginAtZero: true,
						ticks:      { precision: 0 },
					},
					y: { grid: { display: false } },
				},
			},
		} );
	}

	// Display names for the ad-platform keys derived server-side from click IDs.
	const PLATFORM_NAMES = {
		google:    i18n.platformGoogle || 'Google Ads',
		facebook:  i18n.platformFacebook || 'Facebook / Meta',
		microsoft: i18n.platformMicrosoft || 'Microsoft Ads',
		tiktok:    i18n.platformTiktok || 'TikTok Ads',
		linkedin:  i18n.platformLinkedin || 'LinkedIn Ads',
		twitter:   i18n.platformTwitter || 'X / Twitter Ads',
	};

	// Shorten a landing URL for chart labels: same-host URLs show their path,
	// other hosts keep host + path. Unparseable values pass through untouched.
	function shortenUrl( url ) {
		try {
			const u = new URL( url );
			const path = u.pathname || '/';
			return u.hostname === window.location.hostname ? path : u.hostname + path;
		} catch ( e ) {
			return url;
		}
	}

	function formatCurrency( value ) {
		return ( currencySymbol || '' ) + Number( value ).toLocaleString();
	}

	function makeRevenueChart( canvasId, data ) {
		const ctx = document.getElementById( canvasId );
		if ( ! ctx ) return;
		if ( charts[ canvasId ] ) {
			charts[ canvasId ].destroy();
			delete charts[ canvasId ];
		}

		toggleNoData( canvasId, data.length > 0 );
		if ( ! data.length ) {
			return;
		}

		charts[ canvasId ] = new Chart( ctx, {
			type: 'bar',
			data: {
				labels:   data.map( function ( d ) { return d.label; } ),
				datasets: [ {
					label:           i18n.revenueLabel || 'Revenue',
					data:            data.map( function ( d ) { return d.revenue; } ),
					backgroundColor: COLORS.slice( 0, data.length ),
					borderRadius:    2,
				} ],
			},
			options: {
				indexAxis:           'y',
				responsive:          true,
				maintainAspectRatio: true,
				plugins: {
					legend: { display: false },
					tooltip: {
						callbacks: {
							label: function ( item ) {
								const d = data[ item.dataIndex ];
								return formatCurrency( d.revenue ) + ' — ' +
									( i18n.ordersLabel || 'Orders' ) + ': ' + d.orders;
							},
						},
					},
				},
				scales: {
					x: {
						beginAtZero: true,
						ticks: {
							callback: function ( value ) { return formatCurrency( value ); },
						},
					},
					y: { grid: { display: false } },
				},
			},
		} );
	}

	// ── Conversions in the totals row ─────────────────────────────────────────
	// Shown only when the period has conversion rows (WooCommerce orders);
	// the rate is conversions over clicks for the same period.
	function renderConversions( clicks, conversions ) {
		const el = document.getElementById( 'utmat-conv' );
		if ( ! el ) return;

		clicks      = Number( clicks ) || 0;
		conversions = Number( conversions ) || 0;

		if ( ! conversions ) {
			el.hidden = true;
			return;
		}

		const rate = clicks > 0 ? ' (' + ( ( conversions / clicks ) * 100 ).toFixed( 1 ) + '%)' : '';
		el.textContent = '· ' + conversions + ' ' + ( i18n.conversionsLabel || 'conversions' ) + rate;
		el.hidden = false;
	}

	// ── Trend indicator ───────────────────────────────────────────────────────
	// Shows the change vs the same-length window immediately before the
	// selected period, next to the total.
	function renderTrend( current, previous ) {
		const el = document.getElementById( 'utmat-trend' );
		if ( ! el ) return;

		current  = Number( current ) || 0;
		previous = Number( previous ) || 0;

		if ( ! previous ) {
			el.hidden = true;
			return;
		}

		const pct = Math.round( ( ( current - previous ) / previous ) * 100 );
		let text, cls;
		if ( pct > 0 ) {
			text = '▲ ' + pct + '%';
			cls  = 'up';
		} else if ( pct < 0 ) {
			text = '▼ ' + Math.abs( pct ) + '%';
			cls  = 'down';
		} else {
			text = '±0%';
			cls  = 'flat';
		}

		el.textContent = text + ' ' + ( i18n.vsPrevious || 'vs previous period' );
		el.className   = 'utmat-trend utmat-trend--' + cls;
		el.hidden      = false;
	}

	// ── Populate campaigns dropdown ────────────────────────────────────────────
	function populateCampaigns( campaigns ) {
		// Remove all options except the first ("All campaigns").
		while ( elCampaignSel.options.length > 1 ) {
			elCampaignSel.remove( 1 );
		}
		campaigns.forEach( function ( c ) {
			const opt   = document.createElement( 'option' );
			opt.value   = c;
			opt.textContent = c;
			elCampaignSel.appendChild( opt );
		} );
		elCampaignSel.value = activeCampaign;
	}

	// ── QR codes (qrcode-generator, bundled) ──────────────────────────────────
	// Draws the QR modules onto a canvas so the PNG download can come straight
	// from canvas.toDataURL — no external service involved.
	function renderQrCanvas( text, canvas ) {
		const qr = qrcode( 0, 'M' );
		qr.addData( text );
		qr.make();

		const count  = qr.getModuleCount();
		const cell   = 4;
		const margin = cell * 4; // Quiet zone: 4 modules per QR spec.
		const size   = count * cell + margin * 2;

		canvas.width  = size;
		canvas.height = size;

		const ctx = canvas.getContext( '2d' );
		ctx.fillStyle = '#fff';
		ctx.fillRect( 0, 0, size, size );
		ctx.fillStyle = '#000';
		for ( let row = 0; row < count; row++ ) {
			for ( let col = 0; col < count; col++ ) {
				if ( qr.isDark( row, col ) ) {
					ctx.fillRect( margin + col * cell, margin + row * cell, cell, cell );
				}
			}
		}
	}

	function closeQrRows() {
		elLinksTbody.querySelectorAll( '.utmat-qr-row' ).forEach( function ( r ) {
			r.remove();
		} );
		elLinksTbody.querySelectorAll( '.utmat-qr-btn' ).forEach( function ( b ) {
			b.setAttribute( 'aria-expanded', 'false' );
		} );
	}

	function toggleQrRow( link, row, btn ) {
		const next   = row.nextElementSibling;
		const isOpen = next && next.classList.contains( 'utmat-qr-row' );

		closeQrRows();
		if ( isOpen ) {
			return;
		}

		const tr = document.createElement( 'tr' );
		tr.className = 'utmat-qr-row';
		const td = document.createElement( 'td' );
		td.colSpan = 6;

		const box = document.createElement( 'div' );
		box.className = 'utmat-qr-box';

		const canvas = document.createElement( 'canvas' );
		canvas.className = 'utmat-qr-canvas';
		canvas.setAttribute( 'role', 'img' );
		canvas.setAttribute( 'aria-label', ( i18n.qrCode || 'QR code' ) + ': ' + link.redirect_url );
		renderQrCanvas( link.redirect_url, canvas );

		const meta = document.createElement( 'div' );
		meta.className = 'utmat-qr-meta';

		const urlSpan = document.createElement( 'span' );
		urlSpan.className   = 'utmat-url-text';
		urlSpan.textContent = link.redirect_url;

		const download = document.createElement( 'a' );
		download.className   = 'button button-small';
		download.textContent = i18n.downloadPng || 'Download PNG';
		download.href        = canvas.toDataURL( 'image/png' );
		download.download    = 'utmat-' + link.id + '-qr.png';

		meta.appendChild( urlSpan );
		meta.appendChild( download );
		box.appendChild( canvas );
		box.appendChild( meta );
		td.appendChild( box );
		tr.appendChild( td );

		row.parentNode.insertBefore( tr, row.nextSibling );
		btn.setAttribute( 'aria-expanded', 'true' );
	}

	// ── Search + sort over the already-fetched links list ─────────────────────
	// Both operate client-side on the full list held in `allLinks` — no extra
	// request, since the whole list is already in memory for the table.
	function visibleLinks() {
		let list = allLinks;

		const needle = linksFilter.trim().toLowerCase();
		if ( needle ) {
			list = list.filter( function ( link ) {
				return [ link.label, link.utm_campaign, link.utm_source, link.utm_medium ]
					.some( function ( field ) { return ( field || '' ).toLowerCase().indexOf( needle ) !== -1; } );
			} );
		}

		if ( clicksSortDir ) {
			list = list.slice().sort( function ( a, b ) {
				return clicksSortDir === 'asc' ? a.clicks - b.clicks : b.clicks - a.clicks;
			} );
		}

		return list;
	}

	function refreshLinksTable() {
		renderLinksTable( visibleLinks() );
	}

	// ── Populate links table ───────────────────────────────────────────────────
	function renderLinksTable( links ) {
		elLinksTbody.innerHTML = '';

		if ( elSortClicks ) {
			elSortClicks.querySelector( '.utmat-sort-arrow' ).textContent =
				clicksSortDir === 'asc' ? '▲' : ( clicksSortDir === 'desc' ? '▼' : '' );
		}

		if ( ! links.length ) {
			const row = elLinksTbody.insertRow();
			const cell = row.insertCell();
			cell.colSpan = 6;
			cell.className = 'description';
			cell.textContent = allLinks.length
				? ( i18n.noLinksMatch || 'No tracked links match your search.' )
				: ( i18n.noLinks || 'No tracked links found.' );
			return;
		}

		links.forEach( function ( link ) {
			const row = elLinksTbody.insertRow();
			row.dataset.linkId = link.id;

			// Label.
			row.insertCell().textContent = link.label;

			// Campaign.
			row.insertCell().textContent = link.utm_campaign || '—';

			// Clicks badge.
			const clicksCell  = row.insertCell();
			const clicksBadge = document.createElement( 'span' );
			clicksBadge.className   = 'utmat-clicks-badge';
			clicksBadge.textContent = String( link.clicks );
			clicksCell.appendChild( clicksBadge );

			// Redirect URL + copy button.
			const redirectCell = row.insertCell();
			const wrapper = document.createElement( 'div' );
			wrapper.className = 'utmat-url-cell';
			const urlSpan = document.createElement( 'span' );
			urlSpan.className   = 'utmat-url-text';
			urlSpan.textContent = link.redirect_url;
			urlSpan.title       = link.redirect_url;
			const copyBtn = document.createElement( 'button' );
			copyBtn.className   = 'button button-small utmat-copy-btn';
			copyBtn.textContent = i18n.copy || 'Copy';
			copyBtn.type        = 'button';
			copyBtn.addEventListener( 'click', function () {
				navigator.clipboard.writeText( link.redirect_url ).then( function () {
					copyBtn.textContent = i18n.copied || 'Copied!';
					copyBtn.classList.add( 'copied' );
					setTimeout( function () {
						copyBtn.textContent = i18n.copy || 'Copy';
						copyBtn.classList.remove( 'copied' );
					}, 2000 );
				} );
			} );
			wrapper.appendChild( urlSpan );
			wrapper.appendChild( copyBtn );
			redirectCell.appendChild( wrapper );

			// Destination.
			const destCell = row.insertCell();
			destCell.className = 'utmat-url-text';
			destCell.textContent = link.destination;
			destCell.title       = link.destination;

			// QR / Edit / Delete.
			const actionsCell = row.insertCell();

			const qrBtn = document.createElement( 'button' );
			qrBtn.type      = 'button';
			qrBtn.className = 'button button-small utmat-qr-btn';
			qrBtn.textContent = i18n.qr || 'QR';
			qrBtn.setAttribute( 'aria-expanded', 'false' );
			qrBtn.addEventListener( 'click', function () {
				toggleQrRow( link, row, qrBtn );
			} );
			actionsCell.appendChild( qrBtn );

			const editBtn = document.createElement( 'button' );
			editBtn.type      = 'button';
			editBtn.className = 'button button-small utmat-edit-btn';
			editBtn.textContent = i18n.edit || 'Edit';
			editBtn.addEventListener( 'click', function () {
				enterEditMode( link );
			} );
			actionsCell.appendChild( editBtn );

			const deleteBtn = document.createElement( 'button' );
			deleteBtn.type      = 'button';
			deleteBtn.className = 'button button-small button-link-delete utmat-delete-btn';
			deleteBtn.textContent = i18n.delete || 'Delete';
			deleteBtn.addEventListener( 'click', function () {
				if ( deleteBtn.dataset.confirming ) {
					deleteLink( link.id, row );
					return;
				}
				deleteBtn.dataset.confirming = '1';
				deleteBtn.textContent = i18n.confirmDelete || 'Confirm?';
				setTimeout( function () {
					delete deleteBtn.dataset.confirming;
					deleteBtn.textContent = i18n.delete || 'Delete';
				}, 3000 );
			} );
			actionsCell.appendChild( deleteBtn );
		} );

		show( elLinksSection );
	}

	// ── Delete a link ─────────────────────────────────────────────────────────
	function deleteLink( id, row ) {
		apiFetch( '/links/' + encodeURIComponent( id ), { method: 'DELETE' } )
			.then( function () {
				allLinks = allLinks.filter( function ( link ) { return link.id !== id; } );
				const next = row.nextElementSibling;
				if ( next && next.classList.contains( 'utmat-qr-row' ) ) {
					next.remove();
				}
				row.remove();
				if ( ! elLinksTbody.rows.length ) {
					refreshLinksTable();
				}
			} )
			.catch( function ( err ) {
				window.alert( err.message || 'Unknown error.' ); // eslint-disable-line no-alert
			} );
	}

	// ── UTM naming conventions ────────────────────────────────────────────────
	// Inconsistent case/spaces silently fragment reports ("My Newsletter" vs
	// "my-newsletter"), so utm_* values are normalized on blur and on submit.
	// The server applies the same normalization authoritatively — keep in
	// sync with RestController::normalize_utm().
	const UTM_FIELDS = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_term', 'utm_content' ];

	function normalizeUtm( value ) {
		return value.trim().toLowerCase().replace( /\s+/g, '-' );
	}

	// Normalizes one input in place; returns whether the value changed.
	function normalizeField( input ) {
		const normalized = normalizeUtm( input.value );
		if ( normalized === input.value ) {
			return false;
		}
		input.value = normalized;
		return true;
	}

	function normalizeAllFields() {
		let changed = false;
		UTM_FIELDS.forEach( function ( name ) {
			const input = elAddForm.elements[ name ];
			if ( input && normalizeField( input ) ) {
				changed = true;
			}
		} );
		return changed;
	}

	UTM_FIELDS.forEach( function ( name ) {
		const input = elAddForm.elements[ name ];
		if ( ! input ) {
			return;
		}
		input.addEventListener( 'blur', function () {
			if ( normalizeField( input ) ) {
				show( elNormNotice );
			}
		} );
	} );

	elNormDismiss.addEventListener( 'click', function () {
		hide( elNormNotice );
	} );

	// ── Suggestion datalists ──────────────────────────────────────────────────
	function populateDatalists( suggestions ) {
		const map = {
			campaigns: 'utmat-list-campaign',
			sources:   'utmat-list-source',
			mediums:   'utmat-list-medium',
		};
		Object.keys( map ).forEach( function ( key ) {
			const el = document.getElementById( map[ key ] );
			if ( ! el ) {
				return;
			}
			el.innerHTML = '';
			( suggestions[ key ] || [] ).forEach( function ( value ) {
				const opt = document.createElement( 'option' );
				opt.value = value;
				el.appendChild( opt );
			} );
		} );
	}

	// ── Add / edit link form ──────────────────────────────────────────────────
	// Editing reuses the add form: fields are pre-filled from the link, the
	// submit button becomes "Save changes", and the slug is locked (it is not
	// a form field — the PUT request targets the stored slug directly).
	function enterEditMode( link ) {
		editingId = link.id;

		elAddForm.elements.label.value        = link.label;
		elAddForm.elements.destination.value  = link.destination_raw;
		elAddForm.elements.utm_campaign.value = link.utm_campaign;
		elAddForm.elements.utm_source.value   = link.utm_source;
		elAddForm.elements.utm_medium.value   = link.utm_medium;
		elAddForm.elements.utm_term.value     = link.utm_term;
		elAddForm.elements.utm_content.value  = link.utm_content;
		elAddForm.elements.utm_id.value       = link.utm_id;

		const advanced = elAddForm.querySelector( '.utmat-add-form__advanced' );
		if ( advanced ) {
			advanced.open = !! ( link.utm_term || link.utm_content || link.utm_id );
		}

		elSubmitBtn.textContent = i18n.saveChanges || 'Save changes';
		show( elCancelBtn );
		elAddMsg.textContent = '';
		elAddMsg.className = 'utmat-add-form__msg';
		elAddForm.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	function exitEditMode() {
		editingId = null;
		elAddForm.reset();
		elSubmitBtn.textContent = i18n.addLink || 'Add link';
		hide( elCancelBtn );
	}

	elCancelBtn.addEventListener( 'click', function () {
		exitEditMode();
		elAddMsg.textContent = '';
		elAddMsg.className = 'utmat-add-form__msg';
	} );

	elAddForm.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		if ( normalizeAllFields() ) {
			show( elNormNotice );
		}

		const formData = new FormData( elAddForm );
		const payload  = {};
		formData.forEach( function ( value, key ) {
			payload[ key ] = value;
		} );

		const isEdit = null !== editingId;
		const path   = isEdit ? '/links/' + encodeURIComponent( editingId ) : '/links';

		elAddMsg.textContent = '';
		elAddMsg.className = 'utmat-add-form__msg';

		apiFetch( path, {
			method:  isEdit ? 'PUT' : 'POST',
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			body:    JSON.stringify( payload ),
		} ).then( function () {
			exitEditMode();
			elAddMsg.textContent = isEdit ? ( i18n.updated || 'Link updated.' ) : ( i18n.added || 'Link added.' );
			elAddMsg.className = 'utmat-add-form__msg is-success';
			loadDashboard();
		} ).catch( function ( err ) {
			elAddMsg.textContent = err.message || 'Unknown error.';
			elAddMsg.className = 'utmat-add-form__msg is-error';
		} );
	} );

	// ── CSV export ────────────────────────────────────────────────────────────
	// The REST endpoint returns raw JSON rows (capped at 10,000); the CSV is
	// assembled here. Every cell is quoted, and values starting with = + - @
	// are prefixed with ' so a crafted UTM value can never execute as a
	// spreadsheet formula.
	const EXPORT_COLUMNS = [
		'clicked_at', 'link_id', 'utm_source', 'utm_medium', 'utm_campaign',
		'utm_id', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid',
		'ttclid', 'li_fat_id', 'twclid', 'destination', 'referrer', 'is_conversion', 'order_total',
	];

	function csvCell( value ) {
		let v = ( null === value || undefined === value ) ? '' : String( value );
		if ( /^[=+\-@\t\r]/.test( v ) ) {
			v = "'" + v;
		}
		return '"' + v.replace( /"/g, '""' ) + '"';
	}

	function exportCsv() {
		elExportBtn.disabled    = true;
		elExportBtn.textContent = i18n.exporting || 'Exporting…';

		const url = '/export?period=' + activePeriod +
			( activeCampaign ? '&campaign=' + encodeURIComponent( activeCampaign ) : '' );

		apiFetch( url ).then( function ( body ) {
			const rows = body.rows || [];
			if ( ! rows.length ) {
				window.alert( i18n.exportEmpty || 'No events to export for this period.' ); // eslint-disable-line no-alert
				return;
			}

			const lines = [ EXPORT_COLUMNS.map( csvCell ).join( ',' ) ];
			rows.forEach( function ( row ) {
				lines.push( EXPORT_COLUMNS.map( function ( col ) {
					return csvCell( row[ col ] );
				} ).join( ',' ) );
			} );

			const now   = new Date();
			const pad   = function ( n ) { return String( n ).padStart( 2, '0' ); };
			const stamp = now.getFullYear() + pad( now.getMonth() + 1 ) + pad( now.getDate() );
			const blob  = new Blob( [ lines.join( '\r\n' ) + '\r\n' ], { type: 'text/csv;charset=utf-8;' } );

			const a    = document.createElement( 'a' );
			a.href     = URL.createObjectURL( blob );
			a.download = 'utmat-export-' + stamp + '.csv';
			document.body.appendChild( a );
			a.click();
			a.remove();
			URL.revokeObjectURL( a.href );
		} ).catch( function ( err ) {
			window.alert( err.message || 'Unknown error.' ); // eslint-disable-line no-alert
		} ).then( function () {
			elExportBtn.disabled    = false;
			elExportBtn.textContent = i18n.exportCsv || 'Export CSV';
		} );
	}

	elExportBtn.addEventListener( 'click', exportCsv );

	// ── Settings card ─────────────────────────────────────────────────────────
	if ( settings ) {
		elRetention.value  = settings.retentionDays;
		elWeekly.checked   = !! settings.weeklyEmail;
		elCookieDays.value = settings.cookieDays || 90;
	}

	elSettingsForm.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		elSettingsMsg.textContent = '';
		elSettingsMsg.className   = 'utmat-add-form__msg';

		apiFetch( '/settings', {
			method:  'POST',
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			body:    JSON.stringify( {
				retention_days: parseInt( elRetention.value, 10 ) || 0,
				weekly_email:   elWeekly.checked,
				cookie_days:    parseInt( elCookieDays.value, 10 ) || 90,
			} ),
		} ).then( function ( body ) {
			elRetention.value  = body.retention_days;
			elWeekly.checked   = !! body.weekly_email;
			elCookieDays.value = body.cookie_days || 90;
			elSettingsMsg.textContent = i18n.settingsSaved || 'Settings saved.';
			elSettingsMsg.className   = 'utmat-add-form__msg is-success';
		} ).catch( function ( err ) {
			elSettingsMsg.textContent = err.message || 'Unknown error.';
			elSettingsMsg.className   = 'utmat-add-form__msg is-error';
		} );
	} );

	// ── Test email ────────────────────────────────────────────────────────────
	const elTestEmail = document.getElementById( 'utmat-test-email' );

	elTestEmail.addEventListener( 'click', function () {
		elTestEmail.disabled      = true;
		elSettingsMsg.textContent = i18n.testEmailSending || 'Sending…';
		elSettingsMsg.className   = 'utmat-add-form__msg';

		apiFetch( '/test-email', { method: 'POST' } ).then( function ( body ) {
			elSettingsMsg.textContent = body.sent
				? ( i18n.testEmailSent || 'Test email sent.' )
				: ( i18n.testEmailFailed || 'Sending failed.' );
			elSettingsMsg.className = 'utmat-add-form__msg ' + ( body.sent ? 'is-success' : 'is-error' );
		} ).catch( function ( err ) {
			elSettingsMsg.textContent = err.message || 'Unknown error.';
			elSettingsMsg.className   = 'utmat-add-form__msg is-error';
		} ).then( function () {
			elTestEmail.disabled = false;
		} );
	} );

	// ── Main fetch ────────────────────────────────────────────────────────────
	function loadDashboard() {
		setLoading( true );

		const statsUrl = '/stats?period=' + activePeriod +
			( activeCampaign ? '&campaign=' + encodeURIComponent( activeCampaign ) : '' );

		Promise.all( [
			apiFetch( statsUrl ),
			apiFetch( '/links' ),
		] ).then( function ( results ) {
			const stats    = results[ 0 ];
			const linksRes = results[ 1 ] || {};

			setLoading( false );
			show( elCharts );

			// First-run onboarding — visible only while nothing was ever recorded.
			const onboarding = document.getElementById( 'utmat-onboarding' );
			if ( onboarding ) {
				onboarding.hidden = ( Number( stats.all_time_total ) || 0 ) > 0;
			}

			// Total count, conversions, and trend vs the previous period.
			elTotal.textContent = stats.total;
			renderConversions( stats.total, stats.conversions );
			renderTrend( stats.total, stats.previous_total );

			// Populate campaign filter.
			populateCampaigns( stats.campaigns || [] );

			// Line chart — clicks (and conversions when present) by day.
			const days   = ( stats.clicks_by_day || [] ).map( function ( d ) { return d.date; } );
			const counts = ( stats.clicks_by_day || [] ).map( function ( d ) { return d.count; } );
			const convs  = ( stats.clicks_by_day || [] ).map( function ( d ) { return d.conversions || 0; } );
			makeLineChart( 'utmat-chart-line', days, counts, convs );

			// Doughnut — by source.
			makeDoughnutChart( 'utmat-chart-source', stats.by_source || [] );

			// Bar — by campaign.
			makeBarChart( 'utmat-chart-campaign', stats.by_campaign || [] );

			// Bar — by medium.
			makeBarChart( 'utmat-chart-medium', stats.by_medium || [] );

			// Bar — top landing pages (labels shortened to the URL path).
			makeBarChart( 'utmat-chart-landing', ( stats.by_landing || [] ).map( function ( d ) {
				return { label: shortenUrl( d.label ), count: d.count };
			} ) );

			// Bar — top referrers (external hosts only).
			makeBarChart( 'utmat-chart-referrer', stats.by_referrer || [] );

			// Doughnut — ad platforms, derived from click IDs.
			makeDoughnutChart( 'utmat-chart-platform', ( stats.by_platform || [] ).map( function ( d ) {
				return { label: PLATFORM_NAMES[ d.label ] || d.label, count: d.count };
			} ) );

			// Bar — revenue by campaign (WooCommerce only).
			if ( wooActive && elRevenueCard ) {
				show( elRevenueCard );
				makeRevenueChart( 'utmat-chart-revenue', stats.revenue_by_campaign || [] );
			}

			// Links table + suggestion datalists.
			allLinks = linksRes.links || [];
			refreshLinksTable();
			populateDatalists( linksRes.suggestions || {} );
		} ).catch( function ( err ) {
			setLoading( false );
			showError( err.message || 'Unknown error.' );
		} );
	}

	// ── Event listeners ───────────────────────────────────────────────────────
	document.querySelectorAll( '.utmat-period-btn' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			document.querySelectorAll( '.utmat-period-btn' ).forEach( function ( b ) {
				b.classList.remove( 'active' );
				b.setAttribute( 'aria-pressed', 'false' );
			} );
			btn.classList.add( 'active' );
			btn.setAttribute( 'aria-pressed', 'true' );
			activePeriod = parseInt( btn.dataset.period, 10 );
			loadDashboard();
		} );
	} );

	elCampaignSel.addEventListener( 'change', function () {
		activeCampaign = elCampaignSel.value;
		loadDashboard();
	} );

	if ( elLinksFilter ) {
		elLinksFilter.addEventListener( 'input', function () {
			linksFilter = elLinksFilter.value;
			refreshLinksTable();
		} );
	}

	if ( elSortClicks ) {
		const toggleClicksSort = function () {
			clicksSortDir = clicksSortDir === 'desc' ? 'asc' : 'desc';
			refreshLinksTable();
		};
		elSortClicks.addEventListener( 'click', toggleClicksSort );
		elSortClicks.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key || ' ' === e.key ) {
				e.preventDefault();
				toggleClicksSort();
			}
		} );
	}

	// ── Boot ──────────────────────────────────────────────────────────────────
	loadDashboard();
} )();
