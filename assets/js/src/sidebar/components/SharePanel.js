import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import * as data from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { Button, SelectControl, TextControl, Spinner } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { store } from '../store';
import { settings, usePostContext, copyText, errorMessage, openFeedbackSidebar } from '../utils';
import LinkRow from './LinkRow';
import ReviewBadge from './ReviewBadge';

/**
 * "Share for review" panel in the post's Document settings: the main place
 * to create and manage share links. One click creates a link with the
 * defaults and copies it; "Link options" reveals label/expiry/password/limit.
 */
export default function SharePanel() {
	const { postId, isNew } = usePostContext();
	const { links, loaded, review, openCount } = useSelect(
		( select ) => {
			const s = select( store );
			return {
				links: s.getLinks( postId ),
				loaded: s.hasLoadedLinks( postId ),
				review: s.getReview( postId ),
				openCount: s.getOpenCount( postId ),
			};
		},
		[ postId ]
	);
	const { generateLink } = useDispatch( store );
	const { createSuccessNotice, createErrorNotice } = useDispatch( noticesStore );

	const [ showOptions, setShowOptions ] = useState( false );
	const [ label, setLabel ] = useState( '' );
	const [ expiry, setExpiry ] = useState( settings.defaultExpiry );
	const [ customExpiry, setCustomExpiry ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const [ maxViews, setMaxViews ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const create = async () => {
		const request = showOptions
			? { label, expiry, custom_expiry: customExpiry, password, max_views: parseInt( maxViews, 10 ) || 0 }
			: { expiry: settings.defaultExpiry };

		if ( showOptions && 'custom' === expiry && ! customExpiry ) {
			createErrorNotice( __( 'Please choose a valid future date and time.', 'erdo-draft-links' ), { type: 'snackbar' } );
			return;
		}

		setBusy( true );
		try {
			const link = await generateLink( postId, request );
			const copied = await copyText( link.url );
			createSuccessNotice(
				copied
					? __( 'Share link created and copied.', 'erdo-draft-links' )
					: __( 'Share link created.', 'erdo-draft-links' ),
				{ type: 'snackbar' }
			);
			setLabel( '' );
			setPassword( '' );
			setMaxViews( '' );
			setCustomExpiry( '' );
		} catch ( err ) {
			createErrorNotice( errorMessage( err, __( 'Could not create the link. Please try again.', 'erdo-draft-links' ) ), { type: 'snackbar' } );
		} finally {
			setBusy( false );
		}
	};

	return (
		<PluginDocumentSettingPanel
			name="erdo-draft-links-share"
			title={ __( 'Share for review', 'erdo-draft-links' ) }
			className="erdo-dl-share"
		>
			{ ! isNew && loaded && <ReviewBadge review={ review } /> }

			{ ! isNew && openCount > 0 && (
				<Button variant="link" className="erdo-dl-share__feedback" onClick={ () => openFeedbackSidebar( data.dispatch ) }>
					{ sprintf(
						/* translators: %d: number of open feedback items */
						_n( '%d open comment', '%d open comments', openCount, 'erdo-draft-links' ),
						openCount
					) }
				</Button>
			) }

			{ isNew ? (
				<p className="erdo-dl-hint">
					{ __( 'Save the draft first, then you can create a link to share it.', 'erdo-draft-links' ) }
				</p>
			) : (
				<p className="erdo-dl-hint">
					{ __( 'Anyone with the link can view this draft and leave feedback — no login needed.', 'erdo-draft-links' ) }
				</p>
			) }

			{ showOptions && ! isNew && (
				<div className="erdo-dl-options">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Label', 'erdo-draft-links' ) }
						help={ __( 'Only you see this, e.g. the client’s name.', 'erdo-draft-links' ) }
						value={ label }
						maxLength={ 100 }
						onChange={ setLabel }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Expires', 'erdo-draft-links' ) }
						value={ expiry }
						options={ settings.expiries }
						onChange={ setExpiry }
					/>
					{ 'custom' === expiry && (
						<div className="erdo-dl-field">
							<label htmlFor="erdo-dl-custom-expiry">{ __( 'Expires on', 'erdo-draft-links' ) }</label>
							<input
								type="datetime-local"
								id="erdo-dl-custom-expiry"
								value={ customExpiry }
								onChange={ ( e ) => setCustomExpiry( e.target.value ) }
							/>
						</div>
					) }
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="password"
						autoComplete="new-password"
						label={ __( 'Password (optional)', 'erdo-draft-links' ) }
						help={ __( 'Send it to the reviewer separately from the link.', 'erdo-draft-links' ) }
						value={ password }
						onChange={ setPassword }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						min={ 0 }
						label={ __( 'Stop working after this many opens (optional)', 'erdo-draft-links' ) }
						value={ maxViews }
						onChange={ setMaxViews }
					/>
				</div>
			) }

			<div className="erdo-dl-create">
				<Button variant="primary" onClick={ create } isBusy={ busy } disabled={ busy || isNew } __next40pxDefaultSize>
					{ __( 'Create share link', 'erdo-draft-links' ) }
				</Button>
				{ ! isNew && (
					<Button variant="tertiary" size="compact" onClick={ () => setShowOptions( ! showOptions ) } aria-expanded={ showOptions }>
						{ showOptions ? __( 'Hide options', 'erdo-draft-links' ) : __( 'Link options', 'erdo-draft-links' ) }
					</Button>
				) }
			</div>

			{ ! isNew && ! loaded && <Spinner /> }

			{ links.length > 0 && (
				<>
					<h3 className="erdo-dl-heading">
						{ sprintf(
							/* translators: %d: number of active links */
							_n( '%d active link', '%d active links', links.length, 'erdo-draft-links' ),
							links.length
						) }
					</h3>
					<ul className="erdo-dl-links">
						{ links.map( ( link ) => (
							<LinkRow key={ link.id } postId={ postId } link={ link } />
						) ) }
					</ul>
				</>
			) }
		</PluginDocumentSettingPanel>
	);
}
