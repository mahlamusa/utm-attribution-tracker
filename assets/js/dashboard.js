/* global Chart, utmatData */
/**
 * UTM & Attribution Tracker — Dashboard JS
 *
 * Fetches stats from the REST API and renders Chart.js charts, manages the
 * tracked-links table (list, add, delete). All user-visible strings come
 * from the utmat-i18n JSON block so they pass through WordPress i18n
 * on the PHP side.
 */
( function () {
	'use strict';

	// ── Config ────────────────────────────────────────────────────────────────
	const { apiBase, nonce, wooActive, currencySymbol } = utmatData;

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
	function makeLineChart( canvasId, labels, values ) {
		const ctx = document.getElementById( canvasId );
		if ( ! ctx ) return;
		if ( charts[ canvasId ] ) charts[ canvasId ].destroy();

		toggleNoData( canvasId, true );

		charts[ canvasId ] = new Chart( ctx, {
			type: 'line',
			data: {
				labels,
				datasets: [ {
					label:           i18n.clicksLabel || 'Clicks',
					data:            values,
					borderColor:     COLORS[ 0 ],
					backgroundColor: 'rgba(13,94,163,0.08)',
					borderWidth:     2,
					pointRadius:     3,
					pointHoverRadius: 5,
					fill:            true,
					tension:         0.3,
				} ],
			},
			options: {
				responsive:          true,
				maintainAspectRatio: true,
				interaction:         { mode: 'index', intersect: false },
				plugins: {
					legend: { display: false },
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

	// ── Populate links table ───────────────────────────────────────────────────
	function renderLinksTable( links ) {
		elLinksTbody.innerHTML = '';

		if ( ! links.length ) {
			const row = elLinksTbody.insertRow();
			const cell = row.insertCell();
			cell.colSpan = 6;
			cell.className = 'description';
			cell.textContent = i18n.noLinks || 'No tracked links found.';
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
			const clicksCell = row.insertCell();
			clicksCell.innerHTML = '<span class="utmat-clicks-badge">' + link.clicks + '</span>';

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

			// Edit / Delete.
			const actionsCell = row.insertCell();
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
				row.remove();
				if ( ! elLinksTbody.rows.length ) {
					loadDashboard();
				}
			} )
			.catch( function ( err ) {
				window.alert( err.message || 'Unknown error.' ); // eslint-disable-line no-alert
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

	// ── Main fetch ────────────────────────────────────────────────────────────
	function loadDashboard() {
		setLoading( true );

		const statsUrl = '/stats?period=' + activePeriod +
			( activeCampaign ? '&campaign=' + encodeURIComponent( activeCampaign ) : '' );

		Promise.all( [
			apiFetch( statsUrl ),
			apiFetch( '/links' ),
		] ).then( function ( results ) {
			const stats = results[ 0 ];
			const links = results[ 1 ];

			setLoading( false );
			show( elCharts );

			// Total count.
			elTotal.textContent = stats.total;

			// Populate campaign filter.
			populateCampaigns( stats.campaigns || [] );

			// Line chart — clicks by day.
			const days   = ( stats.clicks_by_day || [] ).map( function ( d ) { return d.date; } );
			const counts = ( stats.clicks_by_day || [] ).map( function ( d ) { return d.count; } );
			makeLineChart( 'utmat-chart-line', days, counts );

			// Doughnut — by source.
			makeDoughnutChart( 'utmat-chart-source', stats.by_source || [] );

			// Bar — by campaign.
			makeBarChart( 'utmat-chart-campaign', stats.by_campaign || [] );

			// Bar — by medium.
			makeBarChart( 'utmat-chart-medium', stats.by_medium || [] );

			// Bar — revenue by campaign (WooCommerce only).
			if ( wooActive && elRevenueCard ) {
				show( elRevenueCard );
				makeRevenueChart( 'utmat-chart-revenue', stats.revenue_by_campaign || [] );
			}

			// Links table.
			renderLinksTable( links );
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

	// ── Boot ──────────────────────────────────────────────────────────────────
	loadDashboard();
} )();
