import { useState, useEffect, createInterpolateElement } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { Button, TextareaControl, Spinner, PanelBody } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { store } from '../store';
import { usePostContext, relative, errorMessage, commentIcon, useAiStatus, aiRequest, sparkIcon } from '../utils';
import ReviewBadge from './ReviewBadge';

const SIDEBAR = 'erdo-draft-links-feedback';

const TYPE_LABELS = {
	comment: __( 'Comment', 'erdo-draft-links' ),
	annotation: __( 'Note on page', 'erdo-draft-links' ),
	approval: __( 'Approved', 'erdo-draft-links' ),
	changes_requested: __( 'Changes requested', 'erdo-draft-links' ),
};

function FeedbackItem( { postId, item, ai } ) {
	const { replyToFeedback, setFeedbackStatus } = useDispatch( store );
	const { createErrorNotice } = useDispatch( noticesStore );
	const [ reply, setReply ] = useState( '' );
	const [ replying, setReplying ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ aiBusy, setAiBusy ] = useState( '' );
	const [ translation, setTranslation ] = useState( '' );

	const runAi = ( helper, onText ) => {
		setAiBusy( helper );
		aiRequest( postId, helper, { item_id: item.id } )
			.then( ( data ) => onText( data.text || '' ) )
			.catch( ( err ) => createErrorNotice( errorMessage( err, __( 'The AI request failed.', 'erdo-draft-links' ) ), { type: 'snackbar' } ) )
			.finally( () => setAiBusy( '' ) );
	};

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
			{ translation && (
				<p className="erdo-dl-fb__translation">
					<span>{ __( 'Translation', 'erdo-draft-links' ) }</span>
					{ translation }
				</p>
			) }
			{ ai.available && item.message && ! translation && (
				<Button variant="link" className="erdo-dl-fb__ai-link" onClick={ () => runAi( 'translate', setTranslation ) } isBusy={ 'translate' === aiBusy } disabled={ !! aiBusy }>
					{ __( 'Translate', 'erdo-draft-links' ) }
				</Button>
			) }

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
					{ ai.available && item.message && (
						<Button
							variant="link"
							icon={ sparkIcon }
							iconSize={ 16 }
							className="erdo-dl-fb__ai-link"
							onClick={ () => runAi( 'reply', setReply ) }
							isBusy={ 'reply' === aiBusy }
							disabled={ !! aiBusy }
						>
							{ reply.trim() ? __( 'Suggest another reply', 'erdo-draft-links' ) : __( 'Suggest a reply', 'erdo-draft-links' ) }
						</Button>
					) }
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
 * AI to-do list built from the open feedback. Each task can resolve the
 * feedback items it came from in one click.
 */
function TaskSummary( { postId, items } ) {
	const { setFeedbackStatus } = useDispatch( store );
	const { createErrorNotice } = useDispatch( noticesStore );
	const [ tasks, setTasks ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const summarize = () => {
		setBusy( true );
		aiRequest( postId, 'summarize' )
			.then( ( data ) => setTasks( data.tasks || [] ) )
			.catch( ( err ) => createErrorNotice( errorMessage( err, __( 'Could not summarise the feedback.', 'erdo-draft-links' ) ), { type: 'snackbar' } ) )
			.finally( () => setBusy( false ) );
	};

	const isDone = ( task ) =>
		task.item_ids.length > 0 &&
		task.item_ids.every( ( id ) => {
			const item = items.find( ( i ) => i.id === id );
			return ! item || 'completed' === item.status;
		} );

	const resolve = ( task ) => {
		Promise.all( task.item_ids.map( ( id ) => setFeedbackStatus( postId, id, 'completed' ) ) ).catch( ( err ) =>
			createErrorNotice( errorMessage( err, __( 'Could not update the status.', 'erdo-draft-links' ) ), { type: 'snackbar' } )
		);
	};

	return (
		<div className="erdo-dl-tasks">
			<Button variant="secondary" icon={ sparkIcon } onClick={ summarize } isBusy={ busy } disabled={ busy } className="erdo-dl-ai-button">
				{ tasks ? __( 'Summarise again', 'erdo-draft-links' ) : __( 'Summarise into a to-do list', 'erdo-draft-links' ) }
			</Button>
			{ tasks && 0 === tasks.length && <p className="erdo-dl-hint">{ __( 'Nothing to do found in the open feedback.', 'erdo-draft-links' ) }</p> }
			{ tasks && tasks.length > 0 && (
				<ol className="erdo-dl-tasks__list">
					{ tasks.map( ( task, index ) => {
						const done = isDone( task );
						return (
							<li key={ index } className={ done ? 'is-done' : '' }>
								<span className="erdo-dl-tasks__text">{ task.task }</span>
								{ task.item_ids.length > 0 && ! done && (
									<Button variant="link" onClick={ () => resolve( task ) }>
										{ sprintf(
											/* translators: %d: number of feedback items the task came from */
											_n( 'Done — resolve %d comment', 'Done — resolve %d comments', task.item_ids.length, 'erdo-draft-links' ),
											task.item_ids.length
										) }
									</Button>
								) }
							</li>
						);
					} ) }
				</ol>
			) }
		</div>
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
	const ai = useAiStatus();
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
					{ ai.available && open.some( ( i ) => 'approval' !== i.type ) && <TaskSummary postId={ postId } items={ items } /> }
					{ open.length > 0 && (
						<ul className="erdo-dl-fb-list">
							{ open.map( ( item ) => (
								<FeedbackItem key={ item.id } postId={ postId } item={ item } ai={ ai } />
							) ) }
						</ul>
					) }
					{ loaded && items.length > 0 && ai.enabled && ! ai.available && ai.connectors_url && (
						<p className="erdo-dl-hint erdo-dl-ai-hint">
							{ createInterpolateElement(
								__( 'Want help summarising, replying or translating? Connect your own AI provider under <a>Settings → Connectors</a>.', 'erdo-draft-links' ),
								{ a: <a href={ ai.connectors_url } /> }
							) }
						</p>
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
								<FeedbackItem key={ item.id } postId={ postId } item={ item } ai={ ai } />
							) ) }
						</ul>
					</PanelBody>
				) }
			</PluginSidebar>
		</>
	);
}
