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
			var isUpload = form.querySelector( 'input[type="file"][name="wpress_file"]' );
			form.addEventListener( 'submit', function ( e ) {
				var action = form.querySelector( 'input[name="action"]' );
				if ( ! action || ( action.value !== 'wpress_restore_path' && action.value !== 'wpress_restore_upload' ) ) {
					return;
				}
				e.preventDefault();
				setFormDisabled( form, true );
				var formData = new FormData( form );
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
