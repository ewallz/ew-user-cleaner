/**
 * EW User Cleaner admin behaviour.
 *
 * Scans run only while this page is open. Progress is persisted server side so
 * closing the page pauses the job instead of losing work.
 */
( function () {
	'use strict';

	var data = window.ewucData || {};
	var running = false;
	var jobId = data.jobId || 0;

	function request( path, options ) {
		return window.wp.apiFetch( {
			url: data.root + path,
			method: ( options && options.method ) || 'GET',
			data: options && options.body,
			headers: { 'X-WP-Nonce': data.nonce }
		} );
	}

	function setText( selector, text ) {
		var node = document.querySelector( selector );

		if ( node ) {
			node.textContent = text;
		}
	}

	function message( selector, text ) {
		setText( selector, text );
	}

	function updateProgress( result ) {
		setText( '[data-ewuc-processed]', new Intl.NumberFormat().format( result.processed || 0 ) );
		setText( '[data-ewuc-matched]', new Intl.NumberFormat().format( result.matched || 0 ) );
		setText( '[data-ewuc-status]', result.status || '' );

		var bar = document.querySelector( '[data-ewuc-bar]' );
		var wrap = document.querySelector( '.ewuc-progress' );

		if ( bar && result.upper ) {
			var percent = Math.min( 100, Math.round( ( result.cursor / result.upper ) * 100 ) );
			bar.style.width = percent + '%';

			if ( wrap ) {
				wrap.setAttribute( 'aria-valuenow', String( percent ) );
			}
		}
	}

	function loop() {
		if ( ! running || ! jobId ) {
			return;
		}

		request( '/scan/' + jobId + '/batch', { method: 'POST' } )
			.then( function ( result ) {
				updateProgress( result );

				if ( result.done || 'running' !== result.status ) {
					running = false;
					message( '[data-ewuc-message]', data.i18n.complete );
					return;
				}

				message( '[data-ewuc-message]', data.i18n.scanning );
				window.setTimeout( loop, 250 );
			} )
			.catch( function ( error ) {
				running = false;
				message( '[data-ewuc-message]', ( error && error.message ) || data.i18n.failed );
			} );
	}

	function selectedIds( form ) {
		return Array.prototype.slice
			.call( form.querySelectorAll( 'input[name="user_ids[]"]:checked' ) )
			.map( function ( input ) {
				return parseInt( input.value, 10 );
			} );
	}

	function onClick( selector, handler ) {
		document.addEventListener( 'click', function ( event ) {
			var target = event.target.closest( selector );

			if ( target ) {
				event.preventDefault();
				handler( target );
			}
		} );
	}

	// Scan controls.
	onClick( '[data-ewuc-start]', function () {
		request( '/scan', { method: 'POST' } )
			.then( function ( result ) {
				jobId = result.job_id;
				running = true;
				message( '[data-ewuc-message]', data.i18n.scanning );
				loop();
			} )
			.catch( function ( error ) {
				message( '[data-ewuc-message]', ( error && error.message ) || data.i18n.failed );
			} );
	} );

	onClick( '[data-ewuc-resume]', function () {
		if ( ! jobId ) {
			return;
		}

		request( '/scan/' + jobId + '/status', { method: 'PUT', body: { status: 'running' } } )
			.then( function () {
				running = true;
				loop();
			} )
			.catch( function ( error ) {
				message( '[data-ewuc-message]', ( error && error.message ) || data.i18n.failed );
			} );
	} );

	onClick( '[data-ewuc-pause]', function () {
		running = false;

		if ( ! jobId ) {
			return;
		}

		request( '/scan/' + jobId + '/status', { method: 'PUT', body: { status: 'paused' } } )
			.then( function () {
				message( '[data-ewuc-message]', data.i18n.paused );
				setText( '[data-ewuc-status]', 'paused' );
			} );
	} );

	// Select all on the current page only.
	document.addEventListener( 'change', function ( event ) {
		var toggle = event.target.closest( '[data-ewuc-toggle-all]' );

		if ( ! toggle ) {
			return;
		}

		var form = toggle.closest( 'form' );

		if ( ! form ) {
			return;
		}

		form.querySelectorAll( 'input[name="user_ids[]"]' ).forEach( function ( input ) {
			input.checked = toggle.checked;
		} );
	} );

	// Candidate bulk actions.
	onClick( '[data-ewuc-quarantine]', function ( button ) {
		var form = button.closest( 'form' );
		var ids = selectedIds( form );

		if ( ! ids.length ) {
			return;
		}

		var override = form.querySelector( '[data-ewuc-override]' );

		if ( override && override.checked && ! window.confirm(
			'These accounts may own content or orders. Their content will be reassigned, not deleted. Continue?'
		) ) {
			return;
		}

		// The server truncates to the configured batch size. Say so up front
		// instead of silently dropping the tail of the selection.
		var limit = parseInt( data.batchQuarantine, 10 ) || 0;

		if ( limit && ids.length > limit && ! window.confirm(
			data.i18n.batchTruncated
				.replace( '%1$s', ids.length )
				.replace( '%2$s', limit )
		) ) {
			return;
		}

		request( '/quarantine', {
			method: 'POST',
			body: {
				job_id: parseInt( form.dataset.job, 10 ) || 0,
				user_ids: ids,
				override: !! ( override && override.checked )
			}
		} )
			.then( function ( result ) {
				message(
					'[data-ewuc-bulk-message]',
					'Quarantined ' + result.quarantined.length + ', skipped ' + result.skipped.length + '.'
				);
				window.location.reload();
			} )
			.catch( function ( error ) {
				message( '[data-ewuc-bulk-message]', ( error && error.message ) || data.i18n.failed );
			} );
	} );

	onClick( '[data-ewuc-dismiss]', function ( button ) {
		var form = button.closest( 'form' );
		var ids = selectedIds( form );

		if ( ! ids.length ) {
			return;
		}

		request( '/candidates/dismiss', {
			method: 'POST',
			body: { job_id: parseInt( form.dataset.job, 10 ) || 0, user_ids: ids }
		} ).then( function () {
			window.location.reload();
		} );
	} );

	// Quarantine actions.
	onClick( '[data-ewuc-restore]', function ( button ) {
		var form = button.closest( 'form' );
		var ids = selectedIds( form );

		if ( ! ids.length ) {
			return;
		}

		request( '/quarantine/restore', { method: 'POST', body: { user_ids: ids } } )
			.then( function () {
				window.location.reload();
			} )
			.catch( function ( error ) {
				message( '[data-ewuc-quarantine-message]', ( error && error.message ) || data.i18n.failed );
			} );
	} );

	onClick( '[data-ewuc-purge]', function ( button ) {
		var form = button.closest( 'form' );
		var ids = selectedIds( form );

		if ( ! ids.length ) {
			return;
		}

		var confirmation = window.prompt( data.i18n.confirm );

		if ( ! confirmation ) {
			return;
		}

		request( '/purge', { method: 'POST', body: { user_ids: ids, confirm: confirmation } } )
			.then( function ( result ) {
				message(
					'[data-ewuc-quarantine-message]',
					'Purged ' + result.purged.length + ', skipped ' + result.skipped.length + ', failed ' + result.failed.length + '.'
				);
				window.setTimeout( function () {
					window.location.reload();
				}, 1500 );
			} )
			.catch( function ( error ) {
				message( '[data-ewuc-quarantine-message]', ( error && error.message ) || data.i18n.failed );
			} );
	} );

	// Purge every quarantined account, one bounded batch per request.
	var purgeAllRunning = false;

	onClick( '[data-ewuc-purge-all]', function ( button ) {
		if ( purgeAllRunning ) {
			purgeAllRunning = false;
			button.textContent = data.i18n.purgeAllStopped;
			return;
		}

		var total = parseInt( button.getAttribute( 'data-total' ), 10 ) || 0;

		if ( ! total ) {
			message( '[data-ewuc-quarantine-message]', data.i18n.nothingQuarantined );
			return;
		}

		var typed = window.prompt( data.i18n.confirmAll );

		if ( ! typed ) {
			return;
		}

		purgeAllRunning = true;

		var totals = { purged: 0, skipped: 0, failed: 0 };
		var cursor = 0;

		function report( remaining ) {
			message(
				'[data-ewuc-quarantine-message]',
				data.i18n.purgeAllProgress
					.replace( '%1$s', totals.purged )
					.replace( '%2$s', totals.skipped )
					.replace( '%3$s', totals.failed )
					.replace( '%4$s', remaining )
			);
		}

		function step() {
			if ( ! purgeAllRunning ) {
				return;
			}

			request( '/purge/all', { method: 'POST', body: { confirm: typed, after: cursor } } )
				.then( function ( result ) {
					totals.purged += result.purged.length;
					totals.skipped += result.skipped.length;
					totals.failed += result.failed.length;
					cursor = result.cursor;

					report( result.remaining );

					if ( result.done || ! purgeAllRunning ) {
						purgeAllRunning = false;
						window.setTimeout( function () {
							window.location.reload();
						}, 1200 );
						return;
					}

					window.setTimeout( step, 250 );
				} )
				.catch( function ( error ) {
					purgeAllRunning = false;
					button.textContent = data.i18n.purgeAllRetry;
					message( '[data-ewuc-quarantine-message]', ( error && error.message ) || data.i18n.failed );
				} );
		}

		button.textContent = data.i18n.purgeAllStop;
		step();
	} );

	// Quarantine every matching candidate, one bounded batch per request.
	var quarantineAllRunning = false;

	onClick( '[data-ewuc-quarantine-all]', function ( button ) {
		if ( quarantineAllRunning ) {
			quarantineAllRunning = false;
			button.textContent = data.i18n.quarantineAllStopped;
			return;
		}

		var total = parseInt( button.getAttribute( 'data-total' ), 10 ) || 0;

		if ( ! total ) {
			message( '[data-ewuc-bulk-message]', data.i18n.nothingToQuarantine );
			return;
		}

		var typed = window.prompt( data.i18n.confirmQuarantineAll.replace( '%s', total ) );

		if ( ! typed ) {
			return;
		}

		var form = button.closest( 'form' );
		var override = form ? form.querySelector( '[data-ewuc-override]' ) : null;
		var useOverride = !! ( override && override.checked );

		if ( useOverride && ! window.confirm(
			'Some of these accounts may own content or orders. Their content will be reassigned, not deleted. Continue?'
		) ) {
			return;
		}

		var label = button.textContent;

		quarantineAllRunning = true;

		var totals = { quarantined: 0, skipped: 0 };
		var cursor = 0;

		function report( remaining ) {
			message(
				'[data-ewuc-bulk-message]',
				data.i18n.quarantineAllProgress
					.replace( '%1$s', totals.quarantined )
					.replace( '%2$s', totals.skipped )
					.replace( '%3$s', remaining )
			);
		}

		function step() {
			if ( ! quarantineAllRunning ) {
				return;
			}

			request( '/quarantine/all', {
				method: 'POST',
				body: {
					confirm: typed,
					after: cursor,
					job_id: parseInt( form && form.dataset.job, 10 ) || 0,
					search: button.getAttribute( 'data-search' ) || '',
					override: useOverride
				}
			} )
				.then( function ( result ) {
					totals.quarantined += result.quarantined.length;
					totals.skipped += result.skipped.length;
					cursor = result.cursor;

					report( result.remaining );

					if ( result.done || ! quarantineAllRunning ) {
						quarantineAllRunning = false;
						window.setTimeout( function () {
							window.location.reload();
						}, 1200 );
						return;
					}

					window.setTimeout( step, 250 );
				} )
				.catch( function ( error ) {
					quarantineAllRunning = false;
					button.textContent = label;
					message( '[data-ewuc-bulk-message]', ( error && error.message ) || data.i18n.failed );
				} );
		}

		button.textContent = data.i18n.quarantineAllStop;
		step();
	} );

	// Backups.
	onClick( '[data-ewuc-delete-batch]', function ( button ) {
		var batch = button.getAttribute( 'data-ewuc-delete-batch' );

		if ( ! window.confirm( 'Delete this backup permanently?' ) ) {
			return;
		}

		request( '/backups/' + encodeURIComponent( batch ), { method: 'DELETE' } ).then( function () {
			window.location.reload();
		} );
	} );

	onClick( '[data-ewuc-restore-backup]', function () {
		var input = document.getElementById( 'ewuc-restore-id' );
		var id = input ? parseInt( input.value, 10 ) : 0;

		if ( ! id ) {
			return;
		}

		request( '/backups/user/' + id + '/restore', { method: 'POST' } )
			.then( function ( result ) {
				message(
					'[data-ewuc-backup-message]',
					'Restored as user #' + result.new_user_id + ( result.partial ? ' (partial: unresolved references remain)' : '' )
				);
			} )
			.catch( function ( error ) {
				message( '[data-ewuc-backup-message]', ( error && error.message ) || data.i18n.failed );
			} );
	} );

	// Help tab: copy a pattern or domain list to the clipboard.
	onClick( '[data-ewuc-copy]', function ( button ) {
		var value = button.getAttribute( 'data-ewuc-copy' ) || '';

		function done( ok ) {
			message( '[data-ewuc-copy-message]', ok ? data.i18n.copied : data.i18n.copyFailed );
			button.textContent = ok ? data.i18n.copiedShort : button.textContent;

			window.setTimeout( function () {
				button.textContent = data.i18n.copy;
			}, 2000 );
		}

		if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			window.navigator.clipboard.writeText( value ).then(
				function () {
					done( true );
				},
				function () {
					done( false );
				}
			);
			return;
		}

		// Fallback for browsers without the async clipboard API.
		var field = document.createElement( 'textarea' );
		field.value = value;
		field.setAttribute( 'readonly', 'readonly' );
		field.style.position = 'fixed';
		field.style.opacity = '0';
		document.body.appendChild( field );
		field.select();

		var ok = false;

		try {
			ok = document.execCommand( 'copy' );
		} catch ( error ) {
			ok = false;
		}

		document.body.removeChild( field );
		done( ok );
	} );

	// Settings impact preview.
	onClick( '[data-ewuc-preview]', function () {
		request( '/preview' )
			.then( function ( result ) {
				message(
					'[data-ewuc-preview-message]',
					result.matched + ' of the ' + result.sample + ' newest accounts would be flagged. This is an estimate from a sample, not a full count.'
				);
			} )
			.catch( function ( error ) {
				message( '[data-ewuc-preview-message]', ( error && error.message ) || data.i18n.failed );
			} );
	} );
} )();
