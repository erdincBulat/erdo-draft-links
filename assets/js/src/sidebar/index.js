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
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true" focusable="false">
		<path d="M10 17.389H8.444A5.194 5.194 0 1 1 8.444 7H10v1.5H8.444a3.694 3.694 0 0 0 0 7.389H10v1.5ZM14 7h1.556a5.194 5.194 0 0 1 0 10.39H14v-1.5h1.556a3.694 3.694 0 0 0 0-7.39H14V7Zm-4.5 6h5v-1.5h-5V13Z" />
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
