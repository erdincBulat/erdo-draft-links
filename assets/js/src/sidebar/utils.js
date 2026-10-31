import { useEffect, useRef } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { humanTimeDiff } from '@wordpress/date';
import { __ } from '@wordpress/i18n';
import { SVG, Path } from '@wordpress/primitives';
import { store } from './store';

/* global erdoDraftLinksData */

// Icons (paths from @wordpress/icons, inlined: that package isn't a WP script).
export const linkIcon = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
		<Path d="M10 17.389H8.444A5.194 5.194 0 1 1 8.444 7H10v1.5H8.444a3.694 3.694 0 0 0 0 7.389H10v1.5ZM14 7h1.556a5.194 5.194 0 0 1 0 10.39H14v-1.5h1.556a3.694 3.694 0 0 0 0-7.39H14V7Zm-4.5 6h5v-1.5h-5V13Z" />
	</SVG>
);
export const commentIcon = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
		<Path d="M18 4H6c-1.1 0-2 .9-2 2v12.9c0 .6.5 1.1 1.1 1.1.3 0 .5-.1.8-.3L8.5 17H18c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm.5 11c0 .3-.2.5-.5.5H7.9l-2.4 2.4V6c0-.3.2-.5.5-.5h12c.3 0 .5.2.5.5v9z" />
	</SVG>
);
export const moreIcon = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
		<Path d="M13 19h-2v-2h2v2zm0-6h-2v-2h2v2zm0-6h-2V5h2v2z" />
	</SVG>
);
export const lockIcon = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
		<Path d="M17 10h-1.2V7c0-2.1-1.7-3.8-3.8-3.8-2.1 0-3.8 1.7-3.8 3.8v3H7c-.6 0-1 .4-1 1v8c0 .6.4 1 1 1h10c.6 0 1-.4 1-1v-8c0-.6-.4-1-1-1zm-2.8 0H9.8V7c0-1.2 1-2.2 2.2-2.2s2.2 1 2.2 2.2v3z" />
	</SVG>
);

export const settings = erdoDraftLinksData;

/**
 * The post being edited, and whether draft links apply to it.
 */
export function usePostContext() {
	return useSelect( ( select ) => {
		const editor = select( editorStore );
		const postType = editor.getCurrentPostType();
		return {
			postId: editor.getCurrentPostId(),
			postType,
			isSupported: settings.postTypes.includes( postType ),
			// Auto-drafts have no stable row yet; the server rejects them.
			isNew: editor.isEditedPostNew(),
			isPublished: editor.isCurrentPostPublished(),
		};
	}, [] );
}

/**
 * Loads links (and the post's review summary) once, then refreshes when the
 * editor tab regains focus (links may change elsewhere) and after each save
 * (a save can make an approval stale).
 */
export function useLinksLoader( postId, enabled = true ) {
	const { fetchLinks } = useDispatch( store );
	const { createErrorNotice } = useDispatch( noticesStore );
	const isSaving = useSelect( ( select ) => {
		const editor = select( editorStore );
		return editor.isSavingPost() && ! editor.isAutosavingPost();
	}, [] );
	const wasSaving = useRef( false );

	useEffect( () => {
		if ( ! postId || ! enabled ) {
			return;
		}
		const load = () =>
			fetchLinks( postId ).catch( () =>
				createErrorNotice( __( 'Could not load draft links.', 'erdo-draft-links' ), {
					type: 'snackbar',
					id: 'erdo-draft-links-load',
				} )
			);
		load();

		const onFocus = () => {
			if ( document.visibilityState === 'visible' ) {
				load();
			}
		};
		window.addEventListener( 'focus', onFocus );
		document.addEventListener( 'visibilitychange', onFocus );
		return () => {
			window.removeEventListener( 'focus', onFocus );
			document.removeEventListener( 'visibilitychange', onFocus );
		};
	}, [ postId, enabled ] );

	useEffect( () => {
		if ( wasSaving.current && ! isSaving && postId && enabled ) {
			fetchLinks( postId ).catch( () => {} );
		}
		wasSaving.current = isSaving;
	}, [ isSaving ] );
}

export async function copyText( text ) {
	try {
		await window.navigator.clipboard.writeText( text );
		return true;
	} catch ( e ) {
		const el = document.createElement( 'textarea' );
		el.value = text;
		el.setAttribute( 'readonly', '' );
		el.style.position = 'fixed';
		el.style.opacity = '0';
		document.body.appendChild( el );
		el.select();
		const ok = document.execCommand( 'copy' );
		document.body.removeChild( el );
		return ok;
	}
}

/** "3 days ago" / "in 3 days" from an ISO 8601 date. */
export function relative( iso ) {
	return iso ? humanTimeDiff( iso, new Date() ) : '';
}

export function errorMessage( err, fallback ) {
	return ( err && ( err.error || err.message ) ) || fallback;
}

/** Opens the Feedback sidebar (WP 6.6+ unified scope, older edit-post fallback). */
export function openFeedbackSidebar( dispatch ) {
	const name = 'erdo-draft-links/erdo-draft-links-feedback';
	const iface = dispatch( 'core/interface' );
	if ( iface && iface.enableComplementaryArea ) {
		iface.enableComplementaryArea( 'core', name );
		return;
	}
	const editPost = dispatch( 'core/edit-post' );
	if ( editPost && editPost.openGeneralSidebar ) {
		editPost.openGeneralSidebar( name );
	}
}
