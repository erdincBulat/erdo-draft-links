( function () {
	var ICONS = {
		success: '<svg class="erdo-draft-feedback-success-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 6 9 17l-5-5"></path></svg>',
		error: '<svg class="erdo-draft-feedback-error-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>'
	};

	function init() {
		var widget = document.querySelector( '.erdo-draft-feedback' );

		if ( ! widget ) {
			return;
		}

		// Some themes/page builders apply a transform/filter/perspective to an
		// ancestor (often the footer), which creates a new containing block and
		// breaks position: fixed relative to the viewport. Re-parent the widget
		// to <body> so it stays pinned regardless of page structure.
		if ( widget.parentNode !== document.body ) {
			document.body.appendChild( widget );
		}

		var toggle = widget.querySelector( '.erdo-draft-feedback-toggle' );
		var panel  = widget.querySelector( '.erdo-draft-feedback-panel' );
		var close  = widget.querySelector( '.erdo-draft-feedback-close' );

		if ( ! toggle || ! panel ) {
			return;
		}

		function openPanel() {
			panel.removeAttribute( 'hidden' );
			// Force layout so the opacity/transform transition runs.
			// eslint-disable-next-line no-unused-expressions
			panel.offsetHeight;
			panel.classList.add( 'is-open' );
			widget.setAttribute( 'data-open', 'true' );
			toggle.setAttribute( 'aria-expanded', 'true' );
		}

		function closePanel() {
			panel.classList.remove( 'is-open' );
			widget.setAttribute( 'data-open', 'false' );
			toggle.setAttribute( 'aria-expanded', 'false' );

			var hidePanel = function () {
				panel.setAttribute( 'hidden', '' );
			};

			panel.addEventListener( 'transitionend', hidePanel, { once: true } );
			// Fallback in case transitionend doesn't fire (e.g. reduced motion).
			setTimeout( hidePanel, 250 );
		}

		toggle.addEventListener( 'click', function () {
			if ( panel.classList.contains( 'is-open' ) ) {
				closePanel();
			} else {
				openPanel();
			}
		} );

		if ( close ) {
			close.addEventListener( 'click', closePanel );
		}

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && panel.classList.contains( 'is-open' ) ) {
				closePanel();
				toggle.focus();
			}
		} );

		// ------------------------------------------------------------------
		// Feedback form — submit via REST so the panel updates instantly
		// (new entry appears in "Past Feedback" without a page reload) and
		// stays open/usable for additional submissions.
		// ------------------------------------------------------------------

		var settings = window.erdoDraftFeedback || {};
		var i18n     = settings.i18n || {};

		var form        = widget.querySelector( '#erdo-draft-feedback-form' );
		var notice      = widget.querySelector( '#erdo-draft-feedback-notice' );
		var historyWrap = widget.querySelector( '#erdo-draft-feedback-history' );
		var historyList = widget.querySelector( '#erdo-draft-feedback-history-list' );
		var submitBtn   = form ? form.querySelector( '.erdo-draft-feedback-submit' ) : null;
		var submitLabel = submitBtn ? submitBtn.querySelector( '.erdo-draft-feedback-submit-label' ) : null;

		function autoDismiss( el, delay ) {
			setTimeout( function () {
				el.style.transition = 'opacity 0.3s ease';
				el.style.opacity    = '0';
				setTimeout( function () {
					el.remove();
				}, 300 );
			}, delay );
		}

		function showNotice( type, message ) {
			if ( ! notice ) {
				return;
			}

			notice.innerHTML = '';

			var box = document.createElement( 'div' );
			box.className = 'erdo-draft-feedback-' + type;
			box.setAttribute( 'role', 'status' );
			box.innerHTML = ICONS[ type ] || '';

			var span = document.createElement( 'span' );
			span.textContent = message;
			box.appendChild( span );

			notice.appendChild( box );
			autoDismiss( box, 4000 );
		}

		function buildHistoryItem( item ) {
			var li = document.createElement( 'li' );
			li.className = 'erdo-draft-feedback-history-item';

			var head = document.createElement( 'div' );
			head.className = 'erdo-draft-feedback-history-head';

			var authorRow = document.createElement( 'span' );
			authorRow.className = 'erdo-draft-feedback-history-author-row';

			var avatar = document.createElement( 'span' );
			avatar.className = 'erdo-draft-feedback-history-avatar';
			avatar.setAttribute( 'aria-hidden', 'true' );
			avatar.textContent = item.initial || '';

			var author = document.createElement( 'span' );
			author.className = 'erdo-draft-feedback-history-author';
			author.textContent = item.author_name || '';

			authorRow.appendChild( avatar );
			authorRow.appendChild( author );

			var status = document.createElement( 'span' );
			status.className = 'erdo-draft-feedback-status erdo-draft-feedback-status--' + ( item.status || '' );
			status.textContent = item.status_label || '';

			head.appendChild( authorRow );
			head.appendChild( status );

			var message = document.createElement( 'p' );
			message.className = 'erdo-draft-feedback-history-message';
			message.textContent = item.message || '';

			var date = document.createElement( 'span' );
			date.className = 'erdo-draft-feedback-history-date';
			date.textContent = item.date || '';

			li.appendChild( head );
			li.appendChild( message );
			li.appendChild( date );

			return li;
		}

		function addHistoryItem( item ) {
			if ( ! historyList || ! item ) {
				return;
			}
			historyList.insertBefore( buildHistoryItem( item ), historyList.firstChild );
			if ( historyWrap ) {
				historyWrap.removeAttribute( 'hidden' );
			}
		}

		function setSubmitting( isSubmitting ) {
			if ( ! submitBtn ) {
				return;
			}
			submitBtn.disabled = isSubmitting;

			if ( isSubmitting ) {
				if ( submitLabel ) {
					submitLabel.textContent = i18n.sending || submitLabel.textContent;
				}
				if ( ! submitBtn.querySelector( '.erdo-draft-feedback-spinner' ) ) {
					var spinner = document.createElement( 'span' );
					spinner.className = 'erdo-draft-feedback-spinner';
					submitBtn.insertBefore( spinner, submitBtn.firstChild );
				}
			} else {
				if ( submitLabel && i18n.submit ) {
					submitLabel.textContent = i18n.submit;
				}
				var existingSpinner = submitBtn.querySelector( '.erdo-draft-feedback-spinner' );
				if ( existingSpinner ) {
					existingSpinner.remove();
				}
			}
		}

		if ( form && settings.restUrl && window.fetch ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				var nameInput    = form.querySelector( '#erdo-feedback-name' );
				var messageInput = form.querySelector( '#erdo-feedback-message' );
				var nonceInput   = form.querySelector( '[name="erdo_feedback_nonce"]' );

				var name    = nameInput ? nameInput.value.trim() : '';
				var message = messageInput ? messageInput.value.trim() : '';

				if ( ! name || ! message ) {
					return;
				}

				setSubmitting( true );

				fetch( settings.restUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						name: name,
						message: message,
						nonce: nonceInput ? nonceInput.value : ''
					} )
				} )
					.then( function ( response ) {
						return response.json().then( function ( data ) {
							return { ok: response.ok, data: data };
						} );
					} )
					.then( function ( result ) {
						if ( ! result.ok || ! result.data || ! result.data.success ) {
							// eslint-disable-next-line no-console
							console.error( 'Erdo Draft Links feedback error:', result.data && result.data.code, result.data && result.data.message );
							throw new Error( ( result.data && result.data.message ) || i18n.error || '' );
						}

						addHistoryItem( result.data.item );
						showNotice( 'success', i18n.success || '' );
						form.reset();
						if ( nameInput ) {
							nameInput.focus();
						}
					} )
					.catch( function ( error ) {
						showNotice( 'error', error.message || i18n.error || '' );
					} )
					.then( function () {
						setSubmitting( false );
					} );
			} );
		}

		// After a non-JS submission the page reloads with ?erdo_feedback=sent
		// so the panel auto-opens with a server-rendered success message,
		// while the (now empty) form stays visible for another submission.
		if ( panel.hasAttribute( 'data-auto-open' ) ) {
			openPanel();

			var url = new URL( window.location.href );
			if ( url.searchParams.has( 'erdo_feedback' ) ) {
				url.searchParams.delete( 'erdo_feedback' );
				window.history.replaceState( {}, '', url.toString() );
			}

			var success = notice ? notice.querySelector( '.erdo-draft-feedback-success' ) : null;
			if ( success ) {
				autoDismiss( success, 4000 );
			}
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
