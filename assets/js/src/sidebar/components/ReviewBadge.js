import { __, sprintf } from '@wordpress/i18n';
import { relative } from '../utils';

/**
 * Latest review decision on the post: approved / changes requested, and a
 * warning when the post was edited after that decision.
 */
export default function ReviewBadge( { review } ) {
	if ( ! review ) {
		return (
			<p className="erdo-dl-review erdo-dl-review--none">
				{ __( 'No review decision yet.', 'erdo-draft-links' ) }
			</p>
		);
	}

	const approved = 'approved' === review.status;
	const when = relative( review.at );

	let modifier = approved ? 'approved' : 'changes';
	let text = approved
		? /* translators: 1: reviewer name, 2: relative time, e.g. "2 hours ago" */
		  sprintf( __( 'Approved by %1$s · %2$s', 'erdo-draft-links' ), review.reviewer, when )
		: /* translators: 1: reviewer name, 2: relative time, e.g. "2 hours ago" */
		  sprintf( __( 'Changes requested by %1$s · %2$s', 'erdo-draft-links' ), review.reviewer, when );

	if ( review.is_stale ) {
		modifier = 'stale';
		text = approved
			? /* translators: %s: reviewer name */
			  sprintf( __( 'Edited since %s approved it', 'erdo-draft-links' ), review.reviewer )
			: /* translators: %s: reviewer name */
			  sprintf( __( 'Edited since %s requested changes', 'erdo-draft-links' ), review.reviewer );
	}

	return (
		<p className={ `erdo-dl-review erdo-dl-review--${ modifier }` }>
			<span className="erdo-dl-review__dot" aria-hidden="true" />
			{ text }
		</p>
	);
}
