import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';

export default function ErdoDraftLinksStatus( { data, onRegenerate, onRevoke } ) {
	const [ copied, setCopied ] = useState( false );

	const handleCopy = () => {
		navigator.clipboard
			.writeText( data.url )
			.catch( () => {
				const el = document.createElement( 'textarea' );
				el.value = data.url;
				document.body.appendChild( el );
				el.select();
				document.execCommand( 'copy' );
				document.body.removeChild( el );
			} );
		setCopied( true );
		setTimeout( () => setCopied( false ), 2000 );
	};

	const formatDate = ( iso ) => {
		if ( ! iso ) return null;
		return new Date( iso ).toLocaleString();
	};

	return (
		<div className="erdo-draft-links-status">
			<div className="erdo-draft-links-url-row">
				<input
					type="text"
					readOnly
					className="erdo-draft-links-url"
					value={ data.url }
					onClick={ ( e ) => e.target.select() }
				/>
			</div>

			<div className="erdo-draft-links-url-actions">
				<button className="button erdo-draft-links-copy" onClick={ handleCopy }>
					{ copied ? __( 'Copied!', 'erdo-draft-links' ) : __( 'Copy', 'erdo-draft-links' ) }
				</button>
				<ExternalLink href={ data.url } className="erdo-draft-links-open">
					{ __( 'Open', 'erdo-draft-links' ) }
				</ExternalLink>
			</div>

			<div className="erdo-draft-links-meta">
				{ data.expires_at ? (
					<p>
						<strong>{ __( 'Expires:', 'erdo-draft-links' ) }</strong>{ ' ' }
						{ formatDate( data.expires_at ) }
					</p>
				) : (
					<p>{ __( 'No expiry.', 'erdo-draft-links' ) }</p>
				) }
				<p>
					<strong>{ __( 'Views:', 'erdo-draft-links' ) }</strong>{ ' ' }{ data.view_count }
				</p>
			</div>

			<div className="erdo-draft-links-actions">
				<button className="button erdo-draft-links-regenerate" onClick={ onRegenerate }>
					{ __( 'Regenerate', 'erdo-draft-links' ) }
				</button>
				<button className="button erdo-draft-links-revoke" onClick={ onRevoke }>
					{ __( 'Revoke', 'erdo-draft-links' ) }
				</button>
			</div>
		</div>
	);
}
