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

	/**
	 * Bulk action progress bar.
	 *
	 * Batched actions know the total up front and report a remaining count per
	 * request, so percentage is derived from work actually confirmed done
	 * rather than from elapsed time.
	 *
	 * @param {string} name Value of the data-ewuc-progress attribute.
	 * @return {Object} Progress controller.
	 */
	function progress( name ) {
		var root = document.querySelector( '[data-ewuc-progress="' + name + '"]' );

		function node( selector ) {
			return root ? root.querySelector( selector ) : null;
		}

		return {
			start: function ( total ) {
				if ( ! root ) {
					return;
				}

				root.hidden = false;
				this.set( 0, total, data.i18n.progressStarting );
			},
			/**
			 * @param {number} done  Rows processed so far.
			 * @param {number} total Total rows in scope.
			 * @param {string} note  Optional status text.
			 */
			set: function ( done, total, note ) {
				if ( ! root ) {
					return;
				}

				var percent = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
				var bar = node( '[data-ewuc-progress-bar]' );
				var meter = node( '.ewuc-progress' );
				var label = node( '[data-ewuc-progress-percent]' );
				var text = node( '[data-ewuc-progress-text]' );

				if ( bar ) {
					bar.style.width = percent + '%';
				}

				if ( meter ) {
					meter.setAttribute( 'aria-valuenow', String( percent ) );
				}

				if ( label ) {
					label.textContent = percent + '%';
				}

				if ( text ) {
					text.textContent = note || data.i18n.progressCount
						.replace( '%1$s', new Intl.NumberFormat().format( done ) )
						.replace( '%2$s', new Intl.NumberFormat().format( total ) );
				}
			},
			finish: function ( note ) {
				if ( ! root ) {
					return;
				}

				this.set( 1, 1, note || data.i18n.progressDone );
			},
			stop: function ( note ) {
				var text = node( '[data-ewuc-progress-text]' );

				if ( text && note ) {
					text.textContent = note;
				}
			}
		};
	}

	/**
	 * Disables a button while a long action runs so it cannot be double fired.
	 *
	 * @param {HTMLElement} button  Button element.
	 * @param {boolean}     busy    Whether the action is running.
	 * @param {string}      label   Text to show while busy.
	 * @return {string} The label that was replaced.
	 */
	function setBusy( button, busy, label ) {
		var previous = button.textContent;

		button.disabled = busy;
		button.setAttribute( 'aria-busy', busy ? 'true' : 'false' );

		if ( busy && label ) {
			button.textContent = label;
		}

		return previous;
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

		// The server truncates each request to the configured batch size, so
		// send the selection in bounded chunks instead of silently dropping
		// its tail. Chunking also gives the reviewer real progress instead of
		// a frozen button.
		var limit = parseInt( data.batchQuarantine, 10 ) || 0;
		var chunkSize = limit || ids.length;
		var chunkSize = limit || ids.length;
		var chunks = [];

		for ( var index = 0; index < ids.length; index += chunkSize ) {
			chunks.push( ids.slice( index, index + chunkSize ) );
		}

		var meter = progress( 'quarantine' );
		var totals = { quarantined: 0, skipped: 0 };
		var processed = 0;
		var busyLabel = setBusy( button, true, data.i18n.quarantineWorking );

		meter.start( ids.length );

		function report() {
			message(
				'[data-ewuc-bulk-message]',
				data.i18n.quarantineProgress
					.replace( '%1$s', totals.quarantined )
					.replace( '%2$s', totals.skipped )
			);
		}

		function step( position ) {
			if ( position >= chunks.length ) {
				meter.finish();
				report();
				window.setTimeout( function () {
					window.location.reload();
				}, 900 );
				return;
			}

			request( '/quarantine', {
				method: 'POST',
				body: {
					job_id: parseInt( form.dataset.job, 10 ) || 0,
					user_ids: chunks[ position ],
					override: !! ( override && override.checked )
				}
			} )
				.then( function ( result ) {
					totals.quarantined += result.quarantined.length;
					totals.skipped += result.skipped.length;
					processed += chunks[ position ].length;

					meter.set( processed, ids.length );
					report();
					step( position + 1 );
				} )
				.catch( function ( error ) {
					setBusy( button, false );
					button.textContent = busyLabel;
					meter.stop( data.i18n.progressStopped );
					message( '[data-ewuc-bulk-message]', ( error && error.message ) || data.i18n.failed );
				} );
		}

		step( 0 );
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

		// Each request is capped at the configured purge batch size, so send
		// the selection in bounded chunks and report progress between them.
		var chunkSize = parseInt( data.batchPurge, 10 ) || ids.length;
		var chunks = [];

		for ( var index = 0; index < ids.length; index += chunkSize ) {
			chunks.push( ids.slice( index, index + chunkSize ) );
		}

		var meter = progress( 'purge' );
		var totals = { purged: 0, skipped: 0, failed: 0 };
		var processed = 0;
		var busyLabel = setBusy( button, true, data.i18n.purgeWorking );

		meter.start( ids.length );

		function report() {
			message(
				'[data-ewuc-quarantine-message]',
				data.i18n.purgeProgress
					.replace( '%1$s', totals.purged )
					.replace( '%2$s', totals.skipped )
					.replace( '%3$s', totals.failed )
			);
		}

		function step( position ) {
			if ( position >= chunks.length ) {
				meter.finish();
				report();
				window.setTimeout( function () {
					window.location.reload();
				}, 1200 );
				return;
			}

			request( '/purge', { method: 'POST', body: { user_ids: chunks[ position ], confirm: confirmation } } )
				.then( function ( result ) {
					totals.purged += result.purged.length;
					totals.skipped += result.skipped.length;
					totals.failed += result.failed.length;
					processed += chunks[ position ].length;

					meter.set( processed, ids.length );
					report();
					step( position + 1 );
				} )
				.catch( function ( error ) {
					setBusy( button, false );
					button.textContent = busyLabel;
					meter.stop( data.i18n.progressStopped );
					message( '[data-ewuc-quarantine-message]', ( error && error.message ) || data.i18n.failed );
				} );
		}

		step( 0 );
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
		var meter = progress( 'purge' );

		meter.start( total );

		function report( remaining ) {
			// remaining is a live count of still-quarantined accounts, so this
			// tracks work actually completed rather than requests sent.
			meter.set( Math.max( 0, total - remaining ), total );

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

						if ( result.done ) {
							meter.finish();
						} else {
							meter.stop( data.i18n.progressStopped );
						}

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
					meter.stop( data.i18n.progressStopped );
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
		var meter = progress( 'quarantine' );

		meter.start( total );

		function report( remaining ) {
			// Percentage comes from rows the server confirmed it has passed,
			// so a run that skips protected accounts still reaches 100%.
			meter.set( Math.max( 0, total - remaining ), total );

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

						if ( result.done ) {
							meter.finish();
						} else {
							meter.stop( data.i18n.progressStopped );
						}

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
					meter.stop( data.i18n.progressStopped );
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
