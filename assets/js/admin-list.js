( function () {
	function init() {
		document.querySelectorAll( '.erdo-draft-links-reveal' ).forEach( function ( wrap ) {
			var toggle  = wrap.querySelector( '.erdo-draft-links-reveal-toggle' );
			var content = wrap.querySelector( '.erdo-draft-links-reveal-content' );
			var copyBtn = wrap.querySelector( '.erdo-draft-links-copy' );
			var input   = wrap.querySelector( '.erdo-draft-links-reveal-input' );

			if ( ! toggle || ! content ) {
				return;
			}

			toggle.addEventListener( 'click', function () {
				var isHidden = content.hasAttribute( 'hidden' );

				if ( isHidden ) {
					content.removeAttribute( 'hidden' );
					toggle.setAttribute( 'aria-expanded', 'true' );
					if ( input ) {
						input.select();
					}
				} else {
					content.setAttribute( 'hidden', '' );
					toggle.setAttribute( 'aria-expanded', 'false' );
				}
			} );

			if ( copyBtn ) {
				copyBtn.addEventListener( 'click', function () {
					var url = wrap.getAttribute( 'data-url' ) || '';

					var markCopied = function () {
						copyBtn.classList.add( 'erdo-draft-links-copy--done' );
						setTimeout( function () {
							copyBtn.classList.remove( 'erdo-draft-links-copy--done' );
						}, 1500 );
					};

					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( url ).then( markCopied );
					} else if ( input ) {
						input.select();
						document.execCommand( 'copy' );
						markCopied();
					}
				} );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
