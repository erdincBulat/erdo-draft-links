/**
 * Shared editor state for draft links and feedback, so the share panel,
 * preview menu item, post-publish panel and feedback sidebar stay in sync
 * without each fetching on its own.
 */
import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

export const STORE_NAME = 'erdo-draft-links';

const DEFAULT_POST = { links: [], review: null, openCount: 0, loaded: false };
const DEFAULT_FEEDBACK = { items: [], loaded: false };

const reducer = ( state = { posts: {}, feedback: {} }, action ) => {
	const post = state.posts[ action.postId ] || DEFAULT_POST;
	const setPost = ( changes ) => ( {
		...state,
		posts: { ...state.posts, [ action.postId ]: { ...post, ...changes } },
	} );
	const feedback = state.feedback[ action.postId ] || DEFAULT_FEEDBACK;
	const setFeedback = ( changes ) => ( {
		...state,
		feedback: { ...state.feedback, [ action.postId ]: { ...feedback, ...changes } },
	} );

	switch ( action.type ) {
		case 'RECEIVE_LINKS':
			return setPost( {
				links: action.links,
				review: action.review,
				openCount: action.openCount,
				loaded: true,
			} );
		case 'ADD_LINK':
			return setPost( { links: [ action.link, ...post.links ] } );
		case 'REPLACE_LINK':
			return setPost( {
				links: post.links.map( ( l ) => ( l.id === action.link.id ? action.link : l ) ),
			} );
		case 'REMOVE_LINK':
			return setPost( { links: post.links.filter( ( l ) => l.id !== action.linkId ) } );
		case 'RECEIVE_FEEDBACK':
			return {
				...setFeedback( { items: action.items, loaded: true } ),
				posts: {
					...state.posts,
					[ action.postId ]: { ...post, review: action.review, openCount: action.openCount },
				},
			};
		case 'ADD_REPLY':
			return setFeedback( {
				items: feedback.items.map( ( item ) =>
					item.id === action.id ? { ...item, replies: [ ...item.replies, action.reply ] } : item
				),
			} );
		case 'SET_FEEDBACK_STATUS':
			return {
				...setFeedback( {
					items: feedback.items.map( ( item ) =>
						item.id === action.id ? { ...item, status: action.status } : item
					),
				} ),
				posts: { ...state.posts, [ action.postId ]: { ...post, openCount: action.openCount } },
			};
	}
	return state;
};

const actions = {
	fetchLinks:
		( postId ) =>
		async ( { dispatch } ) => {
			const data = await apiFetch( { path: `/erdo-draft-links/v1/link/${ postId }` } );
			dispatch( {
				type: 'RECEIVE_LINKS',
				postId,
				links: data.links || [],
				review: data.review || null,
				openCount: data.open_count || 0,
			} );
		},

	generateLink:
		( postId, data ) =>
		async ( { dispatch } ) => {
			const link = await apiFetch( {
				path: `/erdo-draft-links/v1/link/${ postId }/generate`,
				method: 'POST',
				data,
			} );
			dispatch( { type: 'ADD_LINK', postId, link } );
			return link;
		},

	updateLink:
		( postId, linkId, data ) =>
		async ( { dispatch } ) => {
			const link = await apiFetch( {
				path: `/erdo-draft-links/v1/link/${ postId }/update`,
				method: 'POST',
				data: { link_id: linkId, ...data },
			} );
			dispatch( { type: 'REPLACE_LINK', postId, link } );
			return link;
		},

	revokeLink:
		( postId, linkId ) =>
		async ( { dispatch } ) => {
			await apiFetch( {
				path: `/erdo-draft-links/v1/link/${ postId }/revoke`,
				method: 'POST',
				data: { link_id: linkId },
			} );
			dispatch( { type: 'REMOVE_LINK', postId, linkId } );
		},

	fetchFeedback:
		( postId ) =>
		async ( { dispatch } ) => {
			const data = await apiFetch( { path: `/erdo-draft-links/v1/posts/${ postId }/feedback` } );
			dispatch( {
				type: 'RECEIVE_FEEDBACK',
				postId,
				items: data.items || [],
				review: data.review || null,
				openCount: data.open_count || 0,
			} );
		},

	replyToFeedback:
		( postId, id, message ) =>
		async ( { dispatch } ) => {
			const reply = await apiFetch( {
				path: `/erdo-draft-links/v1/posts/${ postId }/feedback/${ id }/reply`,
				method: 'POST',
				data: { message },
			} );
			dispatch( { type: 'ADD_REPLY', postId, id, reply } );
		},

	setFeedbackStatus:
		( postId, id, status ) =>
		async ( { dispatch } ) => {
			const data = await apiFetch( {
				path: `/erdo-draft-links/v1/posts/${ postId }/feedback/${ id }/status`,
				method: 'POST',
				data: { status },
			} );
			dispatch( { type: 'SET_FEEDBACK_STATUS', postId, id, status, openCount: data.open_count } );
		},
};

const selectors = {
	getLinks: ( state, postId ) => ( state.posts[ postId ] || DEFAULT_POST ).links,
	hasLoadedLinks: ( state, postId ) => ( state.posts[ postId ] || DEFAULT_POST ).loaded,
	getReview: ( state, postId ) => ( state.posts[ postId ] || DEFAULT_POST ).review,
	getOpenCount: ( state, postId ) => ( state.posts[ postId ] || DEFAULT_POST ).openCount,
	getFeedback: ( state, postId ) => ( state.feedback[ postId ] || DEFAULT_FEEDBACK ).items,
	hasLoadedFeedback: ( state, postId ) => ( state.feedback[ postId ] || DEFAULT_FEEDBACK ).loaded,
};

export const store = createReduxStore( STORE_NAME, { reducer, actions, selectors } );

register( store );
