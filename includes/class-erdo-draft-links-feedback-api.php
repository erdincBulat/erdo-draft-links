<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API behind the editor's Feedback sidebar: all feedback on a post
 * (every link), with threads, and the editor's reply / resolve actions.
 * Every route requires edit_post on the post the item belongs to.
 */
class Erdo_Draft_Links_Feedback_Api {

	private Erdo_Draft_Links_DB $db;
	private Erdo_Draft_Links_Review $review;

	public function __construct( Erdo_Draft_Links_DB $db, Erdo_Draft_Links_Review $review ) {
		$this->db     = $db;
		$this->review = $review;
	}

	public function register( Erdo_Draft_Links_Loader $loader ): void {
		$loader->add_action( 'rest_api_init', $this, 'register_rest_routes' );
	}

	public function register_rest_routes(): void {
		$namespace = 'erdo-draft-links/v1';
		$post_arg  = array(
			'post_id' => array(
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
		);
		$item_args = $post_arg + array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			$namespace,
			'/posts/(?P<post_id>\d+)/feedback',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_list' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => $post_arg,
			)
		);

		register_rest_route(
			$namespace,
			'/posts/(?P<post_id>\d+)/feedback/(?P<id>\d+)/reply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_reply' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => $item_args + array(
					'message' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/posts/(?P<post_id>\d+)/feedback/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => $item_args + array(
					'status' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( Erdo_Draft_Links_DB::FEEDBACK_STATUS_OPEN, Erdo_Draft_Links_DB::FEEDBACK_STATUS_DONE ),
					),
				),
			)
		);
	}

	public function can_edit_post( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to see feedback on this post.', 'erdo-draft-links' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function rest_list( WP_REST_Request $request ): WP_REST_Response {
		nocache_headers();

		$post_id = (int) $request->get_param( 'post_id' );
		$items   = $this->db->get_feedback_for_post( $post_id );
		$replies = $this->db->get_replies( wp_list_pluck( $items, 'id' ) );
		$labels  = $this->link_labels( $items );

		return new WP_REST_Response(
			array(
				'items'      => array_map( fn( $item ) => $this->item_to_response( $item, $replies[ (int) $item->id ] ?? array(), $labels ), $items ),
				'open_count' => $this->db->count_open_feedback( $post_id ),
				'review'     => $this->review->get_post_review( $post_id ),
			),
			200
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_reply( WP_REST_Request $request ) {
		$item = $this->find_item( $request );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$message = mb_substr( trim( (string) $request->get_param( 'message' ) ), 0, 5000 );
		if ( '' === $message ) {
			return new WP_Error( 'erdo_missing_message', __( 'Write a reply first.', 'erdo-draft-links' ), array( 'status' => 400 ) );
		}

		$user     = wp_get_current_user();
		$reply_id = $this->db->add_reply( $item, (int) $user->ID, $user->display_name, $message );
		if ( ! $reply_id ) {
			return new WP_Error( 'erdo_reply_failed', __( 'Could not save the reply. Please try again.', 'erdo-draft-links' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after an editor replied to a feedback item.
		 *
		 * @param object $item     The top-level feedback item.
		 * @param int    $reply_id The new reply's id.
		 */
		do_action( 'erdo_draft_links_reply_added', $item, $reply_id );

		return new WP_REST_Response( $this->reply_to_response( $this->db->get_feedback_by_id( $reply_id ) ), 201 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_status( WP_REST_Request $request ) {
		$item = $this->find_item( $request );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$this->db->update_feedback_status( (int) $item->id, (string) $request->get_param( 'status' ) );

		return new WP_REST_Response(
			array(
				'status'     => (string) $request->get_param( 'status' ),
				'open_count' => $this->db->count_open_feedback( (int) $item->post_id ),
			),
			200
		);
	}

	/**
	 * A top-level item that belongs to the post in the route — so edit_post on
	 * one post can't be used to touch feedback on another.
	 *
	 * @return object|WP_Error
	 */
	private function find_item( WP_REST_Request $request ) {
		$item = $this->db->get_feedback_by_id( (int) $request->get_param( 'id' ) );
		if ( ! $item || (int) $item->post_id !== (int) $request->get_param( 'post_id' ) || 0 !== (int) $item->parent_id ) {
			return new WP_Error( 'erdo_not_found', __( 'This feedback no longer exists.', 'erdo-draft-links' ), array( 'status' => 404 ) );
		}
		return $item;
	}

	/**
	 * @return array<int, string> Link id => label.
	 */
	private function link_labels( array $items ): array {
		$labels = array();
		foreach ( array_unique( array_map( 'intval', wp_list_pluck( $items, 'link_id' ) ) ) as $link_id ) {
			$link              = $link_id ? $this->db->get_link_by_id( $link_id ) : null;
			$labels[ $link_id ] = $link && '' !== (string) $link->label ? (string) $link->label : '';
		}
		return $labels;
	}

	private function item_to_response( object $item, array $replies, array $labels ): array {
		return array(
			'id'           => (int) $item->id,
			'type'         => (string) $item->type,
			'status'       => (string) $item->status,
			'author_name'  => (string) $item->author_name,
			'author_email' => (string) $item->author_email,
			'message'      => (string) $item->message,
			'link_id'      => (int) $item->link_id,
			'link_label'   => $labels[ (int) $item->link_id ] ?? '',
			'selector'     => null !== $item->selector ? (string) $item->selector : null,
			'created_at'   => $this->iso( $item->created_at ),
			'replies'      => array_map( array( $this, 'reply_to_response' ), $replies ),
		);
	}

	public function reply_to_response( ?object $reply ): array {
		if ( ! $reply ) {
			return array();
		}
		return array(
			'id'          => (int) $reply->id,
			'author_name' => (string) $reply->author_name,
			'is_staff'    => (int) $reply->user_id > 0,
			'message'     => (string) $reply->message,
			'created_at'  => $this->iso( $reply->created_at ),
		);
	}

	private function iso( ?string $gmt ): ?string {
		return $gmt ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $gmt ) ) : null;
	}
}
