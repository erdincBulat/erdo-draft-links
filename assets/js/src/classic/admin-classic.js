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
		return fetch( restUrl + path, opts ).then( ( res ) => res.json() );
	}

	function formatDate( isoString ) {
		if ( ! isoString ) return '';
		const d = new Date( isoString );
		return d.toLocaleString();
	}

	function renderActive( wrap, data ) {
		const url = data.url;

		const expiresText = data.expires_at
			? i18n.expires.replace( '%s', formatDate( data.expires_at ) )
			: i18n.noExpiry;

		const viewsText = i18n.views.replace( '%d', data.view_count );

		wrap.innerHTML = `
			<div class="erdo-draft-links-url-row">
				<input type="text" readonly class="erdo-draft-links-url widefat" value="${ escAttr( url ) }" />
				<button type="button" class="button erdo-draft-links-copy">${ i18n.copy }</button>
			</div>
			<p class="description erdo-draft-links-meta">${ escHtml( expiresText ) }</p>
			<p class="description erdo-draft-links-meta">${ escHtml( viewsText ) }</p>
			<div class="erdo-draft-links-actions">
				<button type="button" class="button erdo-draft-links-regenerate">${ i18n.regenerate }</button>
				<button type="button" class="button erdo-draft-links-revoke">${ i18n.revoke }</button>
			</div>
			<span class="erdo-draft-links-spinner spinner" style="display:none;float:none;margin:4px 0;"></span>
			<div class="erdo-draft-links-notice" style="display:none;"></div>
		`;
		bindEvents( wrap );
	}

	function renderEmpty( wrap ) {
		wrap.innerHTML = `
			<p>
				<label for="erdo-draft-links-expiry-classic">${ i18n.generate }</label>
			</p>
			<p>
				<select name="erdo_draft_link_expiry" id="erdo-draft-links-expiry-classic" class="widefat">
					<option value="24h">24 Hours</option>
					<option value="48h">48 Hours</option>
					<option value="7d">7 Days</option>
					<option value="never">No Expiry</option>
				</select>
			</p>
			<div class="erdo-draft-links-actions">
				<button type="button" class="button button-primary erdo-draft-links-generate">${ i18n.generate }</button>
			</div>
			<span class="erdo-draft-links-spinner spinner" style="display:none;float:none;margin:4px 0;"></span>
			<div class="erdo-draft-links-notice" style="display:none;"></div>
		`;
		bindEvents( wrap );
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

		const copyBtn = wrap.querySelector( '.erdo-draft-links-copy' );
		if ( copyBtn ) {
			copyBtn.addEventListener( 'click', () => {
				const input = wrap.querySelector( '.erdo-draft-links-url' );
				navigator.clipboard
					.writeText( input.value )
					.catch( () => {
						input.select();
						document.execCommand( 'copy' );
					} );
				const orig = copyBtn.textContent;
				copyBtn.textContent = i18n.copied;
				setTimeout( () => ( copyBtn.textContent = orig ), 2000 );
			} );
		}

		const generateBtn = wrap.querySelector( '.erdo-draft-links-generate' );
		if ( generateBtn ) {
			generateBtn.addEventListener( 'click', () => {
				const expiry = wrap.querySelector( 'select[name="erdo_draft_link_expiry"]' )?.value || '24h';
				setLoading( wrap, true );
				apiFetch( `/link/${ postId }/generate`, 'POST', { expiry } )
					.then( ( data ) => renderActive( wrap, data ) )
					.catch( () => {
						setLoading( wrap, false );
						showError( wrap, i18n.error );
					} );
			} );
		}

		const regenerateBtn = wrap.querySelector( '.erdo-draft-links-regenerate' );
		if ( regenerateBtn ) {
			regenerateBtn.addEventListener( 'click', () => {
				setLoading( wrap, true );
				apiFetch( `/link/${ postId }/generate`, 'POST', { expiry: '24h' } )
					.then( ( data ) => renderActive( wrap, data ) )
					.catch( () => {
						setLoading( wrap, false );
						showError( wrap, i18n.error );
					} );
			} );
		}

		const revokeBtn = wrap.querySelector( '.erdo-draft-links-revoke' );
		if ( revokeBtn ) {
			revokeBtn.addEventListener( 'click', () => {
				setLoading( wrap, true );
				apiFetch( `/link/${ postId }/revoke`, 'POST' )
					.then( () => renderEmpty( wrap ) )
					.catch( () => {
						setLoading( wrap, false );
						showError( wrap, i18n.error );
					} );
			} );
		}
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

	document.addEventListener( 'DOMContentLoaded', () => {
		const wrap = document.getElementById( 'erdo-draft-links-classic-wrap' );
		if ( ! wrap ) return;
		bindEvents( wrap );
	} );
} )();
