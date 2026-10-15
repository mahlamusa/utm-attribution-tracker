/* global Chart, qrcode, utmatData */
/**
 * UTM & Attribution Tracker — Dashboard JS
 *
 * Drives the tabbed dashboard: the Overview tab (stat tiles, Chart.js line
 * and revenue charts, breakdown bar lists, first-run empty state), the Links
 * tab (tracked-links table with row actions, QR codes, bulk delete and the
 * add/edit drawer), the Journeys tab (conversions with their touch
 * sequences, loaded lazily on first activation), the Settings tab (save
 * bar, test email and webhook) and the Diagnostics tab. Also handles the
 * CSV export. All user-visible strings come from the utmat-i18n JSON block
 * so they pass through WordPress i18n on the PHP side.
 *
 * @since 1.0.0
 */
( function () {
	'use strict';

	// ── Config ────────────────────────────────────────────────────────────────
	const { apiBase, nonce, wooActive, currencySymbol, settings, cachePlugin } = utmatData;

	// Translated strings from the PHP JSON block.
	const i18nEl = document.getElementById( 'utmat-i18n' );
	const i18n   = i18nEl ? JSON.parse( i18nEl.textContent ) : {};

	// ── Palette — primary blue as the first series, neutral accents after ──
	const COLORS = [
		'#0d5ea3', '#2e8540', '#b32d2e', '#996800',
		'#5b3ea3', '#0a7c8c', '#8a4b08', '#646970',
	];

	// ── State ─────────────────────────────────────────────────────────────────
	let activePeriod   = 7;
	let activeCampaign = '';
	let activeSince    = ''; // Custom date range (Y-m-d); both must be set to take effect.
	let activeUntil    = '';
	let charts         = {};
	let editingId      = null;
	let allLinks       = [];
	let linksFilter    = '';
	let linksCampaign  = ''; // Client-side campaign filter of the links table.
	let clicksSortDir  = ''; // '' (server order), 'asc', or 'desc'.
	const selectedLinks = new Set(); // Link IDs checked for bulk delete.
	let allPresets      = [];
	let drawerReturnFocus = null;
	let settingsBaseline  = null;

	const TAB_NAMES = [ 'overview', 'links', 'journeys', 'settings', 'diagnostics' ];
	const LINK_COLS = 7; // Checkbox, Label, Campaign, Source / medium, Clicks, Short link, Destination.
	const JOURNEY_COLS = 5; // When, Conversion, First touch, Last touch, Touches.

	// Column names for core's mobile list-table view (data-colname). The
	// checkbox header is a td, so index 0 is Label.
	const LINK_COL_NAMES = Array.prototype.map.call(
		document.querySelectorAll( '#utmat-links-table thead th' ),
		function ( th ) { return th.textContent.trim(); }
	);

	// Same for the Journeys table (no checkbox column; index 0 is When).
	const JOURNEY_COL_NAMES = Array.prototype.map.call(
		document.querySelectorAll( '#utmat-journeys-table thead th' ),
		function ( th ) { return th.textContent.trim(); }
	);

	const COPY_ICON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';

	const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea, summary, [tabindex]:not([tabindex="-1"])';

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
	const elConsentCategory = document.getElementById( 'utmat-consent-category' );
	const elTrackVisits  = document.getElementById( 'utmat-track-visits' );
	const elCustomParams = document.getElementById( 'utmat-custom-params' );
	const elChannelHint  = document.getElementById( 'utmat-channel-hint' );
	const elSettingsMsg  = document.getElementById( 'utmat-settings-msg' );
	const elLinksFilter  = document.getElementById( 'utmat-links-filter' );
	const elSortClicks   = document.getElementById( 'utmat-sort-clicks' );
	const elSelectAll    = document.getElementById( 'utmat-select-all' );
	const elBulkDeleteBtn = document.getElementById( 'utmat-bulk-delete-btn' );

	const elAddLinkBtn      = document.getElementById( 'utmat-add-link-btn' );
	const elLive            = document.getElementById( 'utmat-live' );
	const elFilters         = document.getElementById( 'utmat-filters' );
	const elCompareCaption  = document.getElementById( 'utmat-compare-caption' );
	const elPlaceholder     = document.getElementById( 'utmat-charts-placeholder' );
	const elLinksCampaign   = document.getElementById( 'utmat-links-campaign' );
	const elLinksCount      = document.getElementById( 'utmat-links-count' );
	const elLinksMsg        = document.getElementById( 'utmat-links-msg' );
	let linksMsgTimer       = null;

	// Journeys tab state: loaded lazily on first activation (never part of
	// loadDashboard()'s Promise.all, so a failing /journeys request can never
	// blank the overview).
	let journeysLoaded = false;

	// Success text in the Links toolbar clears itself so it never outlives the
	// action it confirms.
	function flashLinksMsg( text ) {
		if ( ! elLinksMsg ) return;
		window.clearTimeout( linksMsgTimer );
		elLinksMsg.textContent = text;
		elLinksMsg.className   = 'utmat-add-form__msg is-success';
		linksMsgTimer = window.setTimeout( function () {
			elLinksMsg.textContent = '';
			elLinksMsg.className   = 'utmat-add-form__msg';
		}, 5000 );
	}
	const elDrawer          = document.getElementById( 'utmat-drawer' );
	const elDrawerBackdrop  = document.getElementById( 'utmat-drawer-backdrop' );
	const elDrawerTitle     = document.getElementById( 'utmat-drawer-title' );
	const elDrawerClose     = document.getElementById( 'utmat-drawer-close' );
	const elDrawerShortlink = document.getElementById( 'utmat-drawer-shortlink' );
	const elDrawerPreview   = document.getElementById( 'utmat-drawer-preview' );
	const elDrawerShortcode = document.getElementById( 'utmat-drawer-shortcode' );
	const elBuilderRow      = document.getElementById( 'utmat-builder-row' );
	const elBuilderToggle   = document.getElementById( 'utmat-builder-toggle' );
	const elLabelField      = document.getElementById( 'utmat-drawer-label-field' );
	const elShortlinkLabel  = document.getElementById( 'utmat-drawer-shortlink-label' );
	const elPreviewLabel    = document.getElementById( 'utmat-drawer-preview-label' );
	const elPreviewLabelBuilder = document.getElementById( 'utmat-drawer-preview-label-builder' );
	const elDrawerCustom    = document.getElementById( 'utmat-drawer-custom' );
	const elDrawerCustomGrid = document.getElementById( 'utmat-drawer-custom-grid' );
	const elUnsaved         = document.getElementById( 'utmat-unsaved-count' );
	const elSavebar         = document.getElementById( 'utmat-savebar' );
	const elDiscard         = document.getElementById( 'utmat-settings-discard' );
	const elTestEmailMsg    = document.getElementById( 'utmat-test-email-msg' );
	const elTestWebhookMsg  = document.getElementById( 'utmat-test-webhook-msg' );
	const elSecretCopy      = document.getElementById( 'utmat-webhook-secret-copy' );
	const elDiagLast        = document.getElementById( 'utmat-diagnostics-last' );
	const elDiagSummary     = document.getElementById( 'utmat-diag-summary' );
	const elJourneysMsg     = document.getElementById( 'utmat-journeys-msg' );
	const elJourneysLoading = document.getElementById( 'utmat-journeys-loading' );
	const elJourneysEmpty   = document.getElementById( 'utmat-journeys-empty' );
	const elJourneysTable   = document.getElementById( 'utmat-journeys-table' );
	const elJourneysTbody   = document.getElementById( 'utmat-journeys-tbody' );
	const elJourneysWithout = document.getElementById( 'utmat-journeys-without' );

	// ── Chart.js global defaults (light theme) ───────────────────────────────
	Chart.defaults.color       = '#646970';
	Chart.defaults.borderColor = '#dcdcde';
	Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';
	Chart.defaults.font.size   = 12;

	// ── Helpers ───────────────────────────────────────────────────────────────
	function show( el ) { el.hidden = false; }
	function hide( el ) { el.hidden = true; }

	/**
	 * Fill a translated string's placeholders.
	 *
	 * Handles numbered (%1$s, %2$s) and sequential (%s) placeholders in a
	 * single pass, so a substituted value is never scanned again.
	 *
	 * @since 1.0.0
	 *
	 * @param {string} str Translated string with placeholders.
	 * @return {string} The filled string, or '' for a non-string.
	 */
	function fmt( str ) {
		if ( 'string' !== typeof str ) {
			return '';
		}
		const args = Array.prototype.slice.call( arguments, 1 );
		let next   = 0;
		return str.replace( /%(?:(\d+)\$)?s/g, function ( match, pos ) {
			const value = pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ next++ ];
			return undefined === value || null === value ? '' : String( value );
		} );
	}

	function formatNumber( n ) {
		return ( Number( n ) || 0 ).toLocaleString();
	}

	// Announce a short message to screen readers through the live region.
	function announce( msg ) {
		if ( ! elLive ) return;
		elLive.textContent = '';
		window.requestAnimationFrame( function () {
			elLive.textContent = msg;
		} );
	}

	// Fallback for non-secure (http) admins where navigator.clipboard is undefined.
	function legacyCopyText( text ) {
		return new Promise( function ( resolve, reject ) {
			const active = document.activeElement;
			const ta     = document.createElement( 'textarea' );
			ta.value = text;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'fixed';
			ta.style.top      = '0';
			ta.style.left     = '0';
			ta.style.opacity  = '0';
			document.body.appendChild( ta );
			ta.select();
			let ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			document.body.removeChild( ta );
			if ( active && active.focus ) {
				active.focus();
			}
			if ( ok ) {
				resolve();
			} else {
				reject( new Error( 'clipboard' ) );
			}
		} );
	}

	function copyText( text ) {
		if ( navigator.clipboard ) {
			return navigator.clipboard.writeText( text ).catch( function () {
				return legacyCopyText( text );
			} );
		}
		return legacyCopyText( text );
	}

	// Copy, then swap a text button's label to "Copied!" for two seconds.
	function copyWithTextFeedback( btn, text ) {
		copyText( text ).then( function () {
			if ( ! btn.dataset.label ) {
				btn.dataset.label = btn.textContent;
			}
			btn.textContent = i18n.copied || 'Copied!';
			announce( i18n.copied || 'Copied!' );
			setTimeout( function () {
				btn.textContent = btn.dataset.label;
				delete btn.dataset.label;
			}, 2000 );
		} ).catch( function () {
			if ( ! btn.dataset.label ) {
				btn.dataset.label = btn.textContent;
			}
			btn.textContent = i18n.copyFailed || 'Copy failed';
			announce( i18n.copyFailed || 'Copy failed' );
			setTimeout( function () {
				btn.textContent = btn.dataset.label;
				delete btn.dataset.label;
			}, 2000 );
		} );
	}

	// Compact icon-only copy button; the label lives in a screen-reader span.
	function makeCopyIconButton( text, label ) {
		const btn = document.createElement( 'button' );
		btn.type      = 'button';
		btn.className = 'utmat-icon-btn utmat-copy-btn';
		btn.title     = label;
		btn.innerHTML = COPY_ICON;
		const sr = document.createElement( 'span' );
		sr.className   = 'screen-reader-text';
		sr.textContent = label;
		btn.appendChild( sr );
		btn.addEventListener( 'click', function () {
			copyText( text ).then( function () {
				btn.classList.add( 'copied' );
				btn.title = i18n.copied || 'Copied!';
				announce( i18n.copied || 'Copied!' );
				setTimeout( function () {
					btn.classList.remove( 'copied' );
					btn.title = label;
				}, 2000 );
			} ).catch( function () {
				btn.classList.add( 'failed' );
				btn.title = i18n.copyFailed || 'Copy failed';
				announce( i18n.copyFailed || 'Copy failed' );
				setTimeout( function () {
					btn.classList.remove( 'failed' );
					btn.title = label;
				}, 2000 );
			} );
		} );
		return btn;
	}

	// One core-style row action: span.<key> > button.button-link + separator.
	function makeRowAction( key, className, label, onClick, isLast ) {
		const wrap = document.createElement( 'span' );
		wrap.className = key;
		const button = document.createElement( 'button' );
		button.type        = 'button';
		button.className   = 'button-link ' + className;
		button.textContent = label;
		if ( onClick ) {
			button.addEventListener( 'click', onClick );
		}
		wrap.appendChild( button );
		if ( ! isLast ) {
			const sep = document.createElement( 'span' );
			sep.className   = 'utmat-row-actions__sep';
			sep.textContent = ' | ';
			sep.setAttribute( 'aria-hidden', 'true' );
			wrap.appendChild( sep );
		}
		return { wrap: wrap, button: button };
	}

	function setLoading( on ) {
		if ( on ) {
			show( elLoading );
			hide( elCharts );
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
		// On plain permalinks the base is index.php?rest_route=..., so the
		// path's own query string must join with & instead of a second ?.
		const url = -1 !== apiBase.indexOf( '?' ) ? apiBase + path.replace( '?', '&' ) : apiBase + path;
		return fetch( url, Object.assign( {
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

	// Toggle between a chart canvas (or bar list) and its permanent "no data"
	// sibling. Never removes nodes, so the canvas survives empty periods.
	function toggleNoData( canvasId, hasData ) {
		const canvas = document.getElementById( canvasId );
		if ( ! canvas ) return;
		const nodata = canvas.parentElement.querySelector( '.utmat-nodata' );
		canvas.hidden = ! hasData;
		if ( nodata ) {
			nodata.hidden = hasData;
		}
	}

	// ── Tabs ──────────────────────────────────────────────────────────────────
	function tabFromHash() {
		const h = window.location.hash.replace( '#', '' );
		return TAB_NAMES.indexOf( h ) !== -1 ? h : 'overview';
	}

	// Charts drawn while their panel was hidden start at zero size.
	function resizeCharts() {
		window.requestAnimationFrame( function () {
			Object.keys( charts ).forEach( function ( id ) {
				charts[ id ].resize();
			} );
		} );
	}

	function activateTab( name, opts ) {
		opts = opts || {};
		if ( ! document.getElementById( 'utmat-tab-overview' ) ) return;
		if ( TAB_NAMES.indexOf( name ) === -1 ) {
			name = 'overview';
		}

		TAB_NAMES.forEach( function ( tabName ) {
			const tab   = document.getElementById( 'utmat-tab-' + tabName );
			const panel = document.getElementById( 'utmat-panel-' + tabName );
			const isOn  = tabName === name;
			if ( tab ) {
				tab.classList.toggle( 'nav-tab-active', isOn );
				tab.setAttribute( 'aria-selected', isOn ? 'true' : 'false' );
				tab.tabIndex = isOn ? 0 : -1;
			}
			if ( panel ) {
				panel.hidden = ! isOn;
			}
		} );

		if ( opts.updateHash && window.location.hash !== '#' + name ) {
			window.history.replaceState( null, '', '#' + name );
		}
		if ( opts.focus ) {
			document.getElementById( 'utmat-tab-' + name ).focus();
		}
		if ( 'overview' === name ) {
			resizeCharts();
		}
		// The Journeys data loads on first activation only — click, hash
		// change and the initial tabFromHash() activation all pass here.
		if ( 'journeys' === name && ! journeysLoaded ) {
			loadJourneys();
		}
	}

	function initTabs() {
		TAB_NAMES.forEach( function ( tabName ) {
			const tab = document.getElementById( 'utmat-tab-' + tabName );
			if ( ! tab ) return;
			tab.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				activateTab( tabName, { updateHash: true } );
			} );
		} );

		const tablist = document.querySelector( '.utmat-tabs' );
		if ( tablist ) {
			tablist.addEventListener( 'keydown', function ( e ) {
				const current = TAB_NAMES.indexOf( ( e.target.id || '' ).replace( 'utmat-tab-', '' ) );
				if ( -1 === current ) return;
				const last = TAB_NAMES.length - 1;
				let next   = null;
				if ( 'ArrowRight' === e.key ) {
					next = current === last ? 0 : current + 1;
				} else if ( 'ArrowLeft' === e.key ) {
					next = 0 === current ? last : current - 1;
				} else if ( 'Home' === e.key ) {
					next = 0;
				} else if ( 'End' === e.key ) {
					next = last;
				} else if ( ' ' === e.key ) {
					e.preventDefault();
					activateTab( TAB_NAMES[ current ], { updateHash: true } );
					return;
				}
				if ( null !== next ) {
					e.preventDefault();
					activateTab( TAB_NAMES[ next ], { updateHash: true, focus: true } );
				}
			} );
		}

		// Only tab hashes switch tabs; other in-page anchors (for example the
		// wp-admin skip link to #wpbody-content) leave the current tab alone.
		// When the focused link sat in the panel that just closed, move focus
		// to the new panel so it does not fall back to the document body.
		window.addEventListener( 'hashchange', function () {
			const h = window.location.hash.replace( '#', '' );
			if ( '' === h || TAB_NAMES.indexOf( h ) !== -1 ) {
				const prev      = document.activeElement;
				const prevPanel = prev && prev.closest ? prev.closest( '.utmat-panel' ) : null;
				const name      = tabFromHash();
				activateTab( name );
				if ( prevPanel && prevPanel.hidden ) {
					document.getElementById( 'utmat-panel-' + name ).focus();
				}
			}
		} );

		activateTab( tabFromHash() );
	}

	// ── Chart factories ───────────────────────────────────────────────────────
	// Chart.js animates on its own, so it is told to stop when the user asks for
	// reduced motion; the CSS transitions already honour the same preference.
	const REDUCED_MOTION = !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );

	function makeLineChart( canvasId, labels, values, conversions, previous ) {
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
			order:           1,
		} ];

		// Overlay the previous period only when it has any clicks — a dashed
		// line plotted by day-position (not date, since the two windows cover
		// different calendar days) so the two shapes are directly comparable.
		const hasPrevious = ( previous || [] ).some( function ( v ) { return v > 0; } );
		if ( hasPrevious ) {
			datasets.push( {
				label:           i18n.previousPeriodSeries || 'Previous period',
				data:            previous,
				borderColor:     '#8c8f94',
				backgroundColor: 'transparent',
				borderWidth:     2,
				borderDash:      [ 5, 4 ],
				pointRadius:     0,
				pointHoverRadius: 4,
				fill:            false,
				tension:         0.3,
				order:           3,
			} );
		}

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
				order:           2,
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
				maintainAspectRatio: false,
				animation:           REDUCED_MOTION ? false : undefined,
				interaction:         { mode: 'index', intersect: false },
				plugins: {
					legend: { display: hasConversions || hasPrevious, position: 'bottom', labels: { boxWidth: 10 } },
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

	// Display names for the device keys derived server-side from user agents.
	const DEVICE_NAMES = {
		desktop: i18n.deviceDesktop || 'Desktop',
		mobile:  i18n.deviceMobile || 'Mobile',
		tablet:  i18n.deviceTablet || 'Tablet',
	};

	// Display names for the ad-platform keys derived server-side from click IDs.
	const PLATFORM_NAMES = {
		google:    i18n.platformGoogle || 'Google Ads',
		facebook:  i18n.platformFacebook || 'Facebook / Meta',
		microsoft: i18n.platformMicrosoft || 'Microsoft Ads',
		tiktok:    i18n.platformTiktok || 'TikTok Ads',
		linkedin:  i18n.platformLinkedin || 'LinkedIn Ads',
		twitter:   i18n.platformTwitter || 'X / Twitter Ads',
	};

	// Display labels for the channel slugs (TrafficSource::channel_labels()
	// through the i18n block); an unknown slug falls back to itself.
	const CHANNEL_NAMES = i18n.channels || {};

	// Shorten a URL for compact labels: same-host URLs show their path,
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
					backgroundColor: COLORS[ 0 ],
					maxBarThickness: 28,
					borderRadius:    2,
				} ],
			},
			options: {
				indexAxis:           'y',
				responsive:          true,
				maintainAspectRatio: false,
				animation:           REDUCED_MOTION ? false : undefined,
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

	// Orders and average order value next to the revenue chart.
	function renderRevenueSummary( rows ) {
		const elOrders = document.getElementById( 'utmat-revenue-orders' );
		const elAov    = document.getElementById( 'utmat-revenue-aov' );
		let orders  = 0;
		let revenue = 0;
		( rows || [] ).forEach( function ( r ) {
			orders  += Number( r.orders ) || 0;
			revenue += Number( r.revenue ) || 0;
		} );
		if ( elOrders ) {
			elOrders.textContent = formatNumber( orders );
		}
		if ( elAov ) {
			elAov.textContent = orders ? formatCurrency( Math.round( revenue / orders * 100 ) / 100 ) : '—';
		}
	}

	// ── Breakdown bar lists ───────────────────────────────────────────────────
	// Every row the payload returns (the server caps lists at 10), sorted by
	// count. Bars are relative to the largest row; opts.share shows each
	// value as a percentage of the list total instead of a count.
	function renderBarList( listId, rows, opts ) {
		const list = document.getElementById( listId );
		if ( ! list ) return;
		opts = opts || {};
		list.innerHTML = '';

		const items = ( rows || [] ).map( function ( d ) {
			const label = String( opts.label ? opts.label( d ) : d.label );
			return {
				label: label,
				title: String( opts.title ? opts.title( d ) : label ),
				count: Number( d.count ) || 0,
			};
		} ).sort( function ( a, b ) { return b.count - a.count; } );

		toggleNoData( listId, items.length > 0 );
		if ( ! items.length ) {
			return;
		}

		const max = Math.max.apply( null, items.map( function ( i ) { return i.count; } ) ) || 1;
		const sum = items.reduce( function ( acc, i ) { return acc + i.count; }, 0 ) || 1;

		items.forEach( function ( item ) {
			const li = document.createElement( 'li' );
			li.className = 'utmat-bars__item';

			const label = document.createElement( 'span' );
			label.className   = 'utmat-bars__label';
			label.textContent = item.label;
			label.title       = item.title;

			const value = document.createElement( 'span' );
			value.className   = 'utmat-bars__value';
			value.textContent = opts.share
				? Math.round( item.count / sum * 100 ) + '%'
				: formatNumber( item.count );

			const track = document.createElement( 'span' );
			track.className = 'utmat-bars__track';
			track.setAttribute( 'aria-hidden', 'true' );
			const fill = document.createElement( 'span' );
			fill.className   = 'utmat-bars__fill';
			fill.style.width = Math.max( 2, Math.round( item.count / max * 100 ) ) + '%';
			track.appendChild( fill );

			li.appendChild( label );
			li.appendChild( value );
			li.appendChild( track );
			list.appendChild( li );
		} );
	}

	// ── Stat tiles ────────────────────────────────────────────────────────────
	// Conversions come from WooCommerce orders and attributed form leads, so
	// the tile shows when WooCommerce is active or the period has
	// conversions anyway.
	function renderConversions( clicks, conversions, isEmpty ) {
		const tile  = document.getElementById( 'utmat-tile-conversions' );
		const count = document.getElementById( 'utmat-conv-count' );
		const meta  = document.getElementById( 'utmat-conv' );

		clicks      = Number( clicks ) || 0;
		conversions = Number( conversions ) || 0;

		if ( tile ) {
			tile.hidden = ! ( wooActive || conversions > 0 );
		}
		if ( count ) {
			count.textContent = isEmpty ? '—' : formatNumber( conversions );
		}
		if ( meta ) {
			if ( isEmpty ) {
				meta.textContent = i18n.tileConvHint || 'Appears with WooCommerce orders and form leads';
			} else {
				meta.textContent = clicks > 0
					? fmt( i18n.convRate || '%s of clicks', ( conversions / clicks * 100 ).toFixed( 1 ) + '%' )
					: '';
			}
		}
	}

	// ── Trend indicator ───────────────────────────────────────────────────────
	// Shows the change vs the same-length window immediately before the
	// selected period, under the total.
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

		el.textContent = '';
		const delta = document.createElement( 'span' );
		delta.className   = 'utmat-trend__delta';
		delta.textContent = text;
		el.appendChild( delta );
		el.appendChild( document.createTextNode( ' ' + ( i18n.vsPrevious || 'vs previous period' ) ) );
		el.className   = 'utmat-trend utmat-trend--' + cls;
		el.hidden      = false;
	}

	// Top source / top campaign tile. Picks the highest count itself rather
	// than trusting the payload order.
	function renderTopTile( key, rows, total, isEmpty ) {
		const value = document.getElementById( 'utmat-top-' + key );
		const meta  = document.getElementById( 'utmat-top-' + key + '-meta' );
		if ( ! value || ! meta ) return;

		let top = null;
		if ( ! isEmpty ) {
			( rows || [] ).forEach( function ( r ) {
				if ( ! top || ( Number( r.count ) || 0 ) > ( Number( top.count ) || 0 ) ) {
					top = r;
				}
			} );
		}

		if ( ! top ) {
			value.textContent = '—';
			value.removeAttribute( 'title' );
			meta.textContent = isEmpty
				? ( i18n.tileWaiting || 'Waiting for data' )
				: ( i18n.tileNoData || 'No tagged clicks in this period' );
			return;
		}

		const count = Number( top.count ) || 0;
		total       = Number( total ) || 0;
		const pct   = total > 0 ? Math.round( count / total * 100 ) : 0;

		value.textContent = top.label;
		value.title       = top.label;
		meta.textContent  = fmt( i18n.topShare || '%1$s clicks · %2$s of total', formatNumber( count ), pct + '%' );
	}

	// Attributed revenue tile: the sum over tagged campaigns (top 10).
	function renderRevenueTile( rows, isEmpty ) {
		const tile  = document.getElementById( 'utmat-tile-revenue' );
		const value = document.getElementById( 'utmat-revenue-total' );
		const meta  = document.getElementById( 'utmat-revenue-meta' );
		if ( tile ) {
			tile.hidden = ! wooActive;
		}
		if ( ! wooActive || ! value || ! meta ) return;

		if ( isEmpty ) {
			value.textContent = '—';
			meta.textContent  = i18n.tileWooHint || 'Appears with WooCommerce orders';
			return;
		}

		let revenue = 0;
		let orders  = 0;
		( rows || [] ).forEach( function ( r ) {
			revenue += Number( r.revenue ) || 0;
			orders  += Number( r.orders ) || 0;
		} );
		value.textContent = formatCurrency( Math.round( revenue * 100 ) / 100 );
		meta.textContent  = fmt( i18n.revenueMeta || 'From %s orders on tagged campaigns', formatNumber( orders ) );
	}

	function renderTiles( stats, isEmpty ) {
		const clicksMeta = document.getElementById( 'utmat-clicks-meta' );

		elTotal.textContent = isEmpty ? '—' : formatNumber( stats.total );
		if ( isEmpty ) {
			const trend = document.getElementById( 'utmat-trend' );
			if ( trend ) {
				trend.hidden = true;
			}
			if ( clicksMeta ) {
				clicksMeta.textContent = i18n.tileNoClicks || 'No clicks yet';
			}
		} else {
			renderTrend( stats.total, stats.previous_total );
			if ( clicksMeta ) {
				clicksMeta.textContent = '';
			}
		}

		renderConversions( stats.total, stats.conversions, isEmpty );
		renderRevenueTile( stats.revenue_by_campaign || [], isEmpty );
		renderTopTile( 'source', stats.by_source || [], stats.total, isEmpty );
		renderTopTile( 'campaign', stats.by_campaign || [], stats.total, isEmpty );
	}

	function renderCompareCaption() {
		if ( ! elCompareCaption ) return;
		elCompareCaption.textContent = ( activeSince && activeUntil )
			? ( i18n.compareRange || 'Compared with the previous period of the same length' )
			: fmt( i18n.compareDays || 'Compared with the previous %s days', activePeriod );
	}

	// First run (nothing ever recorded): onboarding and a placeholder replace
	// the filters and charts, and there is nothing to export yet.
	function renderEmptyState( isEmpty ) {
		const onboarding = document.getElementById( 'utmat-onboarding' );
		if ( onboarding ) {
			onboarding.hidden = ! isEmpty;
		}
		if ( elPlaceholder ) {
			elPlaceholder.hidden = ! isEmpty;
		}
		if ( elFilters ) {
			elFilters.hidden = isEmpty;
		}
		elCharts.hidden      = isEmpty;
		elExportBtn.disabled = isEmpty;
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
		td.colSpan = LINK_COLS;

		const box = document.createElement( 'div' );
		box.className = 'utmat-qr-box';

		const canvas = document.createElement( 'canvas' );
		canvas.className = 'utmat-qr-canvas';
		canvas.setAttribute( 'role', 'img' );
		canvas.setAttribute( 'aria-label', ( i18n.qrCode || 'QR code' ) + ': ' + link.redirect_url );
		renderQrCanvas( link.redirect_url, canvas );

		const meta = document.createElement( 'div' );
		meta.className = 'utmat-qr-meta';

		const title = document.createElement( 'strong' );
		title.className   = 'utmat-qr-title';
		title.textContent = fmt( i18n.qrFor || 'QR code for %s', link.label );

		const urlSpan = document.createElement( 'span' );
		urlSpan.className   = 'utmat-url-text';
		urlSpan.textContent = link.redirect_url;

		const actions = document.createElement( 'div' );
		actions.className = 'utmat-qr-actions';

		const download = document.createElement( 'a' );
		download.className   = 'button button-small';
		download.textContent = i18n.downloadPng || 'Download PNG';
		download.href        = canvas.toDataURL( 'image/png' );
		download.download    = 'utmat-' + link.id + '-qr.png';

		const close = document.createElement( 'button' );
		close.type        = 'button';
		close.className   = 'button button-small utmat-qr-close';
		close.textContent = i18n.close || 'Close';
		close.addEventListener( 'click', function () {
			closeQrRows();
			btn.focus();
		} );

		actions.appendChild( download );
		actions.appendChild( close );
		meta.appendChild( title );
		meta.appendChild( urlSpan );
		meta.appendChild( actions );
		box.appendChild( canvas );
		box.appendChild( meta );
		td.appendChild( box );
		tr.appendChild( td );

		row.parentNode.insertBefore( tr, row.nextSibling );
		btn.setAttribute( 'aria-expanded', 'true' );
	}

	// ── Search + filter + sort over the already-fetched links list ────────────
	// All operate client-side on the full list held in `allLinks` — no extra
	// request, since the whole list is already in memory for the table.
	function visibleLinks() {
		let list = allLinks;

		if ( linksCampaign ) {
			list = list.filter( function ( link ) { return link.utm_campaign === linksCampaign; } );
		}

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

	// Fill the toolbar campaign filter from the campaigns the links use.
	function populateLinksCampaigns() {
		if ( ! elLinksCampaign ) return;
		const campaigns = [];
		allLinks.forEach( function ( link ) {
			if ( link.utm_campaign && campaigns.indexOf( link.utm_campaign ) === -1 ) {
				campaigns.push( link.utm_campaign );
			}
		} );
		campaigns.sort();

		while ( elLinksCampaign.options.length > 1 ) {
			elLinksCampaign.remove( 1 );
		}
		campaigns.forEach( function ( c ) {
			const opt = document.createElement( 'option' );
			opt.value       = c;
			opt.textContent = c;
			elLinksCampaign.appendChild( opt );
		} );

		if ( campaigns.indexOf( linksCampaign ) === -1 ) {
			linksCampaign = '';
		}
		elLinksCampaign.value = linksCampaign;
	}

	function renderLinksCount( links ) {
		if ( ! elLinksCount ) return;
		const clicks = links.reduce( function ( acc, l ) { return acc + ( Number( l.clicks ) || 0 ); }, 0 );
		elLinksCount.textContent = fmt(
			i18n.linksCount || 'Links: %1$s · Clicks (all time): %2$s',
			formatNumber( links.length ),
			formatNumber( clicks )
		);
	}

	// ── Bulk select + delete ──────────────────────────────────────────────────
	// The "select all" checkbox only ever affects the currently visible
	// (filtered) rows — selecting "all" while a search is active should not
	// silently pick up rows the admin can't see.
	function updateBulkDeleteButton() {
		if ( elBulkDeleteBtn ) {
			elBulkDeleteBtn.hidden = selectedLinks.size === 0;
			elBulkDeleteBtn.textContent = selectedLinks.size
				? ( i18n.deleteSelected || 'Delete selected' ) + ' (' + selectedLinks.size + ')'
				: ( i18n.deleteSelected || 'Delete selected' );
		}
		if ( elSelectAll ) {
			const visible = visibleLinks();
			const allSelected = visible.length > 0 && visible.every( function ( l ) { return selectedLinks.has( l.id ); } );
			elSelectAll.checked = allSelected;
			elSelectAll.indeterminate = ! allSelected && visible.some( function ( l ) { return selectedLinks.has( l.id ); } );
		}
	}

	if ( elSelectAll ) {
		elSelectAll.addEventListener( 'change', function () {
			visibleLinks().forEach( function ( link ) {
				if ( elSelectAll.checked ) {
					selectedLinks.add( link.id );
				} else {
					selectedLinks.delete( link.id );
				}
			} );
			refreshLinksTable();
		} );
	}

	if ( elBulkDeleteBtn ) {
		elBulkDeleteBtn.addEventListener( 'click', function () {
			if ( ! elBulkDeleteBtn.dataset.confirming ) {
				elBulkDeleteBtn.dataset.confirming = '1';
				const label = elBulkDeleteBtn.textContent;
				elBulkDeleteBtn.textContent = i18n.confirmDelete || 'Confirm?';
				setTimeout( function () {
					delete elBulkDeleteBtn.dataset.confirming;
					elBulkDeleteBtn.textContent = label;
				}, 3000 );
				return;
			}

			delete elBulkDeleteBtn.dataset.confirming;
			const ids = Array.from( selectedLinks );
			elBulkDeleteBtn.disabled = true;

			Promise.all( ids.map( function ( id ) {
				return apiFetch( '/links/' + encodeURIComponent( id ), { method: 'DELETE' } )
					.then( function () { return { id: id, ok: true }; } )
					.catch( function () { return { id: id, ok: false }; } );
			} ) ).then( function ( results ) {
				results.forEach( function ( r ) {
					if ( r.ok ) {
						selectedLinks.delete( r.id );
						allLinks = allLinks.filter( function ( l ) { return l.id !== r.id; } );
					}
				} );
				elBulkDeleteBtn.disabled = false;
				refreshLinksTable();
				// The button hides once nothing is selected; keep focus on the table.
				if ( elBulkDeleteBtn.hidden ) {
					( elSelectAll && visibleLinks().length ? elSelectAll : elAddLinkBtn ).focus();
				}
			} );
		} );
	}

	// ── Populate links table ───────────────────────────────────────────────────
	// Cell order must match the thead. Label is the core "primary" column, so
	// on phones core list-table CSS collapses the row to checkbox + label and
	// the toggle-row button expands the other cells (labelled by data-colname).
	// Every short link shares the site host, so the table shows the path only;
	// the full URL stays in the title attribute and on the copy button.
	function shortLinkPath( url ) {
		try {
			const u = new URL( url );
			return u.pathname + u.search;
		} catch ( e ) {
			return shortenUrl( url );
		}
	}

	function renderLinksTable( links ) {
		show( elLinksSection );
		renderLinksCount( links );
		elLinksTbody.innerHTML = '';

		if ( elSortClicks ) {
			elSortClicks.querySelector( '.utmat-sort-arrow' ).textContent =
				clicksSortDir === 'asc' ? '▲' : ( clicksSortDir === 'desc' ? '▼' : '' );
			elSortClicks.closest( 'th' ).setAttribute(
				'aria-sort',
				clicksSortDir === 'asc' ? 'ascending' : ( clicksSortDir === 'desc' ? 'descending' : 'none' )
			);
		}

		if ( ! links.length ) {
			const row = elLinksTbody.insertRow();
			row.className = 'no-items';
			const cell = row.insertCell();
			cell.colSpan = LINK_COLS;
			cell.className = 'description';
			cell.textContent = allLinks.length
				? ( i18n.noLinksMatch || 'No tracked links match your search.' )
				: ( i18n.noLinks || 'No tracked links found.' );
			updateBulkDeleteButton();
			return;
		}

		links.forEach( function ( link ) {
			const row = elLinksTbody.insertRow();
			row.dataset.linkId = link.id;

			// Bulk-select checkbox.
			const cbCell = row.insertCell();
			cbCell.className = 'check-column';
			const cb = document.createElement( 'input' );
			cb.type    = 'checkbox';
			cb.className = 'utmat-row-select';
			cb.checked = selectedLinks.has( link.id );
			cb.setAttribute( 'aria-label', fmt( i18n.selectLink || 'Select %s', link.label ) );
			cb.addEventListener( 'change', function () {
				if ( cb.checked ) {
					selectedLinks.add( link.id );
				} else {
					selectedLinks.delete( link.id );
				}
				updateBulkDeleteButton();
			} );
			cbCell.appendChild( cb );

			// Label + row actions.
			const labelCell = row.insertCell();
			labelCell.className = 'utmat-col-label column-primary';
			const strong = document.createElement( 'strong' );
			strong.className   = 'utmat-link-label';
			strong.textContent = link.label;
			strong.title       = link.label;
			labelCell.appendChild( strong );

			const actions = document.createElement( 'div' );
			actions.className = 'row-actions';

			const edit = makeRowAction( 'edit', 'utmat-edit-btn', i18n.edit || 'Edit', null, false );
			edit.button.setAttribute( 'aria-label', fmt( i18n.editLink || 'Edit %s', link.label ) );
			edit.button.addEventListener( 'click', function () {
				enterEditMode( link, edit.button );
			} );

			const dup = makeRowAction( 'duplicate', 'utmat-duplicate-btn', i18n.duplicate || 'Duplicate', null, false );
			dup.button.setAttribute( 'aria-label', fmt( i18n.duplicateLink || 'Duplicate %s', link.label ) );
			dup.button.title = i18n.duplicateHint || 'Pre-fill the add-link form with this link’s values to create a variant.';
			dup.button.addEventListener( 'click', function () {
				enterDuplicateMode( link, dup.button );
			} );

			const qr = makeRowAction( 'qr', 'utmat-qr-btn', i18n.qrCode || 'QR code', null, false );
			qr.button.setAttribute( 'aria-expanded', 'false' );
			qr.button.setAttribute( 'aria-label', fmt( i18n.qrFor || 'QR code for %s', link.label ) );
			qr.button.addEventListener( 'click', function () {
				toggleQrRow( link, row, qr.button );
			} );

			const copy = makeRowAction( 'copy', 'utmat-copy-link-btn', i18n.copyLink || 'Copy link', null, false );
			copy.button.setAttribute( 'aria-label', fmt( i18n.copyLinkFor || 'Copy short link for %s', link.label ) );
			copy.button.addEventListener( 'click', function () {
				copyWithTextFeedback( copy.button, link.redirect_url );
			} );

			const del = makeRowAction( 'delete', 'button-link-delete utmat-delete-btn', i18n.delete || 'Delete', null, true );
			const deleteBtn = del.button;
			deleteBtn.setAttribute( 'aria-label', fmt( i18n.deleteLink || 'Delete %s', link.label ) );
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

			[ edit, dup, qr, copy, del ].forEach( function ( a ) {
				actions.appendChild( a.wrap );
			} );
			labelCell.appendChild( actions );

			const toggle = document.createElement( 'button' );
			toggle.type      = 'button';
			toggle.className = 'toggle-row';
			const toggleText = document.createElement( 'span' );
			toggleText.className   = 'screen-reader-text';
			toggleText.textContent = i18n.showDetails || 'Show more details';
			toggle.appendChild( toggleText );
			labelCell.appendChild( toggle );

			// Campaign.
			const campaignCell = row.insertCell();
			campaignCell.className = 'utmat-col-campaign';
			campaignCell.dataset.colname = LINK_COL_NAMES[ 1 ] || '';
			if ( link.utm_campaign ) {
				const chip = document.createElement( 'span' );
				chip.className   = 'utmat-chip';
				chip.textContent = link.utm_campaign;
				chip.title       = link.utm_campaign;
				campaignCell.appendChild( chip );
			} else {
				campaignCell.textContent = '—';
			}

			// Source / medium.
			const sourceCell = row.insertCell();
			sourceCell.className = 'utmat-col-source';
			sourceCell.dataset.colname = LINK_COL_NAMES[ 2 ] || '';
			sourceCell.textContent = ( link.utm_source || link.utm_medium )
				? ( link.utm_source || '—' ) + ' / ' + ( link.utm_medium || '—' )
				: '—';
			sourceCell.title = sourceCell.textContent;

			// Clicks.
			const clicksCell  = row.insertCell();
			clicksCell.className = 'utmat-col-clicks';
			clicksCell.dataset.colname = LINK_COL_NAMES[ 3 ] || '';
			const clicksBadge = document.createElement( 'span' );
			clicksBadge.className   = 'utmat-clicks-badge';
			clicksBadge.textContent = formatNumber( link.clicks );
			clicksCell.appendChild( clicksBadge );

			// Short link + copy icon.
			const redirectCell = row.insertCell();
			redirectCell.className = 'utmat-col-url';
			redirectCell.dataset.colname = LINK_COL_NAMES[ 4 ] || '';
			const redirectWrap = document.createElement( 'div' );
			redirectWrap.className = 'utmat-url-cell';
			const redirectText = document.createElement( 'span' );
			redirectText.className   = 'utmat-url-text';
			redirectText.textContent = shortLinkPath( link.redirect_url );
			redirectText.title       = link.redirect_url;
			redirectWrap.appendChild( redirectText );
			redirectWrap.appendChild( makeCopyIconButton( link.redirect_url, i18n.copyShortLink || 'Copy short link' ) );
			redirectCell.appendChild( redirectWrap );

			// Destination + copy icon.
			const destCell = row.insertCell();
			destCell.className = 'utmat-col-url';
			destCell.dataset.colname = LINK_COL_NAMES[ 5 ] || '';
			const destWrap = document.createElement( 'div' );
			destWrap.className = 'utmat-url-cell';
			const destText = document.createElement( 'span' );
			destText.className   = 'utmat-url-text';
			destText.textContent = String( link.destination || '' ).replace( /^https?:\/\//, '' );
			destText.title       = link.destination;
			destWrap.appendChild( destText );
			destWrap.appendChild( makeCopyIconButton( link.destination, i18n.copyDestination || 'Copy destination URL' ) );
			destCell.appendChild( destWrap );
		} );

		updateBulkDeleteButton();
	}

	// ── Delete a link ─────────────────────────────────────────────────────────
	// The checkbox of the nearest remaining link row (skipping QR rows), or
	// the Add tracked link button when no other link row is left.
	function focusTargetAfterRemoving( row ) {
		let sib = row.nextElementSibling;
		while ( sib && ! sib.dataset.linkId ) {
			sib = sib.nextElementSibling;
		}
		if ( ! sib ) {
			sib = row.previousElementSibling;
			while ( sib && ! sib.dataset.linkId ) {
				sib = sib.previousElementSibling;
			}
		}
		return ( sib && sib.querySelector( '.utmat-row-select' ) ) || elAddLinkBtn;
	}

	function deleteLink( id, row ) {
		apiFetch( '/links/' + encodeURIComponent( id ), { method: 'DELETE' } )
			.then( function () {
				allLinks = allLinks.filter( function ( link ) { return link.id !== id; } );
				selectedLinks.delete( id );
				const focusTarget = row.contains( document.activeElement ) ? focusTargetAfterRemoving( row ) : null;
				const next = row.nextElementSibling;
				if ( next && next.classList.contains( 'utmat-qr-row' ) ) {
					next.remove();
				}
				row.remove();
				if ( ! elLinksTbody.rows.length ) {
					refreshLinksTable();
				} else {
					renderLinksCount( visibleLinks() );
				}
				if ( focusTarget ) {
					focusTarget.focus();
				}
				flashLinksMsg( i18n.deleted || 'Link deleted.' );
			} )
			.catch( function ( err ) {
				window.alert( err.message || 'Unknown error.' ); // eslint-disable-line no-alert
			} );
	}

	// ── Journeys ───────────────────────────────────────────────────────────────
	// One row per conversion carrying a visitor id; "Show touches" expands a
	// details row listing the visitor's preceding events plus the conversion
	// itself as the terminal step. Errors go to the Journeys message slot —
	// never showError(), which hides the Links section as a side effect.

	// clicked_at is stored as UTC "Y-m-d H:i:s"; the space-separated form is
	// not an ECMAScript date string (WebKit rejects it), so swap in the T and
	// mark the string UTC before handing it to the Date constructor.
	function formatEventTime( s ) {
		return new Date( String( s ).replace( ' ', 'T' ) + 'Z' ).toLocaleString();
	}

	const KIND_LABELS = {
		order: i18n.kindOrder || 'Order',
		lead:  i18n.kindLead || 'Lead',
		click: i18n.kindClick || 'Click',
		visit: i18n.kindVisit || 'Visit',
	};

	// Vendor product names (untranslated), shared with the wp-admin
	// Dashboard widget; unknown slugs render verbatim.
	const INTEGRATION_LABELS = i18n.integrationLabels || {};

	function makeKindChip( kind ) {
		const chip = document.createElement( 'span' );
		chip.className   = 'utmat-chip utmat-kind-chip';
		chip.textContent = KIND_LABELS[ kind ] || kind;
		return chip;
	}

	// "Order #123 · $45.00" / "Order · $45.00" / "Lead · Contact · WPForms".
	function journeyLabel( event ) {
		const meta = event.meta || {};

		if ( 'lead' === event.kind ) {
			return fmt(
				i18n.journeyLead || 'Lead · %1$s · %2$s',
				meta.form_title || '—',
				INTEGRATION_LABELS[ meta.integration ] || meta.integration || '—'
			);
		}
		const total = ( null === event.order_total || undefined === event.order_total )
			? '—'
			: formatCurrency( event.order_total );
		return meta.order_number
			? fmt( i18n.journeyOrder || 'Order #%1$s · %2$s', meta.order_number, total )
			: fmt( i18n.journeyOrderNoNumber || 'Order · %s', total );
	}

	// A first/last-touch cell: "source / medium" plus a campaign chip, or —.
	function renderTouchCell( cell, event ) {
		if ( ! event ) {
			cell.textContent = '—';
			return;
		}
		const line = document.createElement( 'span' );
		line.textContent = ( event.utm_source || event.utm_medium )
			? ( event.utm_source || '—' ) + ' / ' + ( event.utm_medium || '—' )
			: '—';
		line.title = line.textContent;
		cell.appendChild( line );
		if ( event.utm_campaign ) {
			cell.appendChild( document.createTextNode( ' ' ) );
			const chip = document.createElement( 'span' );
			chip.className   = 'utmat-chip';
			chip.textContent = event.utm_campaign;
			chip.title       = event.utm_campaign;
			cell.appendChild( chip );
		}
	}

	// One touch <li>: time, kind chip, channel, campaign detail (or the
	// click ID), landing page path and custom parameters as key=value pairs.
	function renderJourneyTouchItem( event ) {
		const li = document.createElement( 'li' );

		const time = document.createElement( 'span' );
		time.className   = 'utmat-journey-time';
		time.textContent = formatEventTime( event.clicked_at );
		li.appendChild( time );
		li.appendChild( document.createTextNode( ' ' ) );
		li.appendChild( makeKindChip( event.kind ) );

		if ( event.channel ) {
			li.appendChild( document.createTextNode( ' ' + ( CHANNEL_NAMES[ event.channel ] || event.channel ) ) );
		}

		let detail = '';
		if ( event.utm_source || event.utm_medium ) {
			detail = ( event.utm_source || '—' ) + ' / ' + ( event.utm_medium || '—' );
		}
		if ( event.utm_campaign ) {
			detail += ( detail ? ' · ' : '' ) + event.utm_campaign;
		}
		if ( ! detail ) {
			[ 'gclid', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id', 'twclid' ].some( function ( key ) {
				if ( event[ key ] ) {
					detail = key + '=' + event[ key ];
					return true;
				}
				return false;
			} );
		}
		if ( detail ) {
			li.appendChild( document.createTextNode( ' ' + detail ) );
		}

		if ( event.destination ) {
			const path = document.createElement( 'span' );
			path.className   = 'utmat-journey-path';
			path.textContent = shortLinkPath( event.destination );
			path.title       = event.destination;
			li.appendChild( document.createTextNode( ' ' ) );
			li.appendChild( path );
		}

		const custom = event.custom || {};
		Object.keys( custom ).forEach( function ( key ) {
			const pair = document.createElement( 'span' );
			pair.className   = 'utmat-journey-custom';
			pair.textContent = key + '=' + custom[ key ];
			li.appendChild( document.createTextNode( ' ' ) );
			li.appendChild( pair );
		} );

		return li;
	}

	// The hidden expander row: the touches (ascending) with the conversion
	// itself listed last as the terminal step. Created lazily on first
	// expansion and kept in the DOM, toggled via its hidden attribute.
	function renderJourneyTouches( journey ) {
		const tr = document.createElement( 'tr' );
		tr.className = 'utmat-journey-details';
		tr.id        = 'utmat-journey-' + journey.conversion.id;
		tr.hidden    = true;

		const td = document.createElement( 'td' );
		td.colSpan = JOURNEY_COLS;

		const list = document.createElement( 'ol' );
		list.className = 'utmat-journey-touches';

		if ( journey.touches_truncated ) {
			const note = document.createElement( 'li' );
			note.className   = 'utmat-journey-truncated';
			note.textContent = i18n.touchesTruncated || 'Showing the most recent 100 touches.';
			list.appendChild( note );
		}

		( journey.touches || [] ).forEach( function ( event ) {
			list.appendChild( renderJourneyTouchItem( event ) );
		} );
		list.appendChild( renderJourneyTouchItem( journey.conversion ) );

		td.appendChild( list );
		tr.appendChild( td );
		return tr;
	}

	function toggleJourneyDetails( journey, row, btn ) {
		let details = document.getElementById( 'utmat-journey-' + journey.conversion.id );
		if ( ! details ) {
			details = renderJourneyTouches( journey );
			row.parentNode.insertBefore( details, row.nextSibling );
		}
		const open = details.hidden;
		details.hidden         = ! open;
		btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		btn.textContent        = open ? ( i18n.hideTouches || 'Hide touches' ) : ( i18n.showTouches || 'Show touches' );
	}

	function renderJourneysTable( journeys ) {
		if ( ! elJourneysTbody || ! elJourneysTable ) {
			return;
		}
		elJourneysTbody.innerHTML = '';

		if ( ! journeys.length ) {
			show( elJourneysEmpty );
			hide( elJourneysTable );
			return;
		}
		hide( elJourneysEmpty );
		show( elJourneysTable );

		journeys.forEach( function ( journey ) {
			const row = elJourneysTbody.insertRow();
			row.dataset.journeyId = String( journey.conversion.id );

			// When (UTC-stored timestamp, rendered in the admin's locale).
			// No data-colname: the cell sits before the primary column, where
			// core's mobile layout gives the label no gutter and it would
			// overprint the date.
			const whenCell = row.insertCell();
			whenCell.className = 'utmat-col-when';
			whenCell.textContent = formatEventTime( journey.conversion.clicked_at );
			whenCell.title = journey.conversion.clicked_at;

			// Conversion: kind chip + label; core's toggle-row button makes
			// the mobile list-table collapse work on the primary column.
			const convCell = row.insertCell();
			convCell.className = 'utmat-col-conversion column-primary';
			convCell.appendChild( makeKindChip( journey.conversion.kind ) );
			convCell.appendChild( document.createTextNode( ' ' ) );
			const label = document.createElement( 'span' );
			label.className   = 'utmat-journey-label';
			label.textContent = journeyLabel( journey.conversion );
			label.title       = label.textContent;
			convCell.appendChild( label );

			const toggle = document.createElement( 'button' );
			toggle.type      = 'button';
			toggle.className = 'toggle-row';
			const toggleText = document.createElement( 'span' );
			toggleText.className   = 'screen-reader-text';
			toggleText.textContent = i18n.showDetails || 'Show more details';
			toggle.appendChild( toggleText );
			convCell.appendChild( toggle );

			// First / last touch.
			const firstCell = row.insertCell();
			firstCell.dataset.colname = JOURNEY_COL_NAMES[ 2 ] || '';
			renderTouchCell( firstCell, journey.first_touch );

			const lastCell = row.insertCell();
			lastCell.dataset.colname = JOURNEY_COL_NAMES[ 3 ] || '';
			renderTouchCell( lastCell, journey.last_touch );

			// Touches: count badge + the expander button.
			const touchesCell = row.insertCell();
			touchesCell.className = 'utmat-col-touches';
			touchesCell.dataset.colname = JOURNEY_COL_NAMES[ 4 ] || '';
			const badge = document.createElement( 'span' );
			badge.className   = 'utmat-clicks-badge';
			badge.textContent = formatNumber( journey.touch_count );
			touchesCell.appendChild( badge );

			const expand = document.createElement( 'button' );
			expand.type      = 'button';
			expand.className = 'button-link utmat-journey-toggle';
			expand.setAttribute( 'aria-expanded', 'false' );
			expand.setAttribute( 'aria-controls', 'utmat-journey-' + journey.conversion.id );
			expand.textContent = i18n.showTouches || 'Show touches';
			expand.addEventListener( 'click', function () {
				toggleJourneyDetails( journey, row, expand );
			} );
			touchesCell.appendChild( document.createTextNode( ' ' ) );
			touchesCell.appendChild( expand );
		} );
	}

	/**
	 * Fetch and render the Journeys tab (last 50 conversions within 365
	 * days — no period control on this tab by design).
	 *
	 * @since 1.0.0
	 * @return {void}
	 */
	function loadJourneys() {
		journeysLoaded = true;
		show( elJourneysLoading );

		apiFetch( '/journeys?per_page=50&period=365' ).then( function ( body ) {
			hide( elJourneysLoading );
			renderJourneysTable( body.journeys || [] );

			if ( elJourneysWithout ) {
				const without = Number( body.without_visitor ) || 0;
				elJourneysWithout.textContent = fmt(
					i18n.journeysWithoutVisitor || 'Conversions without a visitor id (not shown): %s',
					formatNumber( without )
				);
				elJourneysWithout.hidden = ! without;
			}
		} ).catch( function ( err ) {
			hide( elJourneysLoading );
			if ( elJourneysMsg ) {
				elJourneysMsg.textContent = err.message || ( i18n.journeysFailed || 'Could not load journeys.' );
				elJourneysMsg.className   = 'utmat-add-form__msg is-error';
			}
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

	// ── Saved UTM presets ──────────────────────────────────────────────────────
	// A preset is a reusable source/medium/term/content combination applied
	// to the add-link form on demand — distinct from the passive datalist
	// suggestions above, which only ever fill empty fields; selecting a
	// preset is an explicit action and overwrites the current values.
	const elPresetSelect     = document.getElementById( 'utmat-preset-select' );
	const elPresetDelete     = document.getElementById( 'utmat-preset-delete' );
	const elPresetSave       = document.getElementById( 'utmat-preset-save' );
	const elPresetNameRow    = document.getElementById( 'utmat-preset-name-row' );
	const elPresetName       = document.getElementById( 'utmat-preset-name' );
	const elPresetNameOk     = document.getElementById( 'utmat-preset-name-confirm' );
	const elPresetNameCancel = document.getElementById( 'utmat-preset-name-cancel' );

	// The drawer is reused for add, edit and duplicate, so an open preset-name
	// row or a Delete preset button must not carry over between openings.
	function resetPresetUi() {
		if ( elPresetSelect ) {
			elPresetSelect.value = '';
		}
		if ( elPresetDelete ) {
			elPresetDelete.hidden = true;
		}
		if ( elPresetNameRow ) {
			hide( elPresetNameRow );
		}
	}

	function populatePresets( presets ) {
		allPresets = presets || [];
		if ( ! elPresetSelect ) {
			return;
		}

		const current = elPresetSelect.value;
		while ( elPresetSelect.options.length > 1 ) {
			elPresetSelect.remove( 1 );
		}
		allPresets.forEach( function ( preset ) {
			const opt = document.createElement( 'option' );
			opt.value = preset.id;
			opt.textContent = preset.name;
			elPresetSelect.appendChild( opt );
		} );
		elPresetSelect.value = allPresets.some( function ( p ) { return p.id === current; } ) ? current : '';
		if ( elPresetDelete ) {
			elPresetDelete.hidden = ! elPresetSelect.value;
		}
	}

	if ( elPresetSelect ) {
		elPresetSelect.addEventListener( 'change', function () {
			const preset = allPresets.find( function ( p ) { return p.id === elPresetSelect.value; } );
			if ( elPresetDelete ) {
				elPresetDelete.hidden = ! preset;
			}
			if ( ! preset ) {
				return;
			}
			elAddForm.elements.utm_source.value  = preset.utm_source;
			elAddForm.elements.utm_medium.value  = preset.utm_medium;
			elAddForm.elements.utm_term.value    = preset.utm_term;
			elAddForm.elements.utm_content.value = preset.utm_content;
			const advanced = elAddForm.querySelector( '.utmat-add-form__advanced' );
			if ( advanced && ( preset.utm_term || preset.utm_content ) ) {
				advanced.open = true;
			}
		} );
	}

	if ( elPresetSave ) {
		elPresetSave.addEventListener( 'click', function () {
			show( elPresetNameRow );
			elPresetName.value = '';
			elPresetName.focus();
		} );
	}

	if ( elPresetNameCancel ) {
		elPresetNameCancel.addEventListener( 'click', function () {
			hide( elPresetNameRow );
		} );
	}

	if ( elPresetNameOk ) {
		elPresetNameOk.addEventListener( 'click', function () {
			const name = elPresetName.value.trim();
			if ( ! name ) {
				window.alert( i18n.presetNameRequired || 'Please enter a preset name.' ); // eslint-disable-line no-alert
				return;
			}

			apiFetch( '/presets', {
				method:  'POST',
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				body:    JSON.stringify( {
					name:        name,
					utm_source:  elAddForm.elements.utm_source.value,
					utm_medium:  elAddForm.elements.utm_medium.value,
					utm_term:    elAddForm.elements.utm_term.value,
					utm_content: elAddForm.elements.utm_content.value,
				} ),
			} ).then( function ( saved ) {
				const without = allPresets.filter( function ( p ) { return p.id !== saved.id; } );
				populatePresets( without.concat( [ saved ] ) );
				elPresetSelect.value = saved.id;
				elPresetDelete.hidden = false;
				hide( elPresetNameRow );
				elAddMsg.textContent = i18n.presetSaved || 'Preset saved.';
				elAddMsg.className   = 'utmat-add-form__msg is-success';
			} ).catch( function ( err ) {
				window.alert( err.message || 'Unknown error.' ); // eslint-disable-line no-alert
			} );
		} );
	}

	if ( elPresetDelete ) {
		elPresetDelete.addEventListener( 'click', function () {
			const id = elPresetSelect.value;
			if ( ! id ) {
				return;
			}
			if ( ! elPresetDelete.dataset.confirming ) {
				elPresetDelete.dataset.confirming = '1';
				const label = elPresetDelete.textContent;
				elPresetDelete.textContent = i18n.confirmDeletePreset || 'Delete this preset?';
				setTimeout( function () {
					delete elPresetDelete.dataset.confirming;
					elPresetDelete.textContent = label;
				}, 3000 );
				return;
			}
			delete elPresetDelete.dataset.confirming;

			apiFetch( '/presets/' + encodeURIComponent( id ), { method: 'DELETE' } ).then( function () {
				populatePresets( allPresets.filter( function ( p ) { return p.id !== id; } ) );
				elPresetSelect.value = '';
				elPresetDelete.hidden = true;
			} ).catch( function ( err ) {
				window.alert( err.message || 'Unknown error.' ); // eslint-disable-line no-alert
			} );
		} );
	}

	// ── Add / edit drawer ─────────────────────────────────────────────────────
	// A modal side panel holding the add/edit form. Every close path (Cancel,
	// the X button, Esc, a backdrop click) goes through the Cancel handler,
	// so closing always discards the unsaved input.
	function openDrawer( mode, trigger ) {
		drawerReturnFocus = trigger || document.activeElement;
		if ( elDrawerTitle ) {
			elDrawerTitle.textContent = 'edit' === mode
				? ( i18n.drawerEditTitle || 'Edit tracked link' )
				: ( i18n.drawerAddTitle || 'Add tracked link' );
		}
		if ( elLinksMsg ) {
			elLinksMsg.textContent = '';
			elLinksMsg.className   = 'utmat-add-form__msg';
		}
		activateTab( 'links', { updateHash: true } );
		if ( elDrawerBackdrop ) {
			show( elDrawerBackdrop );
		}
		show( elDrawer );
		document.body.classList.add( 'utmat-drawer-open' );
	}

	function closeDrawer() {
		if ( ! elDrawer || elDrawer.hidden ) return;
		hide( elDrawer );
		if ( elDrawerBackdrop ) {
			hide( elDrawerBackdrop );
		}
		document.body.classList.remove( 'utmat-drawer-open' );

		// The trigger may be gone (rows are rebuilt after a save) or sit in a
		// hidden panel (the empty-state button); fall back to the header button.
		let target = drawerReturnFocus;
		if ( ! target || ! document.body.contains( target ) || null === target.offsetParent ) {
			target = elAddLinkBtn;
		}
		drawerReturnFocus = null;
		if ( target ) {
			target.focus();
		}
	}

	function trapDrawerFocus( e ) {
		if ( 'Tab' !== e.key ) return;
		const items = Array.prototype.filter.call( elDrawer.querySelectorAll( FOCUSABLE ), function ( el ) {
			return null !== el.offsetParent;
		} );
		if ( ! items.length ) return;
		const first = items[ 0 ];
		const last  = items[ items.length - 1 ];
		if ( e.shiftKey && ( document.activeElement === first || document.activeElement === elDrawer ) ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && document.activeElement === last ) {
			e.preventDefault();
			first.focus();
		}
	}

	// Short link and shortcode exist only once the link is saved.
	function updateDrawerMeta( link ) {
		if ( elDrawerShortlink ) {
			elDrawerShortlink.classList.toggle( 'utmat-readonly--pending', ! link );
			elDrawerShortlink.textContent = link
				? link.redirect_url
				: ( i18n.shortlinkPending || 'Created from the label when you save.' );
		}
		if ( elDrawerShortcode ) {
			elDrawerShortcode.textContent = link
				? fmt( i18n.shortcodeFor || 'Shortcode: [utmat_link id="%s"]', link.id )
				: ( i18n.shortcodeAfterSave || 'After saving, embed it with the [utmat_link] shortcode.' );
		}
	}

	// ── Drawer custom-parameter inputs ────────────────────────────────────────
	// One text input per configured custom parameter (utmatData.customParams),
	// rendered into the drawer. The inputs carry NO name attribute: the submit
	// handler builds its payload via FormData.forEach, and a named
	// custom[coupon] input would arrive as a flat string key — instead the
	// handler adds payload.custom = readCustomInputs() explicitly. The raw
	// parameter name is the label (inside <code>), never translated.
	function renderCustomInputs( values ) {
		if ( ! elDrawerCustom || ! elDrawerCustomGrid ) {
			return;
		}
		const names  = utmatData.customParams || [];
		const filled = values || {};
		elDrawerCustomGrid.innerHTML = '';
		elDrawerCustom.hidden = ! names.length;
		names.forEach( function ( name ) {
			const field = document.createElement( 'div' );
			field.className = 'utmat-drawer__field';

			const label = document.createElement( 'label' );
			label.setAttribute( 'for', 'utmat-custom-' + name );
			const code = document.createElement( 'code' );
			code.textContent = name;
			label.appendChild( code );

			const input = document.createElement( 'input' );
			input.type = 'text';
			input.id   = 'utmat-custom-' + name;
			input.setAttribute( 'data-utmat-custom', name );
			if ( filled[ name ] ) {
				input.value = filled[ name ];
			}

			field.appendChild( label );
			field.appendChild( input );
			elDrawerCustomGrid.appendChild( field );
		} );
	}

	// Non-empty custom input values, keyed by parameter name. Values are
	// trimmed but NOT normalized (case-sensitive coupon/affiliate codes).
	function readCustomInputs() {
		const values = {};
		if ( ! elDrawerCustom ) {
			return values;
		}
		elDrawerCustom.querySelectorAll( '[data-utmat-custom]' ).forEach( function ( input ) {
			const value = input.value.trim();
			if ( value ) {
				values[ input.getAttribute( 'data-utmat-custom' ) ] = value;
			}
		} );
		return values;
	}

	// ── "Build a URL only" mode ───────────────────────────────────────────────
	// Turns the drawer into a UTM builder: the link-specific fields hide, the
	// live preview becomes the output, and the submit button becomes the copy
	// action. No short link is created and no request is made on submit.
	function setBuilderMode( on ) {
		if ( elLabelField ) {
			elLabelField.hidden = on;
		}
		if ( elShortlinkLabel ) {
			elShortlinkLabel.hidden = on;
		}
		if ( elDrawerShortlink ) {
			elDrawerShortlink.hidden = on;
		}
		if ( elDrawerShortcode ) {
			elDrawerShortcode.hidden = on;
		}
		if ( elPreviewLabel ) {
			elPreviewLabel.hidden = on;
		}
		if ( elPreviewLabelBuilder ) {
			elPreviewLabelBuilder.hidden = ! on;
		}
		// The Label input stays required in link mode; hiding it alone would
		// leave the browser blocking submit on an invisible control.
		elAddForm.elements.label.required = ! on;
		if ( elDrawerTitle ) {
			elDrawerTitle.textContent = on
				? ( i18n.drawerBuildTitle || 'Build a tagged URL' )
				: ( i18n.drawerAddTitle || 'Add tracked link' );
		}
		elSubmitBtn.textContent = on
			? ( i18n.copyTaggedUrl || 'Copy tagged URL' )
			: ( i18n.addLink || 'Add link' );
		if ( elDrawer ) {
			elDrawer.classList.toggle( 'is-builder', on );
		}
		if ( on ) {
			elAddForm.elements.destination.focus();
		} else {
			elAddForm.elements.label.focus();
		}
	}

	if ( elBuilderToggle ) {
		elBuilderToggle.addEventListener( 'change', function () {
			setBuilderMode( elBuilderToggle.checked );
		} );
	}

	// Client-side mirror of LinkManager::build_url(): same parameter order,
	// empty values skipped, values normalized like the server does, an
	// existing query string and fragment respected. Custom parameter values
	// are appended after the UTM keys, trimmed but not normalized, mirroring
	// the server. Preview only.
	function buildDestinationPreview() {
		const base = elAddForm.elements.destination.value.trim();
		if ( ! base ) {
			return '';
		}
		const params = new URLSearchParams();
		UTM_FIELDS.forEach( function ( key ) {
			const input = elAddForm.elements[ key ];
			const value = normalizeUtm( ( input && input.value ) || '' );
			if ( value ) {
				params.append( key, value );
			}
		} );
		( utmatData.customParams || [] ).forEach( function ( name ) {
			const input = elDrawerCustom
				? elDrawerCustom.querySelector( '[data-utmat-custom="' + name + '"]' )
				: null;
			const value = input ? input.value.trim() : '';
			if ( value ) {
				params.append( name, value );
			}
		} );
		const qs = params.toString();
		if ( ! qs ) {
			return base;
		}
		let head   = base;
		let frag   = '';
		const hash = base.indexOf( '#' );
		if ( -1 !== hash ) {
			frag = base.slice( hash );
			head = base.slice( 0, hash );
		}
		return head + ( -1 !== head.indexOf( '?' ) ? '&' : '?' ) + qs + frag;
	}

	function updateDrawerPreview() {
		if ( ! elDrawerPreview ) return;
		elDrawerPreview.textContent = buildDestinationPreview() ||
			( i18n.previewEmpty || 'Enter a destination URL to see the final link.' );
	}

	function openAddDrawer( trigger ) {
		editingId = null;
		elAddForm.reset();
		renderCustomInputs();
		if ( elBuilderRow ) {
			show( elBuilderRow );
		}
		// Runs after reset() — the reset unchecks the toggle but does not
		// re-sync the hidden flags and labels this manages.
		setBuilderMode( false );
		const advanced = elAddForm.querySelector( '.utmat-add-form__advanced' );
		if ( advanced ) {
			advanced.open = false;
		}
		elSubmitBtn.textContent = i18n.addLink || 'Add link';
		elAddMsg.textContent = '';
		elAddMsg.className   = 'utmat-add-form__msg';
		hide( elNormNotice );
		resetPresetUi();
		openDrawer( 'add', trigger );
		updateDrawerMeta( null );
		updateDrawerPreview();
		elAddForm.elements.label.focus();
	}

	// Editing reuses the add form: fields are pre-filled from the link, the
	// submit button becomes "Save changes", and the slug is locked (it is not
	// a form field — the PUT request targets the stored slug directly).
	function enterEditMode( link, trigger ) {
		// Builder mode never applies to an existing link; the row hides and
		// the mode resets before openDrawer() so the edit title survives.
		if ( elBuilderRow ) {
			hide( elBuilderRow );
		}
		setBuilderMode( false );
		renderCustomInputs( link.custom || {} );
		openDrawer( 'edit', trigger );
		hide( elNormNotice );
		resetPresetUi();
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
		elAddMsg.textContent = '';
		elAddMsg.className = 'utmat-add-form__msg';
		updateDrawerMeta( link );
		updateDrawerPreview();
		elAddForm.elements.label.focus();
	}

	/**
	 * Pre-fill the add-link form from an existing link to create a variant.
	 *
	 * Unlike enterEditMode(), editingId stays null — submitting the form
	 * always POSTs a new link (a fresh slug derived from the label). The
	 * label gets a " (copy)" suffix so the create-time slug never collides
	 * with the original.
	 *
	 * @since 1.0.0
	 *
	 * @param {Object}      link    Link object from the /links payload.
	 * @param {HTMLElement} trigger Element that opened the drawer.
	 * @return {void}
	 */
	function enterDuplicateMode( link, trigger ) {
		setBuilderMode( false );
		if ( elBuilderRow ) {
			show( elBuilderRow );
		}
		renderCustomInputs( link.custom || {} );
		openDrawer( 'add', trigger );
		hide( elNormNotice );
		resetPresetUi();
		editingId = null;

		elAddForm.elements.label.value        = ( link.label || '' ) + ' ' + ( i18n.copySuffix || '(copy)' );
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

		elSubmitBtn.textContent = i18n.addLink || 'Add link';
		elAddMsg.textContent = '';
		elAddMsg.className = 'utmat-add-form__msg';
		updateDrawerMeta( null );
		updateDrawerPreview();
		elAddForm.elements.label.focus();
		elAddForm.elements.label.select();
	}

	function exitEditMode() {
		editingId = null;
		elAddForm.reset();
		setBuilderMode( false );
		elSubmitBtn.textContent = i18n.addLink || 'Add link';
		hide( elNormNotice );
		closeDrawer();
	}

	elCancelBtn.addEventListener( 'click', function () {
		exitEditMode();
		elAddMsg.textContent = '';
		elAddMsg.className = 'utmat-add-form__msg';
	} );

	if ( elDrawerClose ) {
		elDrawerClose.addEventListener( 'click', function () {
			elCancelBtn.click();
		} );
	}

	if ( elDrawerBackdrop ) {
		elDrawerBackdrop.addEventListener( 'click', function () {
			elCancelBtn.click();
		} );
	}

	if ( elDrawer ) {
		elDrawer.addEventListener( 'keydown', trapDrawerFocus );
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && ! elDrawer.hidden ) {
				e.preventDefault();
				elCancelBtn.click();
			}
		} );
	}

	elAddForm.addEventListener( 'input', updateDrawerPreview );
	elAddForm.addEventListener( 'change', updateDrawerPreview );

	if ( elAddLinkBtn ) {
		elAddLinkBtn.addEventListener( 'click', function () {
			openAddDrawer( elAddLinkBtn );
		} );
	}

	const elOnboardingAdd = document.getElementById( 'utmat-onboarding-add' );
	if ( elOnboardingAdd ) {
		elOnboardingAdd.addEventListener( 'click', function () {
			openAddDrawer( elOnboardingAdd );
		} );
	}

	const elOnboardingCopy = document.getElementById( 'utmat-onboarding-copy' );
	const elOnboardingUrl  = document.getElementById( 'utmat-onboarding-url' );
	if ( elOnboardingCopy && elOnboardingUrl ) {
		elOnboardingCopy.addEventListener( 'click', function () {
			copyWithTextFeedback( elOnboardingCopy, elOnboardingUrl.textContent.trim() );
		} );
	}

	elAddForm.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		if ( normalizeAllFields() ) {
			show( elNormNotice );
		}

		// Builder mode: the submit button is the copy action. The tagged URL
		// is built from the current fields (naming enforcement above applied),
		// copied to the clipboard and announced; no request is made and the
		// drawer stays open. An empty destination is already blocked by the
		// required destination input.
		if ( elBuilderToggle && elBuilderToggle.checked ) {
			const url = buildDestinationPreview();
			if ( url ) {
				copyWithTextFeedback( elSubmitBtn, url );
				announce( url );
			}
			return;
		}

		const formData = new FormData( elAddForm );
		const payload  = {};
		formData.forEach( function ( value, key ) {
			payload[ key ] = value;
		} );
		// The custom inputs carry no name attribute (FormData would flatten
		// them into string keys); they travel as one object instead.
		payload.custom = readCustomInputs();

		const isEdit = null !== editingId;
		const path   = isEdit ? '/links/' + encodeURIComponent( editingId ) : '/links';

		elAddMsg.textContent = '';
		elAddMsg.className = 'utmat-add-form__msg';

		apiFetch( path, {
			method:  isEdit ? 'PUT' : 'POST',
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			body:    JSON.stringify( payload ),
		} ).then( function () {
			// The reload rebuilds the rows, so the row button that opened the
			// drawer is gone; return focus to the header button instead.
			drawerReturnFocus = elAddLinkBtn;
			exitEditMode();
			flashLinksMsg( isEdit ? ( i18n.updated || 'Link updated.' ) : ( i18n.added || 'Link added.' ) );
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
		'clicked_at', 'link_id', 'channel', 'utm_source', 'utm_medium', 'utm_campaign',
		'utm_id', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid',
		'ttclid', 'li_fat_id', 'twclid', 'destination', 'referrer', 'is_conversion', 'order_total', 'visitor_id',
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

		const url = '/export?' + rangeQuery();

		apiFetch( url ).then( function ( body ) {
			const rows = body.rows || [];
			if ( ! rows.length ) {
				window.alert( i18n.exportEmpty || 'No events to export for this period.' ); // eslint-disable-line no-alert
				return;
			}

			// One column per configured custom parameter, after the built-ins.
			const columns = EXPORT_COLUMNS.concat( utmatData.customParams || [] );
			const lines   = [ columns.map( csvCell ).join( ',' ) ];
			rows.forEach( function ( row ) {
				lines.push( columns.map( function ( col ) {
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

	// ── Settings ──────────────────────────────────────────────────────────────
	const elWebhookUrl      = document.getElementById( 'utmat-webhook-url' );
	const elWebhookSecret   = document.getElementById( 'utmat-webhook-secret' );
	const elWebhookSecretRow = document.getElementById( 'utmat-webhook-secret-row' );
	const elTestWebhook     = document.getElementById( 'utmat-test-webhook' );
	const elWebhookPreset   = document.getElementById( 'utmat-webhook-preset' );
	const elPresetHelp      = document.getElementById( 'utmat-webhook-preset-help' );
	const elWebhookOrders   = document.getElementById( 'utmat-webhook-orders' );
	const elWebhookOrdersHint = document.getElementById( 'utmat-webhook-orders-hint' );
	const elWebhookLeads    = document.getElementById( 'utmat-webhook-leads' );

	// Preset-specific URL placeholders — plain vendor URL shapes, not
	// translated strings.
	const WEBHOOK_PLACEHOLDERS = {
		generic:      'https://example.com/webhooks/utm',
		zapier:       'https://hooks.zapier.com/hooks/catch/123456/abcdef/',
		make:         'https://hook.eu1.make.com/abcdefghijklmnopqrstuvwxyz012345',
		hubspot:      'https://api.hsforms.com/submissions/v3/integration/submit/{portalId}/{formGuid}',
		gohighlevel:  'https://services.leadconnectorhq.com/hooks/{locationId}/webhook-trigger/{id}',
	};

	const PRESET_HELP_KEYS = {
		generic:      'presetHelpGeneric',
		zapier:       'presetHelpZapier',
		make:         'presetHelpMake',
		hubspot:      'presetHelpHubspot',
		gohighlevel:  'presetHelpGohighlevel',
	};

	function toggleWebhookExtras( hasUrl ) {
		if ( elWebhookSecretRow ) elWebhookSecretRow.hidden = ! hasUrl;
		if ( elTestWebhook ) elTestWebhook.hidden = ! hasUrl;
	}

	// Swap the preset help text and URL placeholder, and disable the orders
	// switch under the HubSpot preset (orders are never sent there).
	function applyPresetUi( preset ) {
		if ( ! PRESET_HELP_KEYS[ preset ] ) {
			preset = 'generic';
		}
		if ( elPresetHelp ) {
			elPresetHelp.textContent = i18n[ PRESET_HELP_KEYS[ preset ] ] || '';
		}
		if ( elWebhookUrl && WEBHOOK_PLACEHOLDERS[ preset ] ) {
			elWebhookUrl.placeholder = WEBHOOK_PLACEHOLDERS[ preset ];
		}
		if ( elWebhookPreset ) {
			elWebhookPreset.value = preset;
		}
		if ( elWebhookOrders ) {
			const hubspot = 'hubspot' === preset;
			elWebhookOrders.disabled = hubspot;
			if ( elWebhookOrdersHint ) {
				elWebhookOrdersHint.hidden = ! hubspot;
			}
		}
	}

	// Current form values as strings, for the unsaved-changes comparison.
	function readSettingsForm() {
		return {
			retention: String( elRetention.value ),
			cookie:    String( elCookieDays.value ),
			weekly:    String( !! elWeekly.checked ),
			webhook:   elWebhookUrl ? String( elWebhookUrl.value ) : '',
			preset:    elWebhookPreset ? String( elWebhookPreset.value ) : 'generic',
			orders:    elWebhookOrders ? String( !! elWebhookOrders.checked ) : 'true',
			leads:     elWebhookLeads ? String( !! elWebhookLeads.checked ) : 'true',
			consent:   elConsentCategory ? String( elConsentCategory.value ) : '',
			visits:    elTrackVisits ? String( !! elTrackVisits.checked ) : '',
			custom:    elCustomParams ? String( elCustomParams.value ) : '',
		};
	}

	function applySettings( s ) {
		if ( ! s ) return;
		elRetention.value  = s.retention;
		elCookieDays.value = s.cookie;
		elWeekly.checked   = 'true' === s.weekly;
		if ( elCustomParams ) {
			elCustomParams.value = s.custom || '';
		}
		if ( elConsentCategory ) {
			elConsentCategory.value = s.consent || 'marketing';
		}
		if ( elTrackVisits ) {
			elTrackVisits.checked = 'true' === s.visits;
		}
		if ( elWebhookUrl ) {
			elWebhookUrl.value = s.webhook;
			toggleWebhookExtras( !! s.webhook );
		}
		if ( elWebhookOrders ) {
			elWebhookOrders.checked = 'true' === s.orders;
		}
		if ( elWebhookLeads ) {
			elWebhookLeads.checked = 'true' === s.leads;
		}
		applyPresetUi( s.preset || 'generic' );
	}

	function countSettingsChanges() {
		if ( ! settingsBaseline ) return 0;
		const now = readSettingsForm();
		return Object.keys( settingsBaseline ).filter( function ( key ) {
			return settingsBaseline[ key ] !== now[ key ];
		} ).length;
	}

	function updateUnsavedCount() {
		const n = countSettingsChanges();
		if ( elUnsaved ) {
			elUnsaved.textContent = n
				? fmt( i18n.unsavedCount || 'Unsaved changes: %s', n )
				: ( i18n.unsavedNone || 'No unsaved changes' );
		}
		if ( elDiscard ) {
			elDiscard.disabled = 0 === n;
		}
		if ( elSavebar ) {
			elSavebar.classList.toggle( 'is-dirty', n > 0 );
		}
	}

	function snapshotSettings() {
		settingsBaseline = readSettingsForm();
		updateUnsavedCount();
	}

	if ( settings ) {
		elRetention.value  = settings.retentionDays;
		elWeekly.checked   = !! settings.weeklyEmail;
		elCookieDays.value = settings.cookieDays || 90;
		if ( elConsentCategory ) {
			elConsentCategory.value = settings.consentCategory || 'marketing';
		}
		if ( elTrackVisits ) {
			// On unless the admin switched it off (the option defaults to on).
			elTrackVisits.checked = undefined === settings.trackVisits || !! settings.trackVisits;
		}
		if ( elWebhookUrl ) {
			elWebhookUrl.value = settings.webhookUrl || '';
			toggleWebhookExtras( !! settings.webhookUrl );
		}
		if ( elWebhookOrders ) {
			// On unless the admin switched it off (both events default to on).
			elWebhookOrders.checked = undefined === settings.webhookOrders || !! settings.webhookOrders;
		}
		if ( elWebhookLeads ) {
			elWebhookLeads.checked = undefined === settings.webhookLeads || !! settings.webhookLeads;
		}
		applyPresetUi( settings.webhookPreset || 'generic' );
	}
	if ( elCustomParams ) {
		elCustomParams.value = ( utmatData.customParams || [] ).join( ', ' );
	}
	snapshotSettings();

	if ( elWebhookSecret ) {
		elWebhookSecret.value = utmatData.webhookSecret || '';
		elWebhookSecret.addEventListener( 'focus', function () {
			elWebhookSecret.select();
		} );
		elWebhookSecret.addEventListener( 'click', function () {
			elWebhookSecret.select();
		} );
	}
	const elWebhookLast = document.getElementById( 'utmat-webhook-last' );
	if ( elWebhookLast && utmatData.webhookLast ) {
		const last = utmatData.webhookLast;
		const when = new Date( last.time * 1000 ).toLocaleString();
		elWebhookLast.textContent = fmt(
			last.ok
				? ( i18n.webhookLastOk || 'Last delivery: %1$s succeeded (%2$s)' )
				: ( i18n.webhookLastFailed || 'Last delivery: %1$s failed (%2$s)' ),
			when,
			last.detail || ''
		);
	}

	// Reassure the admin that a detected full-page cache doesn't break
	// tracking — the front-end beacon already handles it.
	const elCacheNotice = document.getElementById( 'utmat-cache-notice' );
	if ( elCacheNotice && cachePlugin ) {
		elCacheNotice.querySelector( 'p' ).textContent = fmt(
			i18n.cacheDetected || 'Detected %s. Tracking still works correctly on cached pages via the front-end beacon.',
			cachePlugin
		);
		show( elCacheNotice );
	}

	elSettingsForm.addEventListener( 'input', updateUnsavedCount );
	elSettingsForm.addEventListener( 'change', updateUnsavedCount );

	if ( elDiscard ) {
		elDiscard.addEventListener( 'click', function () {
			applySettings( settingsBaseline );
			updateUnsavedCount();
			elSettingsMsg.textContent = '';
			elSettingsMsg.className   = 'utmat-add-form__msg';
		} );
	}

	if ( elSecretCopy && elWebhookSecret ) {
		elSecretCopy.addEventListener( 'click', function () {
			copyWithTextFeedback( elSecretCopy, elWebhookSecret.value );
		} );
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
				webhook_url:    elWebhookUrl ? elWebhookUrl.value.trim() : '',
				webhook_preset: elWebhookPreset ? elWebhookPreset.value : 'generic',
				webhook_orders: elWebhookOrders ? elWebhookOrders.checked : true,
				webhook_leads:  elWebhookLeads ? elWebhookLeads.checked : true,
				consent_category: elConsentCategory ? elConsentCategory.value : 'marketing',
				track_visits:   elTrackVisits ? elTrackVisits.checked : true,
				custom_params:  elCustomParams ? elCustomParams.value.trim() : '',
			} ),
		} ).then( function ( body ) {
			elRetention.value  = body.retention_days;
			elWeekly.checked   = !! body.weekly_email;
			elCookieDays.value = body.cookie_days || 90;
			if ( elCustomParams && Array.isArray( body.custom_params ) ) {
				elCustomParams.value = body.custom_params.join( ', ' );
				// Refresh the in-memory list so the drawer inputs and the
				// CSV export columns pick the new names up without a reload.
				utmatData.customParams = body.custom_params;
				renderCustomInputs();
			}
			if ( elConsentCategory ) {
				elConsentCategory.value = body.consent_category || 'marketing';
			}
			if ( elTrackVisits ) {
				elTrackVisits.checked = !! body.track_visits;
				// loadDashboard() re-derives the hint from settings on every
				// stats reload, so keep the localized value in step.
				if ( settings ) {
					settings.trackVisits = !! body.track_visits;
				}
				if ( elChannelHint ) {
					elChannelHint.hidden = !! body.track_visits;
				}
			}
			if ( elWebhookUrl ) {
				elWebhookUrl.value = body.webhook_url || '';
				toggleWebhookExtras( !! body.webhook_url );
			}
			if ( elWebhookOrders ) {
				elWebhookOrders.checked = undefined === body.webhook_orders || !! body.webhook_orders;
			}
			if ( elWebhookLeads ) {
				elWebhookLeads.checked = undefined === body.webhook_leads || !! body.webhook_leads;
			}
			applyPresetUi( body.webhook_preset || 'generic' );
			snapshotSettings();
			elSettingsMsg.textContent = i18n.settingsSaved || 'Settings saved.';
			elSettingsMsg.className   = 'utmat-add-form__msg is-success';
		} ).catch( function ( err ) {
			elSettingsMsg.textContent = err.message || 'Unknown error.';
			elSettingsMsg.className   = 'utmat-add-form__msg is-error';
		} );
	} );

	// ── Test email ────────────────────────────────────────────────────────────
	const elTestEmail = document.getElementById( 'utmat-test-email' );
	const elEmailMsg  = elTestEmailMsg || elSettingsMsg;

	elTestEmail.addEventListener( 'click', function () {
		elTestEmail.disabled   = true;
		elEmailMsg.textContent = i18n.testEmailSending || 'Sending…';
		elEmailMsg.className   = 'utmat-add-form__msg';

		apiFetch( '/test-email', { method: 'POST' } ).then( function ( body ) {
			elEmailMsg.textContent = body.sent
				? ( i18n.testEmailSent || 'Test email sent.' )
				: ( i18n.testEmailFailed || 'Sending failed.' );
			elEmailMsg.className = 'utmat-add-form__msg ' + ( body.sent ? 'is-success' : 'is-error' );
		} ).catch( function ( err ) {
			elEmailMsg.textContent = err.message || 'Unknown error.';
			elEmailMsg.className   = 'utmat-add-form__msg is-error';
		} ).then( function () {
			elTestEmail.disabled = false;
		} );
	} );

	// ── Test webhook ──────────────────────────────────────────────────────────
	if ( elWebhookPreset ) {
		// The form-level change listener already updates the unsaved count;
		// this one swaps the preset-specific help text and placeholder.
		elWebhookPreset.addEventListener( 'change', function () {
			applyPresetUi( elWebhookPreset.value );
		} );
	}

	if ( elTestWebhook ) {
		const elWebhookMsg = elTestWebhookMsg || elSettingsMsg;
		elTestWebhook.addEventListener( 'click', function () {
			elTestWebhook.disabled   = true;
			elWebhookMsg.textContent = i18n.testWebhookSending || 'Sending…';
			elWebhookMsg.className   = 'utmat-add-form__msg';

			// Post the live select and leads switch so the admin can test a
			// preset before saving it.
			apiFetch( '/test-webhook', {
				method:  'POST',
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				body:    JSON.stringify( {
					preset: elWebhookPreset ? elWebhookPreset.value : '',
					leads:  elWebhookLeads ? elWebhookLeads.checked : true,
				} ),
			} ).then( function ( body ) {
				elWebhookMsg.textContent = body.sent
					? ( i18n.testWebhookSent || 'Test webhook sent.' )
					: ( i18n.testWebhookFailed || 'Delivery failed — check the URL and your receiver.' );
				elWebhookMsg.className = 'utmat-add-form__msg ' + ( body.sent ? 'is-success' : 'is-error' );
			} ).catch( function ( err ) {
				elWebhookMsg.textContent = err.message || 'Unknown error.';
				elWebhookMsg.className   = 'utmat-add-form__msg is-error';
			} ).then( function () {
				elTestWebhook.disabled = false;
			} );
		} );
	}

	// ── Diagnostics (Tracking Doctor) ────────────────────────────────────────
	const elRunDiagnostics         = document.getElementById( 'utmat-run-diagnostics' );
	const elDiagnosticsMsg         = document.getElementById( 'utmat-diagnostics-msg' );
	const elDiagnosticsList        = document.getElementById( 'utmat-diagnostics-list' );
	const elDiagnosticsForms       = document.getElementById( 'utmat-diagnostics-forms' );
	const elDiagnosticsFormsTbody  = document.getElementById( 'utmat-diagnostics-forms-tbody' );
	const elDiagnosticsUnsupported = document.getElementById( 'utmat-diagnostics-unsupported' );

	const DIAG_STATUS_LABELS = {
		pass: i18n.diagStatusPass || 'Passed',
		warn: i18n.diagStatusWarn || 'Warning',
		fail: i18n.diagStatusFail || 'Failed',
		skip: i18n.diagStatusSkip || 'Skipped',
	};

	const FORM_STATUS_LABELS = {
		complete: i18n.diagFormComplete || 'Covered',
		partial:  i18n.diagFormPartial || 'Partial',
		none:     i18n.diagFormNone || 'No fields',
	};

	// The forms-matrix badge reuses the check badge's pass/warn/fail palette.
	const FORM_STATUS_BADGE = { complete: 'pass', partial: 'warn', none: 'fail' };

	function renderFormsMatrix( details ) {
		const matrix      = ( details && details.matrix ) || [];
		const unsupported = ( details && details.unsupported_plugins ) || [];

		elDiagnosticsFormsTbody.innerHTML = '';

		if ( ! matrix.length && ! unsupported.length ) {
			hide( elDiagnosticsForms );
			return;
		}

		matrix.forEach( function ( row ) {
			const tr = elDiagnosticsFormsTbody.insertRow();
			tr.insertCell().textContent = row.form_title;
			tr.insertCell().textContent = row.integration || '';

			const statusCell = tr.insertCell();
			const badge       = document.createElement( 'span' );
			badge.className   = 'utmat-diagnostic-badge is-' + ( FORM_STATUS_BADGE[ row.status ] || 'skip' );
			badge.textContent = FORM_STATUS_LABELS[ row.status ] || row.status;
			statusCell.appendChild( badge );

			tr.insertCell().textContent = ( row.present && row.present.length ) ? row.present.join( ', ' ) : ( i18n.diagNone || '—' );
			tr.insertCell().textContent = ( row.missing && row.missing.length ) ? row.missing.join( ', ' ) : ( i18n.diagNone || '—' );
		} );

		if ( unsupported.length ) {
			elDiagnosticsUnsupported.textContent = unsupported.map( function ( p ) {
				return p.label + ': ' + p.message;
			} ).join( ' ' );
			show( elDiagnosticsUnsupported );
		} else {
			hide( elDiagnosticsUnsupported );
		}

		show( elDiagnosticsForms );
	}

	// Pass / warning / fail / skip counts above the checks list.
	function renderDiagnosticsSummary( checks ) {
		if ( ! elDiagSummary ) return;
		const counts = { pass: 0, warn: 0, fail: 0, skip: 0 };
		checks.forEach( function ( check ) {
			if ( Object.prototype.hasOwnProperty.call( counts, check.status ) ) {
				counts[ check.status ]++;
			}
		} );
		Object.keys( counts ).forEach( function ( status ) {
			const el = document.getElementById( 'utmat-diag-count-' + status );
			if ( el ) {
				el.textContent = String( counts[ status ] );
			}
		} );
		show( elDiagSummary );
	}

	function renderDiagnostics( checks ) {
		const elDiagPlaceholder = document.getElementById( 'utmat-diagnostics-placeholder' );
		if ( elDiagPlaceholder ) hide( elDiagPlaceholder );
		elDiagnosticsList.innerHTML = '';
		hide( elDiagnosticsForms );

		checks.forEach( function ( check ) {
			const li   = document.createElement( 'li' );
			li.className = 'utmat-diagnostic-item';

			const badge       = document.createElement( 'span' );
			badge.className   = 'utmat-diagnostic-badge is-' + check.status;
			badge.textContent = DIAG_STATUS_LABELS[ check.status ] || check.status;

			const body = document.createElement( 'div' );
			body.className = 'utmat-diagnostic-body';
			const title = document.createElement( 'h3' );
			title.className   = 'utmat-diagnostic-title';
			title.textContent = check.label;
			const message = document.createElement( 'p' );
			message.className   = 'utmat-diagnostic-message';
			message.textContent = check.message;
			body.appendChild( title );
			body.appendChild( message );

			li.appendChild( badge );
			li.appendChild( body );
			elDiagnosticsList.appendChild( li );

			if ( 'forms' === check.id ) {
				renderFormsMatrix( check.details );
			}
		} );

		show( elDiagnosticsList );
		renderDiagnosticsSummary( checks );
	}

	if ( elRunDiagnostics ) {
		elRunDiagnostics.addEventListener( 'click', function () {
			elRunDiagnostics.disabled = true;
			elDiagnosticsMsg.textContent = i18n.diagnosticsRunning || 'Running…';
			elDiagnosticsMsg.className   = 'utmat-add-form__msg';

			apiFetch( '/diagnostics' ).then( function ( body ) {
				renderDiagnostics( body.checks || [] );
				elDiagnosticsMsg.textContent = '';
				if ( elDiagLast ) {
					elDiagLast.textContent = fmt( i18n.diagLastRun || 'Last run at %s', new Date().toLocaleTimeString() );
				}
			} ).catch( function ( err ) {
				elDiagnosticsMsg.textContent = err.message || ( i18n.diagnosticsFailed || 'Could not run diagnostics.' );
				elDiagnosticsMsg.className   = 'utmat-add-form__msg is-error';
			} ).then( function () {
				elRunDiagnostics.disabled = false;
			} );
		} );

		// The hash still changes natively (history entry kept); the tab is
		// activated here so the run starts with the panel visible.
		const elWrap = document.querySelector( '.utmat-wrap' );
		if ( elWrap ) {
			elWrap.addEventListener( 'click', function ( e ) {
				if ( ! e.target.closest( '[data-utmat-run-diagnostics]' ) ) return;
				activateTab( 'diagnostics' );
				document.getElementById( 'utmat-panel-diagnostics' ).focus();
				if ( ! elRunDiagnostics.disabled ) {
					elRunDiagnostics.click();
				}
			} );
		}
	}

	// ── Date range query string ───────────────────────────────────────────────
	// Shared by the stats and export requests. A custom range (both dates set)
	// takes precedence server-side over `period`; `period` is still sent so a
	// malformed/partial range degrades to the preset instead of erroring.
	function rangeQuery() {
		let qs = 'period=' + activePeriod;
		if ( activeCampaign ) {
			qs += '&campaign=' + encodeURIComponent( activeCampaign );
		}
		if ( activeSince && activeUntil ) {
			qs += '&since=' + encodeURIComponent( activeSince ) + '&until=' + encodeURIComponent( activeUntil );
		}
		return qs;
	}

	// ── Main fetch ────────────────────────────────────────────────────────────
	function loadDashboard() {
		setLoading( true );

		const statsUrl = '/stats?' + rangeQuery();

		Promise.all( [
			apiFetch( statsUrl ),
			apiFetch( '/links' ),
		] ).then( function ( results ) {
			const stats    = results[ 0 ];
			const linksRes = results[ 1 ] || {};

			setLoading( false );

			// First run — nothing was ever recorded.
			const isEmpty = 0 === ( Number( stats.all_time_total ) || 0 );
			renderEmptyState( isEmpty );
			renderTiles( stats, isEmpty );
			renderCompareCaption();

			// Populate campaign filter.
			populateCampaigns( stats.campaigns || [] );

			// Line chart — clicks (plus conversions and the previous-period
			// overlay when present) by day.
			const days   = ( stats.clicks_by_day || [] ).map( function ( d ) { return d.date; } );
			const counts = ( stats.clicks_by_day || [] ).map( function ( d ) { return d.count; } );
			const convs  = ( stats.clicks_by_day || [] ).map( function ( d ) { return d.conversions || 0; } );
			const prev   = stats.previous_clicks_by_day || [];
			makeLineChart( 'utmat-chart-line', days, counts, convs, prev );

			// Breakdown bar lists.
			renderBarList( 'utmat-bars-source', stats.by_source || [] );
			renderBarList( 'utmat-bars-campaign', stats.by_campaign || [] );
			renderBarList( 'utmat-bars-medium', stats.by_medium || [] );
			renderBarList( 'utmat-bars-device', stats.by_device || [], {
				share: true,
				label: function ( d ) { return DEVICE_NAMES[ d.label ] || d.label; },
			} );
			// Channels (direct, organic search, paid search, ...), the one
			// breakdown that includes untagged landing visits. The hint shows
			// only while the admin has switched visit tracking off.
			renderBarList( 'utmat-bars-channel', stats.by_channel || [], {
				label: function ( d ) { return CHANNEL_NAMES[ d.label ] || d.label; },
			} );
			if ( elChannelHint ) {
				elChannelHint.hidden = !! ( settings && settings.trackVisits );
			}
			// Top landing pages, labels shortened to the URL path.
			renderBarList( 'utmat-bars-landing', stats.by_landing || [], {
				label: function ( d ) { return shortenUrl( d.label ); },
				title: function ( d ) { return d.label; },
			} );
			// Top referrers (external hosts only).
			renderBarList( 'utmat-bars-referrer', stats.by_referrer || [] );
			// Ad platforms, derived from click IDs.
			renderBarList( 'utmat-bars-platform', stats.by_platform || [], {
				label: function ( d ) { return PLATFORM_NAMES[ d.label ] || d.label; },
			} );

			// Revenue by campaign (WooCommerce only).
			if ( wooActive && elRevenueCard ) {
				show( elRevenueCard );
				makeRevenueChart( 'utmat-chart-revenue', stats.revenue_by_campaign || [] );
				renderRevenueSummary( stats.revenue_by_campaign || [] );
			}

			// Links table, toolbar filter, suggestion datalists and presets.
			allLinks = linksRes.links || [];
			populateLinksCampaigns();
			refreshLinksTable();
			populateDatalists( linksRes.suggestions || {} );
			populatePresets( linksRes.presets || [] );
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
			clearDateRange();
			loadDashboard();
		} );
	} );

	elCampaignSel.addEventListener( 'change', function () {
		activeCampaign = elCampaignSel.value;
		loadDashboard();
	} );

	// ── Custom date range ─────────────────────────────────────────────────────
	const elDateSince = document.getElementById( 'utmat-date-since' );
	const elDateUntil = document.getElementById( 'utmat-date-until' );
	const elDateApply = document.getElementById( 'utmat-date-apply' );
	const elDateClear = document.getElementById( 'utmat-date-clear' );

	function clearDateRange() {
		activeSince = '';
		activeUntil = '';
		if ( elDateSince ) elDateSince.value = '';
		if ( elDateUntil ) elDateUntil.value = '';
		if ( elDateClear ) elDateClear.hidden = true;
	}

	if ( elDateApply ) {
		elDateApply.addEventListener( 'click', function () {
			const since = elDateSince.value;
			const until = elDateUntil.value;
			if ( ! since || ! until || since > until ) {
				return;
			}

			activeSince = since;
			activeUntil = until;

			// A custom range replaces the preset selection visually.
			document.querySelectorAll( '.utmat-period-btn' ).forEach( function ( b ) {
				b.classList.remove( 'active' );
				b.setAttribute( 'aria-pressed', 'false' );
			} );
			elDateClear.hidden = false;
			loadDashboard();
		} );
	}

	if ( elDateClear ) {
		elDateClear.addEventListener( 'click', function () {
			clearDateRange();
			const first = document.querySelector( '.utmat-period-btn[data-period="' + activePeriod + '"]' );
			if ( first ) {
				first.classList.add( 'active' );
				first.setAttribute( 'aria-pressed', 'true' );
			}
			loadDashboard();
		} );
	}

	if ( elLinksFilter ) {
		elLinksFilter.addEventListener( 'input', function () {
			linksFilter = elLinksFilter.value;
			refreshLinksTable();
		} );
	}

	if ( elLinksCampaign ) {
		elLinksCampaign.addEventListener( 'change', function () {
			linksCampaign = elLinksCampaign.value;
			refreshLinksTable();
		} );
	}

	if ( elSortClicks ) {
		const toggleClicksSort = function () {
			clicksSortDir = clicksSortDir === 'desc' ? 'asc' : 'desc';
			refreshLinksTable();
		};
		elSortClicks.addEventListener( 'click', toggleClicksSort );
	}

	// ── Boot ──────────────────────────────────────────────────────────────────
	initTabs();
	loadDashboard();
} )();
