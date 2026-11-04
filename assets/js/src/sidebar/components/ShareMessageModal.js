import { useState, useEffect } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { Modal, Button, TextareaControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { aiRequest, copyText, errorMessage } from '../utils';

/**
 * AI-written message to send along with a share link. The editor can edit
 * it before copying; nothing is sent from here.
 */
export default function ShareMessageModal( { postId, link, onClose } ) {
	const { createSuccessNotice } = useDispatch( noticesStore );
	const [ text, setText ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( true );

	const write = () => {
		setBusy( true );
		setError( '' );
		aiRequest( postId, 'share-message', { link_id: link.id } )
			.then( ( data ) => setText( data.text || '' ) )
			.catch( ( err ) => setError( errorMessage( err, __( 'Could not write the message.', 'erdo-draft-links' ) ) ) )
			.finally( () => setBusy( false ) );
	};

	useEffect( write, [] );

	const copy = async () => {
		if ( await copyText( text ) ) {
			createSuccessNotice( __( 'Message copied.', 'erdo-draft-links' ), { type: 'snackbar' } );
			onClose();
		}
	};

	return (
		<Modal title={ __( 'Message to send with the link', 'erdo-draft-links' ) } onRequestClose={ onClose } className="erdo-dl-modal">
			{ busy && ! text && <Spinner /> }
			{ error && <p className="erdo-dl-error">{ error }</p> }
			{ ( text || ! busy ) && (
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Message', 'erdo-draft-links' ) }
					help={ __( 'Written by your AI provider. Check it, edit it, then paste it into your email or chat.', 'erdo-draft-links' ) }
					value={ text }
					onChange={ setText }
					rows={ 9 }
				/>
			) }
			<div className="erdo-dl-modal__buttons">
				<Button variant="primary" onClick={ copy } disabled={ busy || ! text.trim() } __next40pxDefaultSize>
					{ __( 'Copy message', 'erdo-draft-links' ) }
				</Button>
				<Button variant="tertiary" onClick={ write } isBusy={ busy } disabled={ busy } __next40pxDefaultSize>
					{ __( 'Write again', 'erdo-draft-links' ) }
				</Button>
			</div>
		</Modal>
	);
}
