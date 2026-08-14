import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import ErdoDraftLinksPanel from './components/ErdoDraftLinksPanel';

/* global erdoDraftLinksData */
apiFetch.use( apiFetch.createNonceMiddleware( erdoDraftLinksData.nonce ) );

const ErdoDraftLinksIcon = () => (
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
		<path d="M12 2C8.13 2 5 5.13 5 9v7l-2 2v1h18v-1l-2-2V9c0-3.87-3.13-7-7-7zm0 20c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2z" />
	</svg>
);

const ErdoDraftLinksSidebar = () => {
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);

	if ( ! erdoDraftLinksData.postTypes.includes( postType ) ) {
		return null;
	}

	return (
		<PluginSidebar
			name="erdo-draft-links-sidebar"
			title={ __( 'Erdo Draft Links', 'erdo-draft-links' ) }
			icon={ <ErdoDraftLinksIcon /> }
		>
			<ErdoDraftLinksPanel />
		</PluginSidebar>
	);
};

registerPlugin( 'erdo-draft-links', { render: ErdoDraftLinksSidebar } );
