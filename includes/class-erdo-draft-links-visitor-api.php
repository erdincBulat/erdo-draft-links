<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints used by the review bar a preview visitor sees on the shared
 * post. Access is the preview cookie (Session) plus a nonce scoped to the
 * link — there is no WordPress login. A visitor only ever reads and writes
 * feedback that belongs to their own link.
 */
class Erdo_Draft_Links_Visitor_Api {

	const NAMESPACE = 'erdo-draft-links/v1';

	/** Feedback posts allowed per link and visitor IP per window. */
	const POST_MAX    = 30;
	const POST_WINDOW = 10 * MINUTE_IN_SECONDS;

	private Erdo_Draft_Links_DB $db;
	private Erdo_Draft_Links_Token $token;
	private Erdo_Draft_Links_Session $session;
	private Erdo_Draft_Links_Review $review;
	private Erdo_Draft_Links_Notifications $notifications;

	public function __construct( Erdo_Draft_Links_DB $db, Erdo_Draft_Links_Token $token, Erdo_Draft_Links_Session $session, Erdo_Draft_Links_Review $review, Erdo_Draft_Links_Notifications $notifications ) {
		$this->db            = $db;
		$this->token         = $token;
		$this->session       = $session;
		$this->review        = $review;
		$this->notifications = $notifications;
	}

	public function register( Erdo_Draft_Links_Loader $loader ): void {
		$loader->add_action( 'rest_api_init', $this, 'register_rest_routes' );
		$loader->add_action( 'erdo_draft_links_reply_added', $this, 'notify_visitor_of_reply', 10, 2 );
	}

	public function register_rest_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/thread',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_thread' ),
				// Access is the preview cookie + nonce, checked in the callback.
				'permission_callback' => '__return_true',
				'args'                => array(
					'nonce' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/feedback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_submit_feedback' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'name'      => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'message'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'type'      => array(
						'type'    => 'string',
						'default' => Erdo_Draft_Links_DB::FEEDBACK_TYPE_COMMENT,
						'enum'    => array( Erdo_Draft_Links_DB::FEEDBACK_TYPE_COMMENT, Erdo_Draft_Links_DB::FEEDBACK_TYPE_ANNOTATION ),
					),
					'parent_id' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'selector'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'x_percent' => array(
						'type'    => 'number',
						'minimum' => 0,
						'maximum' => 100,
					),
					'y_percent' => array(
						'type'    => 'number',
						'minimum' => 0,
						'maximum' => 100,
					),
					'viewport'  => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^\d{1,5}x\d{1,5}$|^$',
					),
					'nonce'     => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Everything said through this link, oldest first, plus the link's
	 * current review decision.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_thread( WP_REST_Request $request ) {
		$session = $this->verify( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$items   = array_reverse( $this->db->get_feedback_by_link( $session['link_id'], 200 ) );
		$replies = $this->db->get_replies( wp_list_pluck( $items, 'id' ) );

		return rest_ensure_response(
			array(
				'items'  => array_map(
					fn( $item ) => $this->item_to_response( $item, $replies[ (int) $item->id ] ?? array() ),
					$items
				),
				'review' => $this->review->get_link_review( $this->db->get_link_by_id( $session['link_id'] ) ),
			)
		);
	}

	/**
	 * A comment, a note pinned to an element on the page, or a reply to an
	 * existing item on this link.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_submit_feedback( WP_REST_Request $request ) {
		$session = $this->verify( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$name    = mb_substr( trim( (string) $request->get_param( 'name' ) ), 0, 100 );
		$message = mb_substr( trim( (string) $request->get_param( 'message' ) ), 0, 5000 );
		$email   = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( '' === $name ) {
			return new WP_Error( 'erdo_missing_name', __( 'Please enter your name.', 'erdo-draft-links' ), array( 'status' => 400 ) );
		}
		if ( '' === $message ) {
			return new WP_Error( 'erdo_missing_message', __( 'Please write a message.', 'erdo-draft-links' ), array( 'status' => 400 ) );
		}

		$throttle_key = 'erdo_dl_posts_' . $session['link_id'] . '_' . md5( $this->client_ip() );
		$posted       = (int) get_transient( $throttle_key );
		if ( $posted >= self::POST_MAX ) {
			return new WP_Error( 'erdo_too_many', __( 'You have sent a lot of feedback in a short time. Please wait a few minutes.', 'erdo-draft-links' ), array( 'status' => 429 ) );
		}

		$parent_id = (int) $request->get_param( 'parent_id' );

		if ( $parent_id ) {
			$parent = $this->db->get_feedback_by_id( $parent_id );
			if ( ! $parent || (int) $parent->link_id !== $session['link_id'] || 0 !== (int) $parent->parent_id ) {
				return new WP_Error( 'erdo_not_found', __( 'This conversation no longer exists.', 'erdo-draft-links' ), array( 'status' => 404 ) );
			}
			$id = $this->db->add_reply( $parent, 0, $name, $message );
		} else {
			$extra = array(
				'type'         => (string) $request->get_param( 'type' ),
				'author_email' => is_email( $email ) ? $email : '',
				'viewport'     => (string) $request->get_param( 'viewport' ),
			);

			if ( Erdo_Draft_Links_DB::FEEDBACK_TYPE_ANNOTATION === $extra['type'] ) {
				$selector = mb_substr( trim( (string) $request->get_param( 'selector' ) ), 0, 1000 );
				if ( '' === $selector || null === $request->get_param( 'x_percent' ) || null === $request->get_param( 'y_percent' ) ) {
					return new WP_Error( 'erdo_missing_position', __( 'Pick a spot on the page for your note.', 'erdo-draft-links' ), array( 'status' => 400 ) );
				}
				$extra['selector']  = $selector;
				$extra['x_percent'] = round( (float) $request->get_param( 'x_percent' ), 4 );
				$extra['y_percent'] = round( (float) $request->get_param( 'y_percent' ), 4 );
			}

			$id = $this->db->add_feedback( $session['post_id'], $session['link_id'], $name, $message, $extra );
		}

		if ( ! $id ) {
			return new WP_Error( 'erdo_feedback_failed', __( 'An error occurred. Please try again.', 'erdo-draft-links' ), array( 'status' => 500 ) );
		}

		set_transient( $throttle_key, $posted + 1, self::POST_WINDOW );
		$this->notifications->feedback( $session['link'], $name, $message );

		$row = $this->db->get_feedback_by_id( $id );

		return new WP_REST_Response(
			$parent_id ? $this->reply_to_response( $row ) : $this->item_to_response( $row, array() ),
			201
		);
	}

	/**
	 * Emails a visitor who left their address when staff reply to their item.
	 *
	 * @param object $item     Top-level feedback item.
	 * @param int    $reply_id The reply's id.
	 */
	public function notify_visitor_of_reply( object $item, int $reply_id ): void {
		if ( '' === (string) $item->author_email ) {
			return;
		}

		$reply = $this->db->get_feedback_by_id( $reply_id );
		$link  = $this->db->get_link_by_id( (int) $item->link_id );
		if ( ! $reply || ! $link ) {
			return;
		}

		// Only point back to the draft while the link still opens.
		$url = $link->is_active && ! $this->token->is_expired( $link->expires_at ) ? $this->token->link_url( $link ) : '';

		$this->notifications->reply_to_visitor( $item, $reply, $url );
	}

	/**
	 * @return array{link_id: int, post_id: int, link: object}|WP_Error
	 */
	private function verify( WP_REST_Request $request ) {
		if ( ! $this->session->all() ) {
			return new WP_Error( 'erdo_invalid_session', __( 'Your preview session has ended. Open the draft link again.', 'erdo-draft-links' ), array( 'status' => 403 ) );
		}

		// The nonce was made for the link the page was rendered with; with
		// several preview cookies it is what picks the right one.
		$session = $this->session->for_nonce( (string) $request->get_param( 'nonce' ) );
		if ( null === $session ) {
			return new WP_Error( 'erdo_invalid_nonce', __( 'This page has been open for a long time. Reload it and try again.', 'erdo-draft-links' ), array( 'status' => 403 ) );
		}

		return $session;
	}

	/**
	 * What a visitor may see of an item — never the email address.
	 */
	private function item_to_response( object $item, array $replies ): array {
		$is_note = Erdo_Draft_Links_DB::FEEDBACK_TYPE_ANNOTATION === $item->type;

		return array(
			'id'          => (int) $item->id,
			'type'        => (string) $item->type,
			'status'      => (string) $item->status,
			'author_name' => (string) $item->author_name,
			'message'     => (string) $item->message,
			'created_at'  => $this->iso( $item->created_at ),
			'selector'    => $is_note ? (string) $item->selector : null,
			'x_percent'   => $is_note ? (float) $item->x_percent : null,
			'y_percent'   => $is_note ? (float) $item->y_percent : null,
			'replies'     => array_map( array( $this, 'reply_to_response' ), $replies ),
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

	private function client_ip(): string {
		// REMOTE_ADDR only — forwarded headers are client-controlled.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
