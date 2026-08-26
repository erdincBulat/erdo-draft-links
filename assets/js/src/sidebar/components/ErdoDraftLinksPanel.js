import { useState, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { PanelBody, Spinner, TextControl } from '@wordpress/components';
import ExpirySelector from './ExpirySelector';
import ErdoDraftLinksLinkRow from './ErdoDraftLinksLinkRow';

/* global erdoDraftLinksData */

export default function ErdoDraftLinksPanel() {
	const postId = useSelect( ( select ) => select( editorStore ).getCurrentPostId(), [] );

	const [ links, setLinks ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ label, setLabel ] = useState( '' );
	const [ expiry, setExpiry ] = useState( '24h' );
	const [ customExpiry, setCustomExpiry ] = useState( '' );

	useEffect( () => {
		if ( ! postId ) return;

		const fetchLinks = () => {
			apiFetch( { path: `/erdo-draft-links/v1/link/${ postId }` } )
				.then( ( data ) => {
					setLinks( data.links || [] );
					setLoading( false );
				} )
				.catch( () => {
					setError( __( 'Could not load Erdo Draft Links data.', 'erdo-draft-links' ) );
					setLoading( false );
				} );
		};

		fetchLinks();

		// Links may have been generated/revoked elsewhere (e.g. the Erdo Draft
		// Links Manager in another tab) — refresh when the editor tab regains
		// focus so the panel doesn't show stale state.
		const handleFocus = () => {
			if ( document.visibilityState === 'visible' ) {
				fetchLinks();
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
		if ( 'custom' === expiry && ! customExpiry ) {
			setError( __( 'Please choose a valid future date and time.', 'erdo-draft-links' ) );
			return;
		}

		setLoading( true );
		setError( null );
		apiFetch( {
			path: `/erdo-draft-links/v1/link/${ postId }/generate`,
			method: 'POST',
			data: { label, expiry, custom_expiry: customExpiry },
		} )
			.then( ( link ) => {
				setLinks( ( current ) => [ link, ...current ] );
				setLabel( '' );
				setCustomExpiry( '' );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError( ( err && err.error ) || __( 'Could not generate the link. Please try again.', 'erdo-draft-links' ) );
				setLoading( false );
			} );
	};

	const handleRevoke = ( linkId ) => {
		setLoading( true );
		setError( null );
		apiFetch( {
			path: `/erdo-draft-links/v1/link/${ postId }/revoke`,
			method: 'POST',
			data: { link_id: linkId },
		} )
			.then( () => {
				setLinks( ( current ) => current.filter( ( link ) => link.id !== linkId ) );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError( ( err && err.error ) || __( 'Could not revoke the link. Please try again.', 'erdo-draft-links' ) );
				setLoading( false );
			} );
	};

	return (
		<PanelBody title={ __( 'Erdo Draft Links', 'erdo-draft-links' ) } initialOpen={ true }>
			{ loading && <Spinner /> }

			{ ! loading && error && (
				<p className="erdo-draft-links-error">{ error }</p>
			) }

			{ ! loading && ! error && links.length === 0 && (
				<p className="erdo-draft-links-description">
					{ __( 'Generate a secure link to share this draft with anyone — no login required.', 'erdo-draft-links' ) }
				</p>
			) }

			{ ! loading && ! error && links.map( ( link ) => (
				<ErdoDraftLinksLinkRow key={ link.id } link={ link } onRevoke={ handleRevoke } />
			) ) }

			<div className="erdo-draft-links-add-link">
				<TextControl
					label={ __( 'Label (optional)', 'erdo-draft-links' ) }
					placeholder={ __( 'e.g. Client A', 'erdo-draft-links' ) }
					value={ label }
					maxLength={ 100 }
					onChange={ setLabel }
				/>
				<ExpirySelector
					value={ expiry }
					options={ erdoDraftLinksData.expiries }
					onChange={ setExpiry }
				/>
				{ 'custom' === expiry && (
					<div className="erdo-draft-links-custom-expiry">
						<label htmlFor="erdo-draft-links-custom-expiry-input">
							{ __( 'Expires on:', 'erdo-draft-links' ) }
						</label>
						<input
							type="datetime-local"
							id="erdo-draft-links-custom-expiry-input"
							value={ customExpiry }
							onChange={ ( e ) => setCustomExpiry( e.target.value ) }
						/>
					</div>
				) }
				<button
					className="button button-primary erdo-draft-links-generate-btn"
					onClick={ handleGenerate }
					disabled={ loading }
				>
					{ __( 'Generate Erdo Draft Links', 'erdo-draft-links' ) }
				</button>
			</div>
		</PanelBody>
	);
}
