import { registerPlugin } from '@wordpress/plugins';
import apiFetch from '@wordpress/api-fetch';
import './store';
import { settings, usePostContext, useLinksLoader } from './utils';
import SharePanel from './components/SharePanel';
import PreviewMenuItem from './components/PreviewMenuItem';
import PostPublishPanel from './components/PostPublishPanel';
import FeedbackSidebar from './components/FeedbackSidebar';

apiFetch.use( apiFetch.createNonceMiddleware( settings.nonce ) );

const ErdoDraftLinks = () => {
	const { postId, isSupported, isNew } = usePostContext();
	useLinksLoader( postId, isSupported && ! isNew );

	if ( ! isSupported ) {
		return null;
	}

	return (
		<>
			<SharePanel />
			<PreviewMenuItem />
			<PostPublishPanel />
			<FeedbackSidebar />
		</>
	);
};

registerPlugin( 'erdo-draft-links', { render: ErdoDraftLinks } );
