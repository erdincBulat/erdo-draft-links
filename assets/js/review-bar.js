/**
 * Review bar for draft link visitors: comments, notes pinned to elements on
 * the page, and approve / request-changes decisions.
 *
 * Everything lives in a Shadow DOM on a custom element mounted on <html>, so
 * theme styles can't reach in and the bar's styles can't leak out, and a
 * transform on <body> can't break its fixed positioning.
 */
( function () {
	'use strict';

	var settings = window.erdoDraftReview;
	if ( ! settings || ! window.fetch || ! document.documentElement.attachShadow ) {
		return;
	}

	var i18n = settings.i18n || {};
	var STORE_KEY = 'erdoDraftLinks.reviewer';
	var COLLAPSE_KEY = 'erdoDraftLinks.collapsed';

	var ICONS = {
		comment: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 4h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H9l-4 3.5V17a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>',
		pin: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 21s-6.5-5.6-6.5-11A6.5 6.5 0 0 1 18.5 10c0 5.4-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.4"/></svg>',
		check: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
		edit: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg>',
		down: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 9l6 6 6-6"/></svg>',
		close: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>'
	};

	var state = {
		items: [],
		review: null,
		loaded: false,
		panelOpen: false,
		picking: false,
		collapsed: readStore( COLLAPSE_KEY ) === '1'
	};

	// ------------------------------------------------------------------
	// Small helpers
	// ------------------------------------------------------------------

	function readStore( key ) {
		try {
			return window.localStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function writeStore( key, value ) {
		try {
			if ( null === value ) {
				window.localStorage.removeItem( key );
			} else {
				window.localStorage.setItem( key, value );
			}
		} catch ( e ) {}
	}

	function getReviewer() {
		try {
			var saved = JSON.parse( readStore( STORE_KEY ) || 'null' );
			return saved && saved.name ? saved : null;
		} catch ( e ) {
			return null;
		}
	}

	function setReviewer( reviewer ) {
		writeStore( STORE_KEY, reviewer ? JSON.stringify( reviewer ) : null );
	}

	function sprintf( format, value ) {
		return String( format || '' ).replace( /%[sd]/, value );
	}

	/**
	 * Creates an element. `attrs` keys starting with "on" become listeners;
	 * `html` sets innerHTML (only ever used for the static ICONS above).
	 */
	function h( tag, attrs ) {
		var el = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			var value = attrs[ key ];
			if ( null === value || undefined === value || false === value ) {
				return;
			}
			if ( 'html' === key ) {
				el.innerHTML = value;
			} else if ( 'text' === key ) {
				el.textContent = value;
			} else if ( 0 === key.indexOf( 'on' ) ) {
				el.addEventListener( key.slice( 2 ), value );
			} else if ( true === value ) {
				el.setAttribute( key, '' );
			} else {
				el.setAttribute( key, value );
			}
		} );
		for ( var i = 2; i < arguments.length; i++ ) {
			var child = arguments[ i ];
			if ( null === child || undefined === child || false === child ) {
				continue;
			}
			el.appendChild( 'string' === typeof child ? document.createTextNode( child ) : child );
		}
		return el;
	}

	function button( label, className, onClick, icon ) {
		var btn = h( 'button', { type: 'button', class: 'btn ' + ( className || '' ), onclick: onClick } );
		if ( icon ) {
			btn.appendChild( h( 'span', { class: 'icon', html: ICONS[ icon ] } ) );
		}
		btn.appendChild( h( 'span', { class: 'label', text: label } ) );
		return btn;
	}

	var rtf = null;
	try {
		rtf = new Intl.RelativeTimeFormat( settings.locale || undefined, { numeric: 'auto' } );
	} catch ( e ) {
		rtf = null;
	}

	function relative( iso ) {
		if ( ! iso ) {
			return '';
		}
		var diff = ( new Date( iso ).getTime() - Date.now() ) / 1000;
		var units = [ [ 'year', 31536000 ], [ 'month', 2592000 ], [ 'week', 604800 ], [ 'day', 86400 ], [ 'hour', 3600 ], [ 'minute', 60 ] ];
		for ( var i = 0; i < units.length; i++ ) {
			if ( Math.abs( diff ) >= units[ i ][ 1 ] ) {
				var n = Math.round( diff / units[ i ][ 1 ] );
				return rtf ? rtf.format( n, units[ i ][ 0 ] ) : new Date( iso ).toLocaleString();
			}
		}
		return rtf ? rtf.format( 0, 'second' ) : new Date( iso ).toLocaleString();
	}

	function api( path, body ) {
		var url = new URL( settings.restUrl + path, window.location.href );
		var opts = { credentials: 'same-origin', headers: {} };

		if ( settings.wpNonce ) {
			opts.headers[ 'X-WP-Nonce' ] = settings.wpNonce;
		}
		if ( body ) {
			body.nonce = settings.nonce;
			opts.method = 'POST';
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		} else {
			url.searchParams.set( 'nonce', settings.nonce );
		}

		return fetch( url.toString(), opts ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( ( data && data.message ) || i18n.error );
				}
				return data;
			} );
		} );
	}

	// ------------------------------------------------------------------
	// Shadow root and static structure
	// ------------------------------------------------------------------

	var host = document.createElement( 'erdo-review-bar' );
	host.style.setProperty( 'display', 'none', 'important' );
	var root = host.attachShadow( { mode: 'open' } );

	var stylesheet = h( 'link', { rel: 'stylesheet', href: settings.cssUrl } );
	var showHost = function () {
		host.style.removeProperty( 'display' );
		updateSpacer();
		schedulePins();
	};
	stylesheet.addEventListener( 'load', showHost );
	stylesheet.addEventListener( 'error', showHost );
	root.appendChild( stylesheet );

	var pinLayer = h( 'div', { class: 'pins' } );
	var pickLayer = h( 'div', { class: 'pick', hidden: true } );
	var highlight = h( 'div', { class: 'highlight', hidden: true } );
	var hint = h( 'div', { class: 'hint', hidden: true, role: 'status' } );
	var popover = h( 'div', { class: 'popover', hidden: true } );
	var toast = h( 'div', { class: 'toast', role: 'status', 'aria-live': 'polite' } );

	// Comments panel.
	var listEl = h( 'ol', { class: 'thread' } );
	var emptyEl = h( 'p', { class: 'empty', text: i18n.empty } );
	// Editors can leave a "what changed" note for reviewers coming back.
	var noteEl = settings.changesNote
		? h( 'section', { class: 'note' }, h( 'h3', { class: 'note__title', text: i18n.whatChanged } ), h( 'p', { class: 'note__text', text: settings.changesNote } ) )
		: h( 'span', { hidden: true } );
	var composer = h( 'form', { class: 'composer', novalidate: true } );
	var panel = h(
		'section',
		{ class: 'panel', hidden: true, 'aria-labelledby': 'erdo-panel-title', tabindex: '-1' },
		h(
			'header',
			{ class: 'panel__head' },
			h( 'h2', { id: 'erdo-panel-title', text: i18n.comments } ),
			h( 'button', { type: 'button', class: 'icon-btn', 'aria-label': i18n.close, html: ICONS.close, onclick: closePanel } )
		),
		h( 'div', { class: 'panel__body' }, noteEl, emptyEl, listEl ),
		composer
	);

	// Approve / request changes dialog.
	var dialogWrap = h( 'div', { class: 'backdrop', hidden: true } );

	// The bar.
	var countEl = h( 'span', { class: 'count', hidden: true } );
	var chipEl = h( 'span', { class: 'chip', hidden: true } );
	var commentsBtn = button( i18n.comments, 'ghost', togglePanel, 'comment' );
	commentsBtn.appendChild( countEl );
	commentsBtn.setAttribute( 'aria-expanded', 'false' );
	var noteBtn = button( i18n.addNote, 'ghost', function () {
		startPicking();
	}, 'pin' );
	var changesBtn = button( i18n.requestChanges, 'secondary', function () {
		openDecision( 'changes_requested' );
	}, 'edit' );
	var approveBtn = button( i18n.approve, 'primary', function () {
		openDecision( 'approved' );
	}, 'check' );

	var info = h(
		'div',
		{ class: 'bar__info' },
		h( 'span', { class: 'badge', text: settings.isPublished ? i18n.preview : i18n.draftPreview } ),
		h( 'span', { class: 'site', text: settings.site } ),
		settings.expiresAt ? h( 'span', { class: 'expiry', text: sprintf( i18n.expires, relative( settings.expiresAt ) ) } ) : null,
		chipEl
	);

	var bar = h(
		'div',
		{ class: 'bar', role: 'region', 'aria-label': i18n.reviewBar },
		info,
		h(
			'div',
			{ class: 'bar__actions' },
			commentsBtn,
			noteBtn,
			changesBtn,
			approveBtn,
			h( 'button', { type: 'button', class: 'icon-btn collapse', 'aria-label': i18n.collapse, title: i18n.collapse, html: ICONS.down, onclick: function () {
				setCollapsed( true );
			} } )
		)
	);

	var fabCount = h( 'span', { class: 'count', hidden: true } );
	var fab = h(
		'button',
		{ type: 'button', class: 'fab', hidden: true, onclick: function () {
			setCollapsed( false );
		} },
		h( 'span', { class: 'icon', html: ICONS.comment } ),
		h( 'span', { text: i18n.review } ),
		fabCount
	);

	root.appendChild( h( 'div', { class: 'layer' }, pinLayer, highlight, pickLayer, hint, popover, panel, dialogWrap, toast, bar, fab ) );

	// Keeps the end of the page reachable above the fixed bar.
	var spacer = h( 'div', { 'aria-hidden': 'true', 'data-erdo-review-spacer': '' } );

	function updateSpacer() {
		var height = state.collapsed || 'none' === host.style.display ? 0 : bar.offsetHeight;
		spacer.style.setProperty( 'height', height + 'px', 'important' );
	}

	// ------------------------------------------------------------------
	// Toasts and focus management
	// ------------------------------------------------------------------

	var toastTimer = null;

	function notify( message, isError ) {
		toast.textContent = message;
		toast.className = 'toast is-visible' + ( isError ? ' is-error' : '' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () {
			toast.className = 'toast';
		}, 4000 );
	}

	function focusables( container ) {
		return Array.prototype.filter.call(
			container.querySelectorAll( 'button, [href], input, textarea, select, [tabindex]:not([tabindex="-1"])' ),
			function ( el ) {
				return ! el.disabled && ! el.closest( '[hidden]' );
			}
		);
	}

	function trapFocus( event, container ) {
		if ( 'Tab' !== event.key ) {
			return;
		}
		var list = focusables( container );
		if ( ! list.length ) {
			return;
		}
		var first = list[ 0 ];
		var last = list[ list.length - 1 ];
		var active = root.activeElement;
		if ( event.shiftKey && active === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && active === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	// ------------------------------------------------------------------
	// Name / email fields, shared by every form
	// ------------------------------------------------------------------

	/**
	 * Name (+ optional email) inputs the first time; afterwards a short
	 * "Commenting as …" line with a way to change it.
	 */
	function identityFields( uid ) {
		var reviewer = getReviewer();
		var wrap = h( 'div', { class: 'identity' } );

		if ( reviewer ) {
			wrap.appendChild(
				h(
					'p',
					{ class: 'identity__as' },
					sprintf( i18n.commentingAs, reviewer.name ) + ' ',
					h( 'button', { type: 'button', class: 'link', text: i18n.changeName, onclick: function () {
						setReviewer( null );
						wrap.replaceWith( identityFields( uid ) );
					} } )
				)
			);
			return wrap;
		}

		wrap.appendChild(
			h(
				'div',
				{ class: 'field-row' },
				h(
					'label',
					{ class: 'field' },
					h( 'span', { text: i18n.yourName } ),
					h( 'input', { type: 'text', name: 'name', maxlength: '100', autocomplete: 'name', required: true } )
				),
				h(
					'label',
					{ class: 'field' },
					h( 'span', { text: i18n.yourEmail } ),
					h( 'input', { type: 'email', name: 'email', maxlength: '100', autocomplete: 'email', 'aria-describedby': uid + '-email-help' } )
				)
			)
		);
		wrap.appendChild( h( 'p', { class: 'help', id: uid + '-email-help', text: i18n.emailHelp } ) );
		return wrap;
	}

	/**
	 * @return {{name: string, email: string}|null} null after flagging the empty name field.
	 */
	function readIdentity( form ) {
		var saved = getReviewer();
		if ( saved ) {
			return saved;
		}
		var nameInput = form.querySelector( 'input[name="name"]' );
		var emailInput = form.querySelector( 'input[name="email"]' );
		var name = nameInput ? nameInput.value.trim() : '';
		if ( ! name ) {
			nameInput.setAttribute( 'aria-invalid', 'true' );
			nameInput.focus();
			return null;
		}
		var reviewer = { name: name, email: emailInput && emailInput.validity.valid ? emailInput.value.trim() : '' };
		setReviewer( reviewer );
		return reviewer;
	}

	function setBusy( btn, busy ) {
		btn.disabled = busy;
		btn.classList.toggle( 'is-busy', busy );
	}

	// ------------------------------------------------------------------
	// Comments panel
	// ------------------------------------------------------------------

	function noteNumber( item ) {
		var notes = state.items.filter( function ( i ) {
			return 'annotation' === i.type;
		} );
		return notes.indexOf( item ) + 1;
	}

	function renderComposer() {
		composer.textContent = '';
		var textarea = h( 'textarea', { name: 'message', rows: '3', maxlength: '5000', placeholder: i18n.commentPlaceholder, 'aria-label': i18n.commentLabel, required: true } );
		var submit = h( 'button', { type: 'submit', class: 'btn primary', text: i18n.send } );

		composer.appendChild( identityFields( 'composer' ) );
		composer.appendChild( textarea );
		composer.appendChild( h( 'div', { class: 'actions' }, submit ) );

		composer.onsubmit = function ( event ) {
			event.preventDefault();
			var message = textarea.value.trim();
			if ( ! message ) {
				textarea.focus();
				return;
			}
			var reviewer = readIdentity( composer );
			if ( ! reviewer ) {
				return;
			}
			setBusy( submit, true );
			api( 'feedback', { type: 'comment', name: reviewer.name, email: reviewer.email, message: message } )
				.then( function ( item ) {
					state.items.push( item );
					renderThread();
					renderComposer();
					notify( i18n.thanksComment );
					listEl.lastElementChild && listEl.lastElementChild.scrollIntoView( { block: 'nearest' } );
				} )
				.catch( function ( err ) {
					notify( err.message, true );
					setBusy( submit, false );
				} );
		};
	}

	function renderReply( reply ) {
		return h(
			'li',
			{ class: 'reply' + ( reply.is_staff ? ' is-staff' : '' ) },
			h(
				'div',
				{ class: 'meta' },
				h( 'strong', { text: reply.author_name } ),
				reply.is_staff ? h( 'span', { class: 'tag tag--team', text: i18n.team } ) : null,
				h( 'time', { datetime: reply.created_at, text: relative( reply.created_at ) } )
			),
			h( 'p', { class: 'message', text: reply.message } )
		);
	}

	function renderItem( item ) {
		var tags = h( 'div', { class: 'tags' } );

		if ( 'annotation' === item.type ) {
			tags.appendChild(
				h( 'button', { type: 'button', class: 'tag tag--note', text: sprintf( i18n.noteN, noteNumber( item ) ), title: i18n.showOnPage, onclick: function () {
					showOnPage( item );
				} } )
			);
		} else if ( 'approval' === item.type ) {
			tags.appendChild( h( 'span', { class: 'tag tag--approved', text: i18n.approvedItem } ) );
		} else if ( 'changes_requested' === item.type ) {
			tags.appendChild( h( 'span', { class: 'tag tag--changes', text: i18n.changesItem } ) );
		}
		if ( 'completed' === item.status && 'approval' !== item.type ) {
			tags.appendChild( h( 'span', { class: 'tag tag--resolved', text: i18n.resolved } ) );
		}

		var replies = h( 'ol', { class: 'replies' } );
		( item.replies || [] ).forEach( function ( reply ) {
			replies.appendChild( renderReply( reply ) );
		} );

		var li = h(
			'li',
			{ class: 'item' + ( 'completed' === item.status ? ' is-resolved' : '' ), 'data-id': String( item.id ) },
			h(
				'div',
				{ class: 'meta' },
				h( 'strong', { text: item.author_name } ),
				h( 'time', { datetime: item.created_at, text: relative( item.created_at ) } )
			),
			tags.childNodes.length ? tags : null,
			item.message ? h( 'p', { class: 'message', text: item.message } ) : null,
			item.replies && item.replies.length ? replies : null
		);

		var replyBtn = h( 'button', { type: 'button', class: 'link', text: i18n.reply } );
		replyBtn.addEventListener( 'click', function () {
			replyBtn.replaceWith( replyForm( item, li ) );
		} );
		li.appendChild( replyBtn );

		return li;
	}

	function replyForm( item, li ) {
		var form = h( 'form', { class: 'reply-form', novalidate: true } );
		var textarea = h( 'textarea', { rows: '2', maxlength: '5000', 'aria-label': i18n.reply, required: true } );
		var submit = h( 'button', { type: 'submit', class: 'btn primary small', text: i18n.sendReply } );

		form.appendChild( identityFields( 'reply-' + item.id ) );
		form.appendChild( textarea );
		form.appendChild(
			h(
				'div',
				{ class: 'actions' },
				h( 'button', { type: 'button', class: 'btn ghost small', text: i18n.cancel, onclick: renderThread } ),
				submit
			)
		);

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var message = textarea.value.trim();
			if ( ! message ) {
				textarea.focus();
				return;
			}
			var reviewer = readIdentity( form );
			if ( ! reviewer ) {
				return;
			}
			setBusy( submit, true );
			api( 'feedback', { parent_id: item.id, name: reviewer.name, email: reviewer.email, message: message } )
				.then( function ( reply ) {
					item.replies = ( item.replies || [] ).concat( reply );
					renderThread();
					var updated = listEl.querySelector( '[data-id="' + item.id + '"]' );
					updated && updated.scrollIntoView( { block: 'nearest' } );
				} )
				.catch( function ( err ) {
					notify( err.message, true );
					setBusy( submit, false );
				} );
		} );

		setTimeout( function () {
			textarea.focus();
		} );
		return form;
	}

	function renderThread() {
		listEl.textContent = '';
		state.items.forEach( function ( item ) {
			listEl.appendChild( renderItem( item ) );
		} );
		emptyEl.hidden = ! state.loaded || state.items.length > 0;
		renderCounts();
		renderPins();
	}

	function renderCounts() {
		var open = state.items.filter( function ( i ) {
			return 'approval' !== i.type && 'completed' !== i.status;
		} ).length;
		[ countEl, fabCount ].forEach( function ( el ) {
			el.textContent = String( open );
			el.hidden = 0 === open;
		} );
	}

	function renderReviewChip() {
		var review = state.review;
		if ( ! review || 'pending' === review.review_status ) {
			chipEl.hidden = true;
			return;
		}
		var approved = 'approved' === review.review_status;
		chipEl.hidden = false;
		chipEl.className = 'chip ' + ( review.is_stale ? 'chip--stale' : approved ? 'chip--approved' : 'chip--changes' );
		chipEl.textContent = sprintf( approved ? i18n.approvedBy : i18n.changesBy, review.reviewer_name ) + ( review.is_stale ? ' · ' + i18n.editedSince : '' );
	}

	var lastFocus = null;

	function openPanel( focusItemId ) {
		cancelPicking();
		state.panelOpen = true;
		panel.hidden = false;
		commentsBtn.setAttribute( 'aria-expanded', 'true' );
		if ( focusItemId ) {
			var el = listEl.querySelector( '[data-id="' + focusItemId + '"]' );
			if ( el ) {
				el.scrollIntoView( { block: 'center' } );
				el.classList.remove( 'is-flash' );
				void el.offsetWidth;
				el.classList.add( 'is-flash' );
			}
		} else {
			var textarea = composer.querySelector( 'textarea' );
			( getReviewer() ? textarea : composer.querySelector( 'input' ) || textarea ).focus();
		}
	}

	function closePanel() {
		state.panelOpen = false;
		panel.hidden = true;
		commentsBtn.setAttribute( 'aria-expanded', 'false' );
		commentsBtn.focus();
	}

	function togglePanel() {
		if ( state.panelOpen ) {
			closePanel();
		} else {
			openPanel();
		}
	}

	// ------------------------------------------------------------------
	// Approve / request changes
	// ------------------------------------------------------------------

	function openDecision( decision ) {
		cancelPicking();
		lastFocus = root.activeElement;
		var approving = 'approved' === decision;
		var textarea = h( 'textarea', { name: 'message', rows: '4', maxlength: '5000', id: 'erdo-decision-message', required: ! approving } );
		var submit = h( 'button', { type: 'submit', class: 'btn primary', text: approving ? i18n.approve : i18n.requestChanges } );
		var form = h(
			'form',
			{ class: 'dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'erdo-dialog-title', 'aria-describedby': 'erdo-dialog-help', novalidate: true },
			h( 'h2', { id: 'erdo-dialog-title', text: approving ? i18n.approveTitle : i18n.changesTitle } ),
			h( 'p', { id: 'erdo-dialog-help', class: 'help', text: approving ? i18n.approveHelp : i18n.changesHelp } ),
			identityFields( 'dialog' ),
			h( 'label', { class: 'field', for: 'erdo-decision-message' }, h( 'span', { text: approving ? i18n.approveMessage : i18n.changesMessage } ) ),
			textarea,
			h(
				'div',
				{ class: 'actions' },
				h( 'button', { type: 'button', class: 'btn ghost', text: i18n.cancel, onclick: closeDecision } ),
				submit
			)
		);

		form.addEventListener( 'keydown', function ( event ) {
			trapFocus( event, form );
		} );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var message = textarea.value.trim();
			var reviewer = readIdentity( form );
			if ( ! reviewer ) {
				return;
			}
			if ( ! approving && ! message ) {
				textarea.setAttribute( 'aria-invalid', 'true' );
				textarea.focus();
				return;
			}
			setBusy( submit, true );
			api( 'review', { decision: decision, name: reviewer.name, email: reviewer.email, message: message } )
				.then( function ( review ) {
					state.review = review;
					renderReviewChip();
					closeDecision();
					notify( approving ? i18n.thanksApproved : i18n.thanksChanges );
					return loadThread();
				} )
				.catch( function ( err ) {
					notify( err.message, true );
					setBusy( submit, false );
				} );
		} );

		dialogWrap.textContent = '';
		dialogWrap.appendChild( form );
		dialogWrap.hidden = false;
		var first = form.querySelector( 'input' ) || textarea;
		first.focus();
	}

	function closeDecision() {
		dialogWrap.hidden = true;
		dialogWrap.textContent = '';
		if ( lastFocus && lastFocus.focus ) {
			lastFocus.focus();
		}
	}

	dialogWrap.addEventListener( 'mousedown', function ( event ) {
		if ( event.target === dialogWrap ) {
			closeDecision();
		}
	} );

	// ------------------------------------------------------------------
	// Notes on the page: pick an element, then write a note anchored to it
	// by a CSS selector plus the click position inside it (in percent).
	// ------------------------------------------------------------------

	var hovered = null;

	/**
	 * A selector for the element: an :nth-child path up to the nearest
	 * ancestor with a unique id (or <body>), so it survives most edits
	 * elsewhere on the page.
	 */
	function selectorFor( el ) {
		var path = [];
		var node = el;
		while ( node && 1 === node.nodeType && node !== document.body && node !== document.documentElement ) {
			if ( node.id && /^[A-Za-z][\w-]*$/.test( node.id ) && 1 === document.querySelectorAll( '#' + node.id ).length ) {
				path.unshift( '#' + node.id );
				return path.join( ' > ' );
			}
			var index = 1;
			var sibling = node.previousElementSibling;
			while ( sibling ) {
				index++;
				sibling = sibling.previousElementSibling;
			}
			path.unshift( node.tagName.toLowerCase() + ':nth-child(' + index + ')' );
			node = node.parentElement;
		}
		path.unshift( 'body' );
		return path.join( ' > ' );
	}

	function elementAt( x, y ) {
		pickLayer.style.pointerEvents = 'none';
		var el = document.elementFromPoint( x, y );
		pickLayer.style.pointerEvents = '';
		return el && el !== host && el !== document.documentElement && el !== document.body ? el : null;
	}

	function moveHighlight( el ) {
		if ( ! el ) {
			highlight.hidden = true;
			return;
		}
		var rect = el.getBoundingClientRect();
		highlight.hidden = false;
		highlight.style.left = rect.left + 'px';
		highlight.style.top = rect.top + 'px';
		highlight.style.width = rect.width + 'px';
		highlight.style.height = rect.height + 'px';
	}

	function startPicking() {
		if ( state.panelOpen ) {
			state.panelOpen = false;
			panel.hidden = true;
			commentsBtn.setAttribute( 'aria-expanded', 'false' );
		}
		closePopover();
		state.picking = true;
		pickLayer.hidden = false;
		hint.hidden = false;
		hint.textContent = i18n.noteHint;
		noteBtn.classList.add( 'is-active' );
		noteBtn.setAttribute( 'aria-pressed', 'true' );
	}

	function cancelPicking() {
		state.picking = false;
		pickLayer.hidden = true;
		hint.hidden = true;
		highlight.hidden = true;
		hovered = null;
		noteBtn.classList.remove( 'is-active' );
		noteBtn.removeAttribute( 'aria-pressed' );
	}

	pickLayer.addEventListener( 'mousemove', function ( event ) {
		hovered = elementAt( event.clientX, event.clientY );
		moveHighlight( hovered );
	} );

	pickLayer.addEventListener( 'click', function ( event ) {
		event.preventDefault();
		var target = elementAt( event.clientX, event.clientY );
		if ( ! target ) {
			return;
		}
		var rect = target.getBoundingClientRect();
		var anchor = {
			selector: selectorFor( target ),
			x_percent: rect.width ? Math.min( 100, Math.max( 0, ( ( event.clientX - rect.left ) / rect.width ) * 100 ) ) : 0,
			y_percent: rect.height ? Math.min( 100, Math.max( 0, ( ( event.clientY - rect.top ) / rect.height ) * 100 ) ) : 0
		};
		cancelPicking();
		moveHighlight( target );
		openNoteForm( anchor, target, event.clientX, event.clientY );
	} );

	function placeAt( el, x, y ) {
		var margin = 12;
		var rect = el.getBoundingClientRect();
		var maxX = Math.max( margin, window.innerWidth - rect.width - margin );
		var maxY = Math.max( margin, window.innerHeight - rect.height - bar.offsetHeight - margin );
		el.style.left = Math.min( Math.max( x, margin ), maxX ) + 'px';
		el.style.top = Math.min( Math.max( y, margin ), maxY ) + 'px';
	}

	function openNoteForm( anchor, target, x, y ) {
		lastFocus = noteBtn;
		var textarea = h( 'textarea', { rows: '3', maxlength: '5000', placeholder: i18n.notePlaceholder, 'aria-label': i18n.notePlaceholder, required: true } );
		var submit = h( 'button', { type: 'submit', class: 'btn primary small', text: i18n.send } );
		var form = h(
			'form',
			{ class: 'note-form', role: 'dialog', 'aria-label': i18n.addNote, novalidate: true },
			identityFields( 'note' ),
			textarea,
			h(
				'div',
				{ class: 'actions' },
				h( 'button', { type: 'button', class: 'btn ghost small', text: i18n.cancel, onclick: closePopover } ),
				submit
			)
		);

		form.addEventListener( 'keydown', function ( event ) {
			trapFocus( event, form );
		} );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var message = textarea.value.trim();
			if ( ! message ) {
				textarea.focus();
				return;
			}
			var reviewer = readIdentity( form );
			if ( ! reviewer ) {
				return;
			}
			setBusy( submit, true );
			api(
				'feedback',
				{
					type: 'annotation',
					name: reviewer.name,
					email: reviewer.email,
					message: message,
					selector: anchor.selector,
					x_percent: anchor.x_percent,
					y_percent: anchor.y_percent,
					viewport: window.innerWidth + 'x' + window.innerHeight
				}
			)
				.then( function ( item ) {
					state.items.push( item );
					closePopover();
					renderThread();
					notify( i18n.thanksNote );
				} )
				.catch( function ( err ) {
					notify( err.message, true );
					setBusy( submit, false );
				} );
		} );

		popover.textContent = '';
		popover.appendChild( form );
		popover.hidden = false;
		placeAt( popover, x + 8, y + 8 );
		( form.querySelector( 'input' ) || textarea ).focus();
	}

	function closePopover() {
		if ( popover.hidden ) {
			return;
		}
		popover.hidden = true;
		popover.textContent = '';
		highlight.hidden = true;
		if ( lastFocus && lastFocus.focus ) {
			lastFocus.focus();
		}
	}

	// ------------------------------------------------------------------
	// Pins: one numbered marker per note, kept over its element.
	// ------------------------------------------------------------------

	var pins = [];

	function findTarget( item ) {
		try {
			return document.querySelector( item.selector );
		} catch ( e ) {
			return null;
		}
	}

	function renderPins() {
		pinLayer.textContent = '';
		pins = [];
		state.items.forEach( function ( item ) {
			if ( 'annotation' !== item.type || ! item.selector ) {
				return;
			}
			var number = noteNumber( item );
			var pin = h( 'button', {
				type: 'button',
				class: 'pin' + ( 'completed' === item.status ? ' is-resolved' : '' ),
				text: String( number ),
				'aria-label': sprintf( i18n.noteN, number ) + ': ' + item.message,
				onclick: function () {
					openPanel( item.id );
				}
			} );
			pinLayer.appendChild( pin );
			pins.push( { item: item, el: pin } );
		} );
		positionPins();
	}

	function positionPins() {
		pins.forEach( function ( pin ) {
			var target = findTarget( pin.item );
			var rect = target ? target.getBoundingClientRect() : null;
			if ( ! rect || ( ! rect.width && ! rect.height ) ) {
				pin.el.hidden = true;
				return;
			}
			pin.el.hidden = false;
			pin.el.style.left = rect.left + ( pin.item.x_percent / 100 ) * rect.width + 'px';
			pin.el.style.top = rect.top + ( pin.item.y_percent / 100 ) * rect.height + 'px';
		} );
	}

	var pinsScheduled = false;

	function schedulePins() {
		if ( pinsScheduled ) {
			return;
		}
		pinsScheduled = true;
		window.requestAnimationFrame( function () {
			pinsScheduled = false;
			positionPins();
			if ( ! highlight.hidden && hovered ) {
				moveHighlight( hovered );
			}
		} );
	}

	function showOnPage( item ) {
		var target = findTarget( item );
		if ( ! target ) {
			notify( i18n.notFound, true );
			return;
		}
		if ( window.innerWidth < 720 ) {
			closePanel();
		}
		var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		target.scrollIntoView( { block: 'center', behavior: reduce ? 'auto' : 'smooth' } );
		pins.forEach( function ( pin ) {
			if ( pin.item === item ) {
				pin.el.classList.remove( 'is-flash' );
				void pin.el.offsetWidth;
				pin.el.classList.add( 'is-flash' );
			}
		} );
	}

	// ------------------------------------------------------------------
	// Collapse
	// ------------------------------------------------------------------

	function setCollapsed( collapsed ) {
		state.collapsed = collapsed;
		writeStore( COLLAPSE_KEY, collapsed ? '1' : null );
		bar.hidden = collapsed;
		fab.hidden = ! collapsed;
		if ( collapsed ) {
			cancelPicking();
			closePopover();
			if ( state.panelOpen ) {
				state.panelOpen = false;
				panel.hidden = true;
			}
			pinLayer.hidden = true;
			fab.focus();
		} else {
			pinLayer.hidden = false;
			schedulePins();
			commentsBtn.focus();
		}
		updateSpacer();
	}

	// ------------------------------------------------------------------
	// Global keys, scroll tracking, boot
	// ------------------------------------------------------------------

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key ) {
			return;
		}
		if ( ! dialogWrap.hidden ) {
			closeDecision();
		} else if ( ! popover.hidden ) {
			closePopover();
		} else if ( state.picking ) {
			cancelPicking();
			noteBtn.focus();
		} else if ( state.panelOpen ) {
			closePanel();
		}
	} );

	function loadThread() {
		return api( 'thread' )
			.then( function ( data ) {
				state.items = data.items || [];
				state.review = data.review || null;
				state.loaded = true;
				renderThread();
				renderReviewChip();
			} )
			.catch( function ( err ) {
				state.loaded = true;
				notify( err.message || i18n.loadError, true );
			} );
	}

	function boot() {
		document.documentElement.appendChild( host );
		document.body.appendChild( spacer );

		bar.hidden = state.collapsed;
		fab.hidden = ! state.collapsed;
		pinLayer.hidden = state.collapsed;

		renderComposer();
		loadThread();

		window.addEventListener( 'scroll', schedulePins, { passive: true, capture: true } );
		window.addEventListener( 'resize', function () {
			schedulePins();
			updateSpacer();
		} );
		window.addEventListener( 'load', schedulePins );
		if ( window.ResizeObserver ) {
			new ResizeObserver( schedulePins ).observe( document.body );
			new ResizeObserver( updateSpacer ).observe( bar );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
