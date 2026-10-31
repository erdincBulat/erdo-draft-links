/**
 * "Copy link" buttons in the Draft Links tables and the Posts list.
 */
( function () {
	function copy( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		// Plain-HTTP admin: fall back to a temporary textarea.
		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();
			var ok = document.execCommand( 'copy' );
			area.remove();
			( ok ? resolve : reject )();
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.erdo-dl-copy' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();

		var label = button.textContent;
		copy( button.getAttribute( 'data-url' ) || '' ).then(
			function () {
				button.textContent = button.getAttribute( 'data-copied' ) || label;
				button.classList.add( 'is-copied' );
				if ( window.wp && window.wp.a11y ) {
					window.wp.a11y.speak( button.textContent );
				}
				setTimeout( function () {
					button.textContent = label;
					button.classList.remove( 'is-copied' );
				}, 1600 );
			},
			function () {
				window.prompt( '', button.getAttribute( 'data-url' ) || '' ); // eslint-disable-line no-alert
			}
		);
	} );
}() );
