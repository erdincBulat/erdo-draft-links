import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { PluginPostPublishPanel } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { store } from '../store';
import { usePostContext } from '../utils';

/**
 * After publishing: draft links still work (they now just lead to the
 * public post), so offer to turn them off in one click.
 */
export default function PostPublishPanel() {
	const { postId } = usePostContext();
	const links = useSelect( ( select ) => select( store ).getLinks( postId ), [ postId ] );
	const { revokeLink } = useDispatch( store );
	const { createSuccessNotice, createErrorNotice } = useDispatch( noticesStore );
	const [ busy, setBusy ] = useState( false );

	if ( ! links.length ) {
		return null;
	}

	const turnOffAll = async () => {
		setBusy( true );
		try {
			await Promise.all( links.map( ( link ) => revokeLink( postId, link.id ) ) );
			createSuccessNotice( __( 'Share links turned off.', 'erdo-draft-links' ), { type: 'snackbar' } );
		} catch ( e ) {
			createErrorNotice( __( 'Could not turn off every link. Check the Share for review panel.', 'erdo-draft-links' ), { type: 'snackbar' } );
		} finally {
			setBusy( false );
		}
	};

	return (
		<PluginPostPublishPanel title={ __( 'Share links', 'erdo-draft-links' ) } initialOpen>
			<p>
				{ sprintf(
					/* translators: %d: number of active share links */
					_n(
						'%d share link for this post is still active. Now that the post is public you may not need it anymore.',
						'%d share links for this post are still active. Now that the post is public you may not need them anymore.',
						links.length,
						'erdo-draft-links'
					),
					links.length
				) }
			</p>
			<Button variant="secondary" onClick={ turnOffAll } isBusy={ busy } disabled={ busy }>
				{ _n( 'Turn off link', 'Turn off all links', links.length, 'erdo-draft-links' ) }
			</Button>
		</PluginPostPublishPanel>
	);
}
