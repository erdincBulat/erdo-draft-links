import { useState, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { PanelBody, Spinner } from '@wordpress/components';
import ExpirySelector from './ExpirySelector';
import ErdoDraftLinksStatus from './ErdoDraftLinksStatus';

/* global erdoDraftLinksData */

export default function ErdoDraftLinksPanel() {
	const postId = useSelect( ( select ) => select( editorStore ).getCurrentPostId(), [] );

	const [ linkData, setLinkData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ expiry, setExpiry ] = useState( '24h' );

	useEffect( () => {
		if ( ! postId ) return;

		const fetchLink = () => {
			apiFetch( { path: `/erdo-draft-links/v1/link/${ postId }` } )
				.then( ( data ) => {
					setLinkData( data.exists === false ? null : data );
					setLoading( false );
				} )
				.catch( () => {
					setError( __( 'Could not load Erdo Draft Links data.', 'erdo-draft-links' ) );
					setLoading( false );
				} );
		};

		fetchLink();

		// The link may have been deleted or changed elsewhere (e.g. the
		// Erdo Draft Links Manager in another tab) — refresh when the
		// editor tab regains focus so the panel doesn't show stale state.
		const handleFocus = () => {
			if ( document.visibilityState === 'visible' ) {
				fetchLink();
			}
		};

		window.addEventListener( 'focus', handleFocus );
		document.addEventListener( 'visibilitychange', handleFocus );

		return () => {
			window.removeEventListener( 'focus', handleFocus );
			document.removeEventListener( 'visibilitychange', handleFocus );
		};
	}, [ postId ] );

	const handleGenerate = () => {
		setLoading( true );
		setError( null );
		apiFetch( {
			path: `/erdo-draft-links/v1/link/${ postId }/generate`,
			method: 'POST',
			data: { expiry },
		} )
			.then( ( data ) => {
				setLinkData( data );
				setLoading( false );
			} )
			.catch( () => {
				setError( __( 'Could not generate the link. Please try again.', 'erdo-draft-links' ) );
				setLoading( false );
			} );
	};

	const handleRevoke = () => {
		setLoading( true );
		setError( null );
		apiFetch( {
			path: `/erdo-draft-links/v1/link/${ postId }/revoke`,
			method: 'POST',
		} )
			.then( () => {
				setLinkData( null );
				setLoading( false );
			} )
			.catch( () => {
				setError( __( 'Could not revoke the link. Please try again.', 'erdo-draft-links' ) );
				setLoading( false );
			} );
	};

	return (
		<PanelBody title={ __( 'Erdo Draft Links', 'erdo-draft-links' ) } initialOpen={ true }>
			{ loading && <Spinner /> }

			{ ! loading && error && (
				<p className="erdo-draft-links-error">{ error }</p>
			) }

			{ ! loading && ! error && ! linkData && (
				<>
					<p className="erdo-draft-links-description">
						{ __( 'Generate a secure link to share this draft with anyone — no login required.', 'erdo-draft-links' ) }
					</p>
					<ExpirySelector
						value={ expiry }
						options={ erdoDraftLinksData.expiries }
						onChange={ setExpiry }
					/>
					<button
						className="button button-primary erdo-draft-links-generate-btn"
						onClick={ handleGenerate }
					>
						{ __( 'Generate Erdo Draft Links', 'erdo-draft-links' ) }
					</button>
				</>
			) }

			{ ! loading && ! error && linkData && (
				<ErdoDraftLinksStatus
					data={ linkData }
					onRegenerate={ handleGenerate }
					onRevoke={ handleRevoke }
				/>
			) }
		</PanelBody>
	);
}
