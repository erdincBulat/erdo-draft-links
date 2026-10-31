<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Draft Links → Feedback: comments, notes on the page and decisions from
 * every share link, each with its reply thread.
 *
 * The table sits inside the bulk-action form, so each row's reply box points
 * at its own <form>, printed after the table (render_reply_forms()), through
 * the HTML `form` attribute — forms can't be nested.
 */
class Erdo_Draft_Links_Feedback_List extends WP_List_Table {

	private Erdo_Draft_Links_DB $db;
	private int $author_id;
	private string $current_filter;
	private int $post_id;
	private int $link_id;

	/** @var array<int, object[]> Replies keyed by parent feedback id. */
	private array $replies = array();

	/** @var array<int, string> Link labels keyed by link id. */
	private array $link_labels = array();

	/**
	 * @param int $author_id Only list feedback on this author's posts (0 = all).
	 */
	public function __construct( Erdo_Draft_Links_DB $db, int $author_id = 0 ) {
		parent::__construct(
			array(
				'singular' => 'erdo_draft_feedback',
				'plural'   => 'erdo_draft_feedbacks',
				'ajax'     => false,
			)
		);
		$this->db        = $db;
		$this->author_id = $author_id;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$this->current_filter = isset( $_GET['feedback_status'] ) ? sanitize_key( $_GET['feedback_status'] ) : 'open';
		$this->post_id        = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$this->link_id        = isset( $_GET['link_id'] ) ? absint( $_GET['link_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	public function get_columns(): array {
		return array(
			'cb'      => '<input type="checkbox" />',
			'message' => __( 'Feedback', 'erdo-draft-links' ),
			'post'    => __( 'Post', 'erdo-draft-links' ),
			'status'  => __( 'Status', 'erdo-draft-links' ),
			'date'    => __( 'Received', 'erdo-draft-links' ),
		);
	}

	protected function get_primary_column_name(): string {
		return 'message';
	}

	protected function get_bulk_actions(): array {
		return array(
			'resolve' => __( 'Mark resolved', 'erdo-draft-links' ),
			'reopen'  => __( 'Reopen', 'erdo-draft-links' ),
			'delete'  => __( 'Delete', 'erdo-draft-links' ),
		);
	}

	/**
	 * Keeps the post/link filter when switching views.
	 */
	private function filter_args(): array {
		return array_filter(
			array(
				'post_id' => $this->post_id,
				'link_id' => $this->link_id,
			)
		);
	}

	private function query( string $status ): array {
		return array(
			'status'    => $status,
			'author_id' => $this->author_id,
			'post_id'   => $this->post_id,
			'link_id'   => $this->link_id,
		);
	}

	public function get_views(): array {
		$labels = array(
			'open'      => __( 'Open', 'erdo-draft-links' ),
			'resolved'  => __( 'Resolved', 'erdo-draft-links' ),
			'decisions' => __( 'Decisions', 'erdo-draft-links' ),
			'all'       => __( 'All', 'erdo-draft-links' ),
		);

		$views = array();
		foreach ( $labels as $key => $label ) {
			$views[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( Erdo_Draft_Links_Admin_Pages::url( 'feedback', array( 'feedback_status' => $key ) + $this->filter_args() ) ),
				$this->current_filter === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $this->db->get_feedback_count( $this->query( $key ) ) ) )
			);
		}
		return $views;
	}

	/**
	 * "Showing feedback on <post> · <link>" with a way back to everything.
	 */
	public function render_filter_notice(): void {
		if ( ! $this->post_id && ! $this->link_id ) {
			return;
		}

		$link    = $this->link_id ? $this->db->get_link_by_id( $this->link_id ) : null;
		$post_id = $this->post_id ?: ( $link ? (int) $link->post_id : 0 );
		$parts   = array();
		if ( $post_id ) {
			$parts[] = get_the_title( $post_id ) ?: __( '(no title)', 'erdo-draft-links' );
		}
		if ( $link ) {
			$parts[] = '' !== (string) $link->label ? (string) $link->label : __( 'Untitled link', 'erdo-draft-links' );
		}

		printf(
			'<p class="erdo-dl-filter-note">%1$s <strong>%2$s</strong> <a href="%3$s">%4$s</a></p>',
			esc_html__( 'Showing feedback on', 'erdo-draft-links' ),
			esc_html( implode( ' · ', $parts ) ),
			esc_url( Erdo_Draft_Links_Admin_Pages::url( 'feedback', array( 'feedback_status' => $this->current_filter ) ) ),
			esc_html__( 'Show all', 'erdo-draft-links' )
		);
	}

	public function no_items(): void {
		if ( 'open' === $this->current_filter ) {
			esc_html_e( 'Nothing waiting for you. New comments from reviewers show up here.', 'erdo-draft-links' );
			return;
		}
		esc_html_e( 'No feedback here.', 'erdo-draft-links' );
	}

	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="erdo-dl-fb-%1$d">%2$s</label><input type="checkbox" id="erdo-dl-fb-%1$d" name="feedback_ids[]" value="%1$d" />',
			(int) $item->id,
			esc_html__( 'Select feedback', 'erdo-draft-links' )
		);
	}

	private function type_badge( object $item ): string {
		$types = array(
			Erdo_Draft_Links_DB::FEEDBACK_TYPE_COMMENT    => array( 'comment', __( 'Comment', 'erdo-draft-links' ), 'admin-comments' ),
			Erdo_Draft_Links_DB::FEEDBACK_TYPE_ANNOTATION => array( 'note', __( 'Note on page', 'erdo-draft-links' ), 'location' ),
			Erdo_Draft_Links_DB::FEEDBACK_TYPE_APPROVAL   => array( 'approved', __( 'Approved', 'erdo-draft-links' ), 'yes-alt' ),
			Erdo_Draft_Links_DB::FEEDBACK_TYPE_CHANGES    => array( 'changes', __( 'Changes requested', 'erdo-draft-links' ), 'edit' ),
		);
		list( $class, $label, $icon ) = $types[ $item->type ] ?? $types[ Erdo_Draft_Links_DB::FEEDBACK_TYPE_COMMENT ];

		return sprintf(
			'<span class="erdo-dl-type erdo-dl-type--%1$s"><span class="dashicons dashicons-%2$s" aria-hidden="true"></span>%3$s</span>',
			esc_attr( $class ),
			esc_attr( $icon ),
			esc_html( $label )
		);
	}

	protected function column_message( $item ): string {
		$out  = '<div class="erdo-dl-fb-head">' . $this->type_badge( $item );
		$out .= '<strong class="erdo-dl-fb-author">' . esc_html( $item->author_name ) . '</strong>';
		if ( '' !== (string) $item->author_email ) {
			$out .= sprintf( ' <a class="erdo-dl-muted" href="mailto:%1$s">%2$s</a>', esc_attr( $item->author_email ), esc_html( $item->author_email ) );
		}
		$out .= '</div>';

		if ( '' !== (string) $item->message ) {
			$out .= '<div class="erdo-dl-fb-message">' . nl2br( esc_html( $item->message ) ) . '</div>';
		}

		$replies = $this->replies[ (int) $item->id ] ?? array();
		if ( $replies ) {
			$out .= '<ul class="erdo-dl-fb-thread">';
			foreach ( $replies as $reply ) {
				$out .= sprintf(
					'<li class="%1$s"><strong>%2$s</strong>%3$s <span class="erdo-dl-muted">%4$s</span><div>%5$s</div></li>',
					(int) $reply->user_id > 0 ? 'is-staff' : '',
					esc_html( $reply->author_name ),
					(int) $reply->user_id > 0 ? ' <span class="erdo-dl-badge erdo-dl-badge--team">' . esc_html__( 'Team', 'erdo-draft-links' ) . '</span>' : '',
					esc_html( $this->ago( $reply->created_at ) ),
					nl2br( esc_html( $reply->message ) )
				);
			}
			$out .= '</ul>';
		}

		$form_id = 'erdo-dl-reply-' . (int) $item->id;
		$out    .= sprintf(
			'<details class="erdo-dl-fb-reply"><summary>%1$s</summary><label class="screen-reader-text" for="%2$s-text">%3$s</label><textarea id="%2$s-text" name="reply" form="%2$s" rows="3" class="widefat" required></textarea><p class="description">%4$s</p><button type="submit" form="%2$s" class="button button-primary button-small">%5$s</button></details>',
			esc_html__( 'Reply', 'erdo-draft-links' ),
			esc_attr( $form_id ),
			/* translators: %s: reviewer name */
			esc_html( sprintf( __( 'Reply to %s', 'erdo-draft-links' ), $item->author_name ) ),
			'' !== (string) $item->author_email
				? esc_html__( 'The reviewer gets an email with your reply and sees it on the draft.', 'erdo-draft-links' )
				: esc_html__( 'The reviewer sees your reply next time they open the draft.', 'erdo-draft-links' ),
			esc_html__( 'Send reply', 'erdo-draft-links' )
		);

		return $out . $this->row_actions( $this->item_actions( $item ) );
	}

	private function action_url( string $action, object $item, array $extra = array() ): string {
		return wp_nonce_url(
			Erdo_Draft_Links_Admin_Pages::url(
				'feedback',
				array(
					'action'      => $action,
					'feedback_id' => (int) $item->id,
				) + $extra
			),
			$action . '_' . $item->id
		);
	}

	private function item_actions( object $item ): array {
		$actions = array();

		if ( Erdo_Draft_Links_DB::FEEDBACK_TYPE_APPROVAL !== $item->type ) {
			$resolved          = Erdo_Draft_Links_DB::FEEDBACK_STATUS_DONE === $item->status;
			$actions['status'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $this->action_url( 'erdo_draft_feedback_status', $item, array( 'status' => $resolved ? Erdo_Draft_Links_DB::FEEDBACK_STATUS_OPEN : Erdo_Draft_Links_DB::FEEDBACK_STATUS_DONE ) ) ),
				$resolved ? esc_html__( 'Reopen', 'erdo-draft-links' ) : esc_html__( 'Mark resolved', 'erdo-draft-links' )
			);
		}
		$actions['delete'] = sprintf(
			'<a href="%1$s" class="submitdelete" onclick="return confirm(\'%2$s\')">%3$s</a>',
			esc_url( $this->action_url( 'erdo_draft_feedback_delete', $item ) ),
			esc_js( __( 'Delete this feedback and its replies?', 'erdo-draft-links' ) ),
			esc_html__( 'Delete', 'erdo-draft-links' )
		);
		return $actions;
	}

	protected function column_post( $item ): string {
		$post = get_post( (int) $item->post_id );
		if ( ! $post ) {
			/* translators: %d: post ID number */
			return esc_html( sprintf( __( '(Post #%d — deleted)', 'erdo-draft-links' ), (int) $item->post_id ) );
		}

		$edit = get_edit_post_link( $post->ID );
		$out  = $edit
			? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $edit ), esc_html( get_the_title( $post ) ?: __( '(no title)', 'erdo-draft-links' ) ) )
			: esc_html( get_the_title( $post ) );

		$label = $this->link_labels[ (int) $item->link_id ] ?? '';
		if ( '' !== $label ) {
			/* translators: %s: share link label */
			$out .= '<span class="erdo-dl-cell-sub">' . esc_html( sprintf( __( 'via “%s”', 'erdo-draft-links' ), $label ) ) . '</span>';
		}
		$out .= sprintf(
			'<span class="erdo-dl-cell-sub"><a href="%1$s">%2$s</a></span>',
			esc_url( Erdo_Draft_Links_Admin_Pages::url( 'feedback', array( 'post_id' => $post->ID, 'feedback_status' => $this->current_filter ) ) ),
			esc_html__( 'All feedback on this post', 'erdo-draft-links' )
		);
		return $out;
	}

	protected function column_status( $item ): string {
		if ( Erdo_Draft_Links_DB::FEEDBACK_TYPE_APPROVAL === $item->type ) {
			return '<span class="erdo-dl-muted">—</span>';
		}
		return Erdo_Draft_Links_DB::FEEDBACK_STATUS_DONE === $item->status
			? '<span class="erdo-dl-badge erdo-dl-badge--off">' . esc_html__( 'Resolved', 'erdo-draft-links' ) . '</span>'
			: '<span class="erdo-dl-badge erdo-dl-badge--open">' . esc_html__( 'Open', 'erdo-draft-links' ) . '</span>';
	}

	protected function column_date( $item ): string {
		$ts = strtotime( $item->created_at . ' UTC' );
		return sprintf(
			'<span title="%1$s">%2$s</span>',
			esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) ),
			esc_html( $this->ago( $item->created_at ) )
		);
	}

	private function ago( string $gmt ): string {
		/* translators: %s: human-readable time, e.g. "2 hours" */
		return sprintf( __( '%s ago', 'erdo-draft-links' ), human_time_diff( strtotime( $gmt . ' UTC' ), time() ) );
	}

	protected function column_default( $item, $column_name ): string {
		return '';
	}

	/**
	 * One empty form per row; the row's textarea and button join it through
	 * their `form` attribute.
	 */
	public function render_reply_forms(): void {
		foreach ( $this->items as $item ) {
			printf( '<form id="erdo-dl-reply-%d" method="post" hidden>', (int) $item->id );
			wp_nonce_field( 'erdo_draft_feedback_reply_' . $item->id );
			printf(
				'<input type="hidden" name="action" value="erdo_draft_feedback_reply" /><input type="hidden" name="feedback_id" value="%d" />',
				(int) $item->id
			);
			echo '</form>';
		}
	}

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'erdo_draft_feedback_per_page', 20 );
		$query    = $this->query( $this->current_filter );

		$this->set_pagination_args(
			array(
				'total_items' => $this->db->get_feedback_count( $query ),
				'per_page'    => $per_page,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array(), $this->get_primary_column_name() );

		$this->items   = $this->db->get_feedback_paginated( $this->get_pagenum(), $per_page, $query );
		$this->replies = $this->db->get_replies( wp_list_pluck( $this->items, 'id' ) );

		foreach ( array_unique( array_map( 'intval', wp_list_pluck( $this->items, 'link_id' ) ) ) as $link_id ) {
			$link                          = $link_id ? $this->db->get_link_by_id( $link_id ) : null;
			$this->link_labels[ $link_id ] = $link ? (string) $link->label : '';
		}
	}
}
