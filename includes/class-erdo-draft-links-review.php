<?php
defined( 'ABSPATH' ) || exit;

/**
 * Review decisions: a preview visitor approves the draft or requests
 * changes. The decision is stored on the link, as a feedback item (so it
 * shows up in the conversation), and as post meta holding the latest
 * decision across all links for quick badges in lists and the editor.
 *
 * A decision is "stale" once the post was modified after it was made.
 */
class Erdo_Draft_Links_Review {

	const META_KEY = '_erdo_dl_review';

	private Erdo_Draft_Links_DB $db;
	private Erdo_Draft_Links_Session $session;
	private Erdo_Draft_Links_Notifications $notifications;

	public function __construct( Erdo_Draft_Links_DB $db, Erdo_Draft_Links_Session $session, Erdo_Draft_Links_Notifications $notifications ) {
		$this->db            = $db;
		$this->session       = $session;
		$this->notifications = $notifications;
	}

	public function register( Erdo_Draft_Links_Loader $loader ): void {
		$loader->add_action( 'init', $this, 'register_meta' );
		$loader->add_action( 'rest_api_init', $this, 'register_rest_routes' );
	}

	public function register_meta(): void {
		foreach ( Erdo_Draft_Links_Post_Types::get_supported() as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_KEY,
				array(
					'type'          => 'object',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static fn() => false,
				)
			);
		}
	}

	public function register_rest_routes(): void {
		register_rest_route(
			'erdo-draft-links/v1',
			'/review',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_submit_review' ),
				// Access is the preview cookie + nonce, checked in the callback.
				'permission_callback' => '__return_true',
				'args'                => array(
					'decision' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( Erdo_Draft_Links_DB::REVIEW_APPROVED, Erdo_Draft_Links_DB::REVIEW_CHANGES ),
					),
					'name'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'message'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'nonce'    => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_submit_review( WP_REST_Request $request ) {
		$session = $this->session->current();
		if ( null === $session ) {
			return new WP_Error( 'erdo_invalid_session', __( 'Your preview session has ended. Open the draft link again.', 'erdo-draft-links' ), array( 'status' => 403 ) );
		}

		if ( ! wp_verify_nonce( (string) $request->get_param( 'nonce' ), 'erdo_draft_feedback_' . $session['link_id'] ) ) {
			return new WP_Error( 'erdo_invalid_nonce', __( 'An error occurred. Please try again.', 'erdo-draft-links' ), array( 'status' => 403 ) );
		}

		$decision = (string) $request->get_param( 'decision' );
		$name     = mb_substr( (string) $request->get_param( 'name' ), 0, 100 );
		$message  = mb_substr( (string) $request->get_param( 'message' ), 0, 5000 );

		if ( '' === $name ) {
			return new WP_Error( 'erdo_missing_name', __( 'Please enter your name.', 'erdo-draft-links' ), array( 'status' => 400 ) );
		}
		if ( Erdo_Draft_Links_DB::REVIEW_CHANGES === $decision && '' === $message ) {
			return new WP_Error( 'erdo_missing_message', __( 'Please describe the changes you would like.', 'erdo-draft-links' ), array( 'status' => 400 ) );
		}

		$this->record( $session['link'], $decision, $name, $message );

		return rest_ensure_response( array_merge( array( 'success' => true ), $this->get_link_review( $this->db->get_link_by_id( $session['link_id'] ) ) ) );
	}

	public function record( object $link, string $decision, string $name, string $message ): void {
		$post_id  = (int) $link->post_id;
		$modified = (string) get_post_field( 'post_modified_gmt', $post_id );

		$this->db->record_review( (int) $link->id, $decision, $name, $modified );

		$this->db->add_feedback(
			$post_id,
			(int) $link->id,
			$name,
			$message,
			array(
				'type' => Erdo_Draft_Links_DB::REVIEW_APPROVED === $decision ? Erdo_Draft_Links_DB::FEEDBACK_TYPE_APPROVAL : Erdo_Draft_Links_DB::FEEDBACK_TYPE_CHANGES,
			)
		);

		update_post_meta(
			$post_id,
			self::META_KEY,
			array(
				'status'   => $decision,
				'link_id'  => (int) $link->id,
				'reviewer' => $name,
				'at'       => gmdate( 'Y-m-d H:i:s' ),
				'modified' => $modified,
			)
		);

		$this->notifications->review( $link, $decision, $name, $message );

		/**
		 * Fires after a reviewer approved a draft or requested changes.
		 *
		 * @param object $link     The link row (before the decision was stored).
		 * @param string $decision 'approved' or 'changes_requested'.
		 * @param string $name     Reviewer name as entered.
		 * @param string $message  Optional message.
		 */
		do_action( 'erdo_draft_links_review_submitted', $link, $decision, $name, $message );
	}

	/**
	 * Latest decision for a post across all its links, or null if none.
	 *
	 * @return array{status: string, reviewer: string, at: string, link_id: int, is_stale: bool}|null
	 */
	public function get_post_review( int $post_id ): ?array {
		$meta = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $meta ) || empty( $meta['status'] ) ) {
			return null;
		}

		return array(
			'status'   => (string) $meta['status'],
			'reviewer' => (string) ( $meta['reviewer'] ?? '' ),
			'at'       => (string) ( $meta['at'] ?? '' ),
			'link_id'  => (int) ( $meta['link_id'] ?? 0 ),
			'is_stale' => $this->is_stale( $post_id, (string) ( $meta['modified'] ?? '' ) ),
		);
	}

	/**
	 * @return array{review_status: string, reviewed_at: ?string, reviewer_name: string, is_stale: bool}
	 */
	public function get_link_review( ?object $link ): array {
		if ( ! $link ) {
			return array(
				'review_status' => Erdo_Draft_Links_DB::REVIEW_PENDING,
				'reviewed_at'   => null,
				'reviewer_name' => '',
				'is_stale'      => false,
			);
		}

		$status = (string) $link->review_status;

		return array(
			'review_status' => $status,
			'reviewed_at'   => $link->reviewed_at ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $link->reviewed_at ) ) : null,
			'reviewer_name' => (string) $link->reviewer_name,
			'is_stale'      => Erdo_Draft_Links_DB::REVIEW_PENDING !== $status && $this->is_stale( (int) $link->post_id, (string) $link->reviewed_modified ),
		);
	}

	private function is_stale( int $post_id, string $reviewed_modified ): bool {
		if ( '' === $reviewed_modified ) {
			return false;
		}
		$current = (string) get_post_field( 'post_modified_gmt', $post_id );
		return '' !== $current && strtotime( $current ) > strtotime( $reviewed_modified );
	}
}
