<?php
defined( 'ABSPATH' ) || exit;

/**
 * Standalone pages a draft link visitor sees instead of the draft: invalid,
 * expired, turned off, view limit reached, password prompt. Rendered without
 * the theme (so nothing about the site's content leaks) but with the site's
 * name and icon, so the visitor knows whose link it is.
 */
class Erdo_Draft_Links_Status_Page {

	const STATE_INVALID      = 'invalid';
	const STATE_EXPIRED      = 'expired';
	const STATE_REVOKED      = 'revoked';
	const STATE_LIMIT        = 'limit';
	const STATE_PASSWORD     = 'password';
	const STATE_REQUEST_SENT = 'request_sent';

	/**
	 * Outputs the page and exits.
	 *
	 * @param array{status?: int, form_action?: string, link_id?: int, error?: string, can_request?: bool} $args
	 */
	public function render( string $state, array $args = array() ): void {
		$copy = $this->copy( $state );

		status_header( $args['status'] ?? $copy['status'] );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );

		$edl_state       = $state;
		$edl_heading     = $copy['heading'];
		$edl_message     = $copy['message'];
		$edl_site_name   = get_bloginfo( 'name' );
		$edl_site_icon   = get_site_icon_url( 128 );
		$edl_form_action = (string) ( $args['form_action'] ?? '' );
		$edl_link_id     = (int) ( $args['link_id'] ?? 0 );
		$edl_error       = (string) ( $args['error'] ?? '' );
		$edl_can_request = ! empty( $args['can_request'] ) && '' !== $edl_form_action && $edl_link_id > 0;

		include ERDO_DRAFT_LINKS_PLUGIN_DIR . 'templates/status.php';
		exit;
	}

	/**
	 * @return array{heading: string, message: string, status: int}
	 */
	private function copy( string $state ): array {
		switch ( $state ) {
			case self::STATE_EXPIRED:
				return array(
					'heading' => __( 'This link has expired', 'erdo-draft-links' ),
					'message' => __( 'The draft you were invited to review is no longer available through this link. Ask the person who shared it for a new one.', 'erdo-draft-links' ),
					'status'  => 410,
				);
			case self::STATE_REVOKED:
				return array(
					'heading' => __( 'This link was turned off', 'erdo-draft-links' ),
					'message' => __( 'The person who shared this draft has turned the link off. If you still need access, ask them for a new link.', 'erdo-draft-links' ),
					'status'  => 410,
				);
			case self::STATE_LIMIT:
				return array(
					'heading' => __( 'This link has been used up', 'erdo-draft-links' ),
					'message' => __( 'This link could only be opened a limited number of times, and that limit has been reached. Ask the person who shared it for a new link.', 'erdo-draft-links' ),
					'status'  => 410,
				);
			case self::STATE_PASSWORD:
				return array(
					'heading' => __( 'This draft is password protected', 'erdo-draft-links' ),
					'message' => __( 'Enter the password you were given together with the link.', 'erdo-draft-links' ),
					'status'  => 200,
				);
			case self::STATE_REQUEST_SENT:
				return array(
					'heading' => __( 'Request sent', 'erdo-draft-links' ),
					'message' => __( 'We let the person who shared this draft know that you need a new link.', 'erdo-draft-links' ),
					'status'  => 200,
				);
			default:
				return array(
					'heading' => __( 'This link doesn’t work', 'erdo-draft-links' ),
					'message' => __( 'The link may be incomplete. Check that you copied the whole address, or ask the person who shared it to send it again.', 'erdo-draft-links' ),
					'status'  => 404,
				);
		}
	}
}
