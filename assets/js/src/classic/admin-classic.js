/* global erdoDraftLinksClassicData */
( function () {
	'use strict';

	const { restUrl, nonce, i18n } = erdoDraftLinksClassicData;

	function apiFetch( path, method = 'GET', body = null ) {
		const opts = {
			method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
		};
		if ( body ) {
			opts.body = JSON.stringify( body );
		}
		return fetch( restUrl + path, opts ).then( ( res ) =>
			res.json().then( ( data ) => ( res.ok ? data : Promise.reject( data ) ) )
		);
	}

	function escAttr( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function renderRow( link ) {
		const expiresText = link.expires_at
			? i18n.expires.replace( '%s', link.expires_display )
			: i18n.noExpiry;
		const viewsText = i18n.views.replace( '%d', link.view_count );
		const label = link.label ? escHtml( link.label ) : escHtml( i18n.untitled );

		const row = document.createElement( 'div' );
		row.className = 'erdo-draft-links-link-row';
		row.dataset.linkId = link.id;
		row.innerHTML = `
			<div class="erdo-draft-links-link-header">
				<span class="erdo-draft-links-link-label">${ label }</span>
				<button type="button" class="button-link erdo-draft-links-revoke" data-link-id="${ link.id }">${ i18n.revoke }</button>
			</div>
			<div class="erdo-draft-links-url-row">
				<input type="text" readonly class="erdo-draft-links-url widefat" value="${ escAttr( link.url ) }" />
				<button type="button" class="button erdo-draft-links-copy">${ i18n.copy }</button>
			</div>
			<p class="description erdo-draft-links-meta">${ escHtml( expiresText ) }</p>
			<p class="description erdo-draft-links-meta">${ escHtml( viewsText ) }</p>
		`;
		return row;
	}

	function setLoading( wrap, loading ) {
		const spinner = wrap.querySelector( '.erdo-draft-links-spinner' );
		const buttons = wrap.querySelectorAll( 'button' );
		if ( loading ) {
			spinner.style.display = 'inline-block';
			buttons.forEach( ( b ) => b.setAttribute( 'disabled', 'disabled' ) );
		} else {
			spinner.style.display = 'none';
			buttons.forEach( ( b ) => b.removeAttribute( 'disabled' ) );
		}
	}

	function showError( wrap, msg ) {
		const notice = wrap.querySelector( '.erdo-draft-links-notice' );
		notice.textContent = msg;
		notice.style.display = 'block';
		notice.className = 'erdo-draft-links-notice notice notice-error';
	}

	function bindEvents( wrap ) {
		const postId = wrap.dataset.postId;
		const list = wrap.querySelector( '#erdo-draft-links-list' );

		// Rows are added/removed dynamically (generate/revoke), so copy/revoke
		// use delegated listeners on the list container instead of per-row binding.
		list.addEventListener( 'click', ( e ) => {
			const copyBtn = e.target.closest( '.erdo-draft-links-copy' );
			if ( copyBtn ) {
				const row = copyBtn.closest( '.erdo-draft-links-link-row' );
				const input = row.querySelector( '.erdo-draft-links-url' );
				navigator.clipboard
					.writeText( input.value )
					.catch( () => {
						input.select();
						document.execCommand( 'copy' );
					} );
				const orig = copyBtn.textContent;
				copyBtn.textContent = i18n.copied;
				setTimeout( () => ( copyBtn.textContent = orig ), 2000 );
				return;
			}

			const revokeBtn = e.target.closest( '.erdo-draft-links-revoke' );
			if ( revokeBtn ) {
				const linkId = Number( revokeBtn.dataset.linkId );
				setLoading( wrap, true );
				apiFetch( `/link/${ postId }/revoke`, 'POST', { link_id: linkId } )
					.then( () => {
						revokeBtn.closest( '.erdo-draft-links-link-row' ).remove();
						setLoading( wrap, false );
					} )
					.catch( ( err ) => {
						setLoading( wrap, false );
						showError( wrap, ( err && err.error ) || i18n.error );
					} );
			}
		} );

		const expirySelect      = wrap.querySelector( 'select[name="erdo_draft_link_expiry"]' );
		const customExpiryRow   = wrap.querySelector( '#erdo-draft-links-custom-expiry-row-classic' );
		const customExpiryInput = wrap.querySelector( '#erdo-draft-links-custom-expiry-classic' );
		if ( expirySelect && customExpiryRow ) {
			expirySelect.addEventListener( 'change', () => {
				customExpiryRow.style.display = 'custom' === expirySelect.value ? '' : 'none';
			} );
		}

		const generateBtn = wrap.querySelector( '.erdo-draft-links-generate' );
		if ( generateBtn ) {
			generateBtn.addEventListener( 'click', () => {
				const labelInput  = wrap.querySelector( '#erdo-draft-links-label-classic' );
				const expiry      = expirySelect ? expirySelect.value : '24h';
				const label       = labelInput ? labelInput.value : '';
				const customExpiry = customExpiryInput ? customExpiryInput.value : '';
				const passwordInput = wrap.querySelector( '#erdo-draft-links-password-classic' );
				const maxViewsInput = wrap.querySelector( '#erdo-draft-links-max-views-classic' );

				if ( 'custom' === expiry && ! customExpiry ) {
					showError( wrap, i18n.invalidCustomExpiry );
					return;
				}

				setLoading( wrap, true );
				apiFetch( `/link/${ postId }/generate`, 'POST', {
					label,
					expiry,
					custom_expiry: customExpiry,
					password: passwordInput ? passwordInput.value : '',
					max_views: maxViewsInput ? parseInt( maxViewsInput.value, 10 ) || 0 : 0,
				} )
					.then( ( link ) => {
						list.prepend( renderRow( link ) );
						if ( labelInput ) labelInput.value = '';
						if ( customExpiryInput ) customExpiryInput.value = '';
						if ( passwordInput ) passwordInput.value = '';
						if ( maxViewsInput ) maxViewsInput.value = '';
						setLoading( wrap, false );
					} )
					.catch( ( err ) => {
						setLoading( wrap, false );
						showError( wrap, ( err && err.error ) || i18n.error );
					} );
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', () => {
		const wrap = document.getElementById( 'erdo-draft-links-classic-wrap' );
		if ( ! wrap ) return;
		bindEvents( wrap );
	} );
} )();
