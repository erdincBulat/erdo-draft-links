import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';

export default function ErdoDraftLinksLinkRow( { link, onRevoke } ) {
	const [ copied, setCopied ] = useState( false );

	const handleCopy = () => {
		navigator.clipboard
			.writeText( link.url )
			.catch( () => {
				const el = document.createElement( 'textarea' );
				el.value = link.url;
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
			<div className="erdo-draft-links-link-header">
				<strong className="erdo-draft-links-link-label">
					{ link.label || __( 'Untitled link', 'erdo-draft-links' ) }
				</strong>
			</div>

			<div className="erdo-draft-links-url-row">
				<input
					type="text"
					readOnly
					className="erdo-draft-links-url"
					value={ link.url }
					onClick={ ( e ) => e.target.select() }
				/>
			</div>

			<div className="erdo-draft-links-url-actions">
				<button className="button erdo-draft-links-copy" onClick={ handleCopy }>
					{ copied ? __( 'Copied!', 'erdo-draft-links' ) : __( 'Copy', 'erdo-draft-links' ) }
				</button>
				<ExternalLink href={ link.url } className="erdo-draft-links-open">
					{ __( 'Open', 'erdo-draft-links' ) }
				</ExternalLink>
			</div>

			<div className="erdo-draft-links-meta">
				{ link.expires_at ? (
					<p>
						<strong>{ __( 'Expires:', 'erdo-draft-links' ) }</strong>{ ' ' }
						{ formatDate( link.expires_at ) }
					</p>
				) : (
					<p>{ __( 'No expiry.', 'erdo-draft-links' ) }</p>
				) }
				<p>
					<strong>{ __( 'Views:', 'erdo-draft-links' ) }</strong>{ ' ' }{ link.view_count }
				</p>
			</div>

			<div className="erdo-draft-links-actions">
				<button className="button erdo-draft-links-revoke" onClick={ () => onRevoke( link.id ) }>
					{ __( 'Revoke', 'erdo-draft-links' ) }
				</button>
			</div>
		</div>
	);
}
