import { useState, useEffect } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { store as noticesStore } from '@wordpress/notices';
import { Modal, Button, TextareaControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { aiRequest, errorMessage, sparkIcon } from '../utils';

/**
 * "What's new since your last review" note, shown to reviewers at the top
 * of the review bar's comment panel. Editors write it by hand or let their
 * AI provider draft it from the difference to the reviewed version.
 */
export default function ChangesNote( { postId, ai } ) {
	const { createSuccessNotice, createErrorNotice } = useDispatch( noticesStore );
	const [ saved, setSaved ] = useState( null );
	const [ open, setOpen ] = useState( false );
	const [ text, setText ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const path = `/erdo-draft-links/v1/posts/${ postId }/changes-note`;

	useEffect( () => {
		apiFetch( { path } )
			.then( ( note ) => setSaved( note.text || '' ) )
			.catch( () => setSaved( '' ) );
	}, [ postId ] );

	const edit = () => {
		setText( saved || '' );
		setOpen( true );
	};

	const save = ( value ) => {
		setBusy( true );
		apiFetch( { path, method: 'POST', data: { text: value } } )
			.then( ( note ) => {
				setSaved( note.text || '' );
				setOpen( false );
				createSuccessNotice(
					note.text
						? __( 'Reviewers will see the note.', 'erdo-draft-links' )
						: __( 'Note removed.', 'erdo-draft-links' ),
					{ type: 'snackbar' }
				);
			} )
			.catch( ( err ) => createErrorNotice( errorMessage( err, __( 'Could not save the note.', 'erdo-draft-links' ) ), { type: 'snackbar' } ) )
			.finally( () => setBusy( false ) );
	};

	const draft = () => {
		setBusy( true );
		aiRequest( postId, 'changes' )
			.then( ( data ) => setText( data.text || '' ) )
			.catch( ( err ) => createErrorNotice( errorMessage( err, __( 'Could not write the note.', 'erdo-draft-links' ) ), { type: 'snackbar' } ) )
			.finally( () => setBusy( false ) );
	};

	if ( null === saved ) {
		return null;
	}

	return (
		<div className="erdo-dl-note">
			{ saved ? (
				<>
					<span className="erdo-dl-note__label">{ __( 'Note for reviewers', 'erdo-draft-links' ) }</span>
					<p className="erdo-dl-note__text">{ saved }</p>
					<Button variant="link" onClick={ edit }>
						{ __( 'Edit note', 'erdo-draft-links' ) }
					</Button>
				</>
			) : (
				<Button variant="link" onClick={ edit }>
					{ __( 'Add a “what changed” note for reviewers', 'erdo-draft-links' ) }
				</Button>
			) }

			{ open && (
				<Modal title={ __( 'What changed since the last review', 'erdo-draft-links' ) } onRequestClose={ () => setOpen( false ) } className="erdo-dl-modal">
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Note', 'erdo-draft-links' ) }
						help={ __( 'Shown at the top of the comments panel on the review page, so returning reviewers know what to look at.', 'erdo-draft-links' ) }
						value={ text }
						onChange={ setText }
						rows={ 7 }
					/>
					{ ai.available && (
						<Button variant="secondary" icon={ sparkIcon } onClick={ draft } isBusy={ busy } disabled={ busy } className="erdo-dl-ai-button">
							{ __( 'Write from the changes with AI', 'erdo-draft-links' ) }
						</Button>
					) }
					{ busy && <Spinner /> }
					<div className="erdo-dl-modal__buttons">
						<Button variant="primary" onClick={ () => save( text ) } disabled={ busy } __next40pxDefaultSize>
							{ __( 'Save note', 'erdo-draft-links' ) }
						</Button>
						{ saved && (
							<Button variant="tertiary" isDestructive onClick={ () => save( '' ) } disabled={ busy } __next40pxDefaultSize>
								{ __( 'Remove note', 'erdo-draft-links' ) }
							</Button>
						) }
					</div>
				</Modal>
			) }
		</div>
	);
}
