(function () {
	'use strict';

	if ( typeof wpressRestore === 'undefined' ) {
		return;
	}

	var streamUrl = wpressRestore.streamUrl;
	var nonce = wpressRestore.nonce;
	var redirectUrl = wpressRestore.redirectUrl || '';

	function getStatusBox() {
		var box = document.getElementById( 'wpress-restore-status' );
		if ( box ) return box;
		box = document.createElement( 'div' );
		box.id = 'wpress-restore-status';
		box.className = 'wpress-restore-status';
		box.setAttribute( 'aria-live', 'polite' );
		box.innerHTML = '<div class="wpress-restore-status-current"></div><ul class="wpress-restore-status-log"></ul>';
		return box;
	}

	function showStatus( container, currentText, logEntry ) {
		var box = getStatusBox();
		if ( ! container.querySelector( '#wpress-restore-status' ) ) {
			container.insertBefore( box, container.firstChild );
		}
		var current = box.querySelector( '.wpress-restore-status-current' );
		var log = box.querySelector( '.wpress-restore-status-log' );
		if ( current && currentText ) {
			current.textContent = currentText;
			current.classList.add( 'active' );
		}
		if ( log && logEntry ) {
			var li = document.createElement( 'li' );
			li.textContent = logEntry;
			log.appendChild( li );
		}
	}

	function showDone( container, message, isError ) {
		var box = document.getElementById( 'wpress-restore-status' );
		if ( ! box ) return;
		var current = box.querySelector( '.wpress-restore-status-current' );
		if ( current ) {
			current.textContent = isError ? 'Error' : 'Completed';
			current.className = 'wpress-restore-status-current ' + ( isError ? 'error' : 'done' );
		}
		var log = box.querySelector( '.wpress-restore-status-log' );
		if ( log && message ) {
			var li = document.createElement( 'li' );
			li.className = isError ? 'error' : 'done';
			li.textContent = message;
			log.appendChild( li );
		}
	}

	function setFormDisabled( form, disabled ) {
		var inputs = form.querySelectorAll( 'input, button' );
		inputs.forEach( function ( el ) {
			el.disabled = disabled;
		});
	}

	function runRestoreStream( form, formData, container ) {
		formData.append( 'action', 'wpress_restore_stream' );
		formData.append( '_wpnonce', nonce );

		showStatus( container, 'Starting restore…', 'Connecting…' );

		fetch( streamUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin'
		}).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'Request failed: ' + response.status );
			}
			return response.body.getReader();
		}).then( function ( reader ) {
			var decoder = new TextDecoder();
			var buffer = '';
			function readChunk() {
				return reader.read().then( function ( result ) {
					if ( result.done ) {
						if ( buffer.trim() ) {
							buffer.split( '\n' ).forEach( function ( line ) {
								line = line.trim();
								if ( line.indexOf( 'STEP:' ) === 0 ) {
									var rest = line.slice( 5 );
									var colon = rest.indexOf( ':' );
									var msg = colon >= 0 ? rest.slice( colon + 1 ) : rest;
									showStatus( container, msg, msg );
								} else if ( line.indexOf( 'DONE:' ) === 0 ) {
									showDone( container, line.slice( 5 ), false );
									setFormDisabled( form, false );
									if ( redirectUrl ) {
										setTimeout( function () {
											window.location.href = redirectUrl + '&wpress_message=' + encodeURIComponent( line.slice( 5 ) ) + '&wpress_success=1';
										}, 1500 );
									}
								} else if ( line.indexOf( 'ERROR:' ) === 0 ) {
									showDone( container, line.slice( 6 ), true );
									setFormDisabled( form, false );
								}
							});
						}
						return;
					}
					buffer += decoder.decode( result.value, { stream: true } );
					var lines = buffer.split( '\n' );
					buffer = lines.pop() || '';
					lines.forEach( function ( line ) {
						line = line.trim();
						if ( ! line ) return;
						if ( line.indexOf( 'STEP:' ) === 0 ) {
							var rest = line.slice( 5 );
							var colon = rest.indexOf( ':' );
							var msg = colon >= 0 ? rest.slice( colon + 1 ) : rest;
							showStatus( container, msg, msg );
						} else if ( line.indexOf( 'DONE:' ) === 0 ) {
							showDone( container, line.slice( 5 ), false );
							setFormDisabled( form, false );
							if ( redirectUrl ) {
								setTimeout( function () {
									window.location.href = redirectUrl + '&wpress_message=' + encodeURIComponent( line.slice( 5 ) ) + '&wpress_success=1';
								}, 1500 );
							}
						} else if ( line.indexOf( 'ERROR:' ) === 0 ) {
							showDone( container, line.slice( 6 ), true );
							setFormDisabled( form, false );
						}
					});
					return readChunk();
				});
			}
			return readChunk();
		}).catch( function ( err ) {
			showDone( container, err.message || 'Network or server error.', true );
			setFormDisabled( form, false );
		});
	}

	function init() {
		var wrap = document.querySelector( '.wpress-restore-wrap' );
		if ( ! wrap ) return;

		var forms = wrap.querySelectorAll( 'form[action*="admin-post.php"]' );
		forms.forEach( function ( form ) {
			var fileInput = form.querySelector( 'input[type="file"][name="wpress_file"]' );
			var pathInput = form.querySelector( 'input[name="wpress_path"]' );
			form.addEventListener( 'submit', function ( e ) {
				var actionInput = form.querySelector( 'input[name="action"]' );
				var action = actionInput ? actionInput.value : '';
				var isStream = action === 'wpress_restore_stream' || action === 'wpress_restore_path' || action === 'wpress_restore_upload';
				if ( ! isStream ) {
					return;
				}
				e.preventDefault();

				if ( action === 'wpress_restore_stream' ) {
					var selected = form.querySelector( 'input[name="selected_backup"]:checked' );
					var pathVal = form.querySelector( 'input[name="wpress_path"]' );
					if ( selected ) {
						// Restore from backup list — no extra validation.
					} else if ( pathVal && ( pathVal.value || '' ).trim() ) {
						var path = ( pathVal.value || '' ).trim();
						if ( path.indexOf( '/' ) !== 0 ) {
							alert( 'The path must be the full server path starting with /.' );
							pathVal.focus();
							return;
						}
					} else if ( ! selected && ( ! pathVal || ! ( pathVal.value || '' ).trim() ) ) {
						alert( 'Please select a backup from the list above.' );
						return;
					}
				}

				if ( fileInput ) {
					if ( ! fileInput.files || fileInput.files.length === 0 ) {
						alert( 'Please select a .wpress file to upload first.' );
						return;
					}
				} else if ( pathInput && action !== 'wpress_restore_stream' ) {
					var path = ( pathInput.value || '' ).trim();
					if ( ! path ) {
						alert( 'Please enter the full server path to your .wpress file.\n\nExample: /home/username/public_html/wp-content/uploads/2026/backup/yourfile.wpress' );
						pathInput.focus();
						return;
					}
					if ( path.indexOf( '/' ) !== 0 ) {
						alert( 'The path must be the full server path starting with / (e.g. /home/username/...).' );
						pathInput.focus();
						return;
					}
				}

				var formData = new FormData( form );
				setFormDisabled( form, true );
				runRestoreStream( form, formData, wrap );
			});
		});
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();
