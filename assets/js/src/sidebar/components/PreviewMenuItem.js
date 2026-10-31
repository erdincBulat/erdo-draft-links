import { useSelect, useDispatch } from '@wordpress/data';
import * as editor from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';
import { store } from '../store';
import { settings, usePostContext, copyText, errorMessage, linkIcon } from '../utils';

// Added in WP 6.7; on 6.6 this item is simply not shown.
const { PluginPreviewMenuItem } = editor;

/**
 * "Copy share link" in the editor's Preview dropdown: copies the newest
 * active link, or creates one with the default settings if there is none.
 */
export default function PreviewMenuItem() {
	const { postId, isNew } = usePostContext();
	const links = useSelect( ( select ) => select( store ).getLinks( postId ), [ postId ] );
	const { generateLink } = useDispatch( store );
	const { createSuccessNotice, createErrorNotice } = useDispatch( noticesStore );

	if ( ! PluginPreviewMenuItem || isNew ) {
		return null;
	}

	const onClick = async () => {
		try {
			const link = links[ 0 ] || ( await generateLink( postId, { expiry: settings.defaultExpiry } ) );
			const copied = await copyText( link.url );
			createSuccessNotice(
				copied ? __( 'Share link copied.', 'erdo-draft-links' ) : __( 'Share link created.', 'erdo-draft-links' ),
				{ type: 'snackbar' }
			);
		} catch ( err ) {
			createErrorNotice( errorMessage( err, __( 'Could not create the link. Please try again.', 'erdo-draft-links' ) ), { type: 'snackbar' } );
		}
	};

	return (
		<PluginPreviewMenuItem icon={ linkIcon } onClick={ onClick }>
			{ __( 'Copy share link', 'erdo-draft-links' ) }
		</PluginPreviewMenuItem>
	);
}
