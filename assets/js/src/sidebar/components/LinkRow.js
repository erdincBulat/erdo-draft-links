import { useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { Button, DropdownMenu, MenuGroup, MenuItem, TextControl, Icon } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { store } from '../store';
import { copyText, relative, errorMessage, moreIcon, lockIcon } from '../utils';
import ShareMessageModal from './ShareMessageModal';

export default function LinkRow( { postId, link, ai } ) {
	const { updateLink, revokeLink } = useDispatch( store );
	const { createSuccessNotice, createErrorNotice } = useDispatch( noticesStore );
	const [ renaming, setRenaming ] = useState( false );
	const [ label, setLabel ] = useState( link.label );
	const [ busy, setBusy ] = useState( false );
	const [ writing, setWriting ] = useState( false );

	const run = ( promise, success, fallback ) => {
		setBusy( true );
		return promise
			.then( () => success && createSuccessNotice( success, { type: 'snackbar' } ) )
			.catch( ( err ) => createErrorNotice( errorMessage( err, fallback ), { type: 'snackbar' } ) )
			.finally( () => setBusy( false ) );
	};

	const copy = async () => {
		const ok = await copyText( link.url );
		if ( ok ) {
			createSuccessNotice( __( 'Link copied.', 'erdo-draft-links' ), { type: 'snackbar' } );
		} else {
			createErrorNotice( __( 'Could not copy. Select the link and copy it manually.', 'erdo-draft-links' ), { type: 'snackbar' } );
		}
	};

	const saveLabel = () => {
		setRenaming( false );
		if ( label !== link.label ) {
			run( updateLink( postId, link.id, { label } ), null, __( 'Could not rename the link.', 'erdo-draft-links' ) );
		}
	};

	const turnOff = () => {
		// eslint-disable-next-line no-alert
		if ( window.confirm( __( 'Turn off this link? Anyone who has it will no longer be able to open the draft.', 'erdo-draft-links' ) ) ) {
			run( revokeLink( postId, link.id ), __( 'Link turned off.', 'erdo-draft-links' ), __( 'Could not turn off the link.', 'erdo-draft-links' ) );
		}
	};

	const expiry = link.expires_at
		? /* translators: %s: relative time, e.g. "in 3 days" */
		  sprintf( __( 'Expires %s', 'erdo-draft-links' ), relative( link.expires_at ) )
		: __( 'No expiry', 'erdo-draft-links' );

	let views;
	if ( 0 === link.view_count ) {
		views = __( 'Not opened yet', 'erdo-draft-links' );
	} else if ( link.last_viewed_at ) {
		views = sprintf(
			/* translators: 1: number of times opened, 2: relative time of the last open */
			_n( 'Opened %1$d time, last %2$s', 'Opened %1$d times, last %2$s', link.view_count, 'erdo-draft-links' ),
			link.view_count,
			relative( link.last_viewed_at )
		);
	} else {
		views = sprintf(
			/* translators: %d: number of times opened */
			_n( 'Opened %d time', 'Opened %d times', link.view_count, 'erdo-draft-links' ),
			link.view_count
		);
	}

	const usedUp = null !== link.max_views && link.view_count >= link.max_views;

	return (
		<li className="erdo-dl-link">
			<div className="erdo-dl-link__main">
				{ renaming ? (
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Label', 'erdo-draft-links' ) }
						hideLabelFromVision
						value={ label }
						maxLength={ 100 }
						onChange={ setLabel }
						onBlur={ saveLabel }
						onKeyDown={ ( e ) => 'Enter' === e.key && saveLabel() }
						autoFocus // eslint-disable-line jsx-a11y/no-autofocus
					/>
				) : (
					<span className="erdo-dl-link__label">
						{ link.label || __( 'Untitled link', 'erdo-draft-links' ) }
						{ link.has_password && (
							<Icon icon={ lockIcon } size={ 14 } className="erdo-dl-link__lock" aria-label={ __( 'Password protected', 'erdo-draft-links' ) } />
						) }
					</span>
				) }
				{ usedUp ? (
					<span className="erdo-dl-link__meta erdo-dl-link__meta--warning">
						{ __( 'Open limit reached — the link no longer opens', 'erdo-draft-links' ) }
					</span>
				) : (
					<span className="erdo-dl-link__meta">{ expiry }</span>
				) }
				<span className="erdo-dl-link__meta">
					{ views }
					{ null !== link.max_views &&
						/* translators: %d: maximum number of opens */
						' · ' + sprintf( __( 'limit %d', 'erdo-draft-links' ), link.max_views ) }
				</span>
			</div>
			<div className="erdo-dl-link__actions">
				<Button size="compact" variant="secondary" onClick={ copy } disabled={ busy || ! link.url }>
					{ __( 'Copy', 'erdo-draft-links' ) }
				</Button>
				<DropdownMenu icon={ moreIcon } label={ __( 'Link actions', 'erdo-draft-links' ) } toggleProps={ { size: 'compact', disabled: busy } }>
					{ ( { onClose } ) => (
						<>
							<MenuGroup>
								<MenuItem
									onClick={ () => {
										window.open( link.url, '_blank', 'noopener,noreferrer' );
										onClose();
									} }
								>
									{ __( 'Open in new tab', 'erdo-draft-links' ) }
								</MenuItem>
								<MenuItem
									onClick={ () => {
										setRenaming( true );
										onClose();
									} }
								>
									{ __( 'Rename', 'erdo-draft-links' ) }
								</MenuItem>
								{ ai && ai.available && (
									<MenuItem
										onClick={ () => {
											setWriting( true );
											onClose();
										} }
									>
										{ __( 'Write a message to send (AI)', 'erdo-draft-links' ) }
									</MenuItem>
								) }
								<MenuItem
									onClick={ () => {
										run(
											updateLink( postId, link.id, { expiry: '7d' } ),
											__( 'Link now expires in 7 days.', 'erdo-draft-links' ),
											__( 'Could not extend the link.', 'erdo-draft-links' )
										);
										onClose();
									} }
								>
									{ __( 'Expire in 7 days from now', 'erdo-draft-links' ) }
								</MenuItem>
							</MenuGroup>
							<MenuGroup>
								<MenuItem
									isDestructive
									onClick={ () => {
										onClose();
										turnOff();
									} }
								>
									{ __( 'Turn off link', 'erdo-draft-links' ) }
								</MenuItem>
							</MenuGroup>
						</>
					) }
				</DropdownMenu>
			</div>
			{ writing && <ShareMessageModal postId={ postId } link={ link } onClose={ () => setWriting( false ) } /> }
		</li>
	);
}
