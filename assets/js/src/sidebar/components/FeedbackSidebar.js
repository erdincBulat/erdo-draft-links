import { useState, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { Button, TextareaControl, Spinner, PanelBody } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { store } from '../store';
import { usePostContext, relative, errorMessage, commentIcon } from '../utils';
import ReviewBadge from './ReviewBadge';

const SIDEBAR = 'erdo-draft-links-feedback';

const TYPE_LABELS = {
	comment: __( 'Comment', 'erdo-draft-links' ),
	annotation: __( 'Note on page', 'erdo-draft-links' ),
	approval: __( 'Approved', 'erdo-draft-links' ),
	changes_requested: __( 'Changes requested', 'erdo-draft-links' ),
};

function FeedbackItem( { postId, item } ) {
	const { replyToFeedback, setFeedbackStatus } = useDispatch( store );
	const { createErrorNotice } = useDispatch( noticesStore );
	const [ reply, setReply ] = useState( '' );
	const [ replying, setReplying ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	// Decisions are records, not tasks — they don't get resolved.
	const resolvable = 'approval' !== item.type;
	const resolved = 'completed' === item.status;

	const sendReply = async () => {
		if ( ! reply.trim() ) {
			return;
		}
		setBusy( true );
		try {
			await replyToFeedback( postId, item.id, reply );
			setReply( '' );
			setReplying( false );
		} catch ( err ) {
			createErrorNotice( errorMessage( err, __( 'Could not send the reply.', 'erdo-draft-links' ) ), { type: 'snackbar' } );
		} finally {
			setBusy( false );
		}
	};

	const toggleResolved = () => {
		setBusy( true );
		setFeedbackStatus( postId, item.id, resolved ? 'in_progress' : 'completed' )
			.catch( ( err ) => createErrorNotice( errorMessage( err, __( 'Could not update the status.', 'erdo-draft-links' ) ), { type: 'snackbar' } ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<li className={ `erdo-dl-fb erdo-dl-fb--${ item.type }${ resolved ? ' is-resolved' : '' }` }>
			<div className="erdo-dl-fb__head">
				<span className="erdo-dl-fb__type">{ TYPE_LABELS[ item.type ] || TYPE_LABELS.comment }</span>
				<span className="erdo-dl-fb__when">{ relative( item.created_at ) }</span>
			</div>
			<div className="erdo-dl-fb__author">
				{ item.author_name }
				{ item.link_label && <span className="erdo-dl-fb__via"> · { item.link_label }</span> }
			</div>
			{ item.message && <p className="erdo-dl-fb__message">{ item.message }</p> }

			{ item.replies.length > 0 && (
				<ul className="erdo-dl-fb__replies">
					{ item.replies.map( ( r ) => (
						<li key={ r.id } className={ r.is_staff ? 'is-staff' : '' }>
							<span className="erdo-dl-fb__author">{ r.author_name }</span>
							<span className="erdo-dl-fb__when"> · { relative( r.created_at ) }</span>
							<p className="erdo-dl-fb__message">{ r.message }</p>
						</li>
					) ) }
				</ul>
			) }

			{ replying ? (
				<div className="erdo-dl-fb__reply">
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Reply', 'erdo-draft-links' ) }
						hideLabelFromVision
						help={ __( 'The reviewer sees your reply the next time they open the link.', 'erdo-draft-links' ) }
						value={ reply }
						onChange={ setReply }
						rows={ 3 }
					/>
					<div className="erdo-dl-fb__buttons">
						<Button variant="primary" size="compact" onClick={ sendReply } isBusy={ busy } disabled={ busy || ! reply.trim() }>
							{ __( 'Send reply', 'erdo-draft-links' ) }
						</Button>
						<Button variant="tertiary" size="compact" onClick={ () => setReplying( false ) }>
							{ __( 'Cancel', 'erdo-draft-links' ) }
						</Button>
					</div>
				</div>
			) : (
				<div className="erdo-dl-fb__buttons">
					<Button variant="secondary" size="compact" onClick={ () => setReplying( true ) }>
						{ __( 'Reply', 'erdo-draft-links' ) }
					</Button>
					{ resolvable && (
						<Button variant="tertiary" size="compact" onClick={ toggleResolved } disabled={ busy }>
							{ resolved ? __( 'Reopen', 'erdo-draft-links' ) : __( 'Mark resolved', 'erdo-draft-links' ) }
						</Button>
					) }
				</div>
			) }
		</li>
	);
}

/**
 * All feedback on this post across every share link, newest first, with
 * replies and resolve/reopen. Open items are shown first; resolved ones
 * are collapsed below.
 */
export default function FeedbackSidebar() {
	const { postId, isNew } = usePostContext();
	const { items, loaded, review } = useSelect(
		( select ) => ( {
			items: select( store ).getFeedback( postId ),
			loaded: select( store ).hasLoadedFeedback( postId ),
			review: select( store ).getReview( postId ),
		} ),
		[ postId ]
	);
	const isOpen = useSelect(
		( select ) => {
			const iface = select( 'core/interface' );
			return !! iface && 'erdo-draft-links/' + SIDEBAR === iface.getActiveComplementaryArea( 'core' );
		},
		[]
	);
	const { fetchFeedback } = useDispatch( store );
	const { createErrorNotice } = useDispatch( noticesStore );

	// Load when the sidebar is opened, and again each time it's reopened.
	useEffect( () => {
		if ( isOpen && postId && ! isNew ) {
			fetchFeedback( postId ).catch( () =>
				createErrorNotice( __( 'Could not load feedback.', 'erdo-draft-links' ), { type: 'snackbar' } )
			);
		}
	}, [ isOpen, postId ] );

	const open = items.filter( ( i ) => 'completed' !== i.status || 'approval' === i.type );
	const resolved = items.filter( ( i ) => 'completed' === i.status && 'approval' !== i.type );
	const title = __( 'Client feedback', 'erdo-draft-links' );

	return (
		<>
			<PluginSidebarMoreMenuItem target={ SIDEBAR } icon={ commentIcon }>
				{ title }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name={ SIDEBAR } title={ title } icon={ commentIcon } className="erdo-dl-feedback">
				<PanelBody>
					<ReviewBadge review={ review } />
					{ isNew && <p className="erdo-dl-hint">{ __( 'Save the draft and share a link to start collecting feedback.', 'erdo-draft-links' ) }</p> }
					{ ! isNew && ! loaded && <Spinner /> }
					{ loaded && 0 === items.length && (
						<p className="erdo-dl-hint">
							{ __( 'No feedback yet. Reviewers can comment, pin notes on the page, approve or request changes from the share link.', 'erdo-draft-links' ) }
						</p>
					) }
					{ open.length > 0 && (
						<ul className="erdo-dl-fb-list">
							{ open.map( ( item ) => (
								<FeedbackItem key={ item.id } postId={ postId } item={ item } />
							) ) }
						</ul>
					) }
				</PanelBody>
				{ resolved.length > 0 && (
					<PanelBody
						initialOpen={ false }
						title={ sprintf(
							/* translators: %d: number of resolved feedback items */
							_n( '%d resolved', '%d resolved', resolved.length, 'erdo-draft-links' ),
							resolved.length
						) }
					>
						<ul className="erdo-dl-fb-list">
							{ resolved.map( ( item ) => (
								<FeedbackItem key={ item.id } postId={ postId } item={ item } />
							) ) }
						</ul>
					</PanelBody>
				) }
			</PluginSidebar>
		</>
	);
}
