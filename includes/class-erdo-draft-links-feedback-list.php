<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Erdo_Draft_Links_Feedback_List extends WP_List_Table {

	private Erdo_Draft_Links_DB $db;
	private int $post_id;

	public function __construct( Erdo_Draft_Links_DB $db, int $post_id = 0 ) {
		parent::__construct( array(
			'singular' => 'erdo_draft_feedback',
			'plural'   => 'erdo_draft_feedbacks',
			'ajax'     => false,
		) );
		$this->db      = $db;
		$this->post_id = $post_id;
	}

	public function get_columns(): array {
		return array(
			'post_title' => __( 'Post', 'erdo-draft-links' ),
			'author'     => __( 'Name', 'erdo-draft-links' ),
			'message'    => __( 'Feedback', 'erdo-draft-links' ),
			'status'     => __( 'Status', 'erdo-draft-links' ),
			'date'       => __( 'Date', 'erdo-draft-links' ),
		);
	}

	protected function column_post_title( $item ): string {
		$post  = get_post( (int) $item->post_id );
		/* translators: %d: post ID number */
		$title = $post ? esc_html( get_the_title( $post ) ) : sprintf( __( '(Post #%d — deleted)', 'erdo-draft-links' ), (int) $item->post_id );

		$row_actions = array();
		if ( $post ) {
			$row_actions['edit'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( get_edit_post_link( $post->ID ) ),
				__( 'Edit Post', 'erdo-draft-links' )
			);
		}

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'erdo_draft_feedback_delete',
					'feedback_id' => $item->id,
					'tab'         => 'feedback',
				),
				admin_url( 'tools.php?page=erdo-draft-links-manager' )
			),
			'erdo_draft_feedback_delete_' . $item->id
		);
		$row_actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\')">%s</a>',
			esc_url( $delete_url ),
			esc_js( __( 'Are you sure you want to delete this feedback?', 'erdo-draft-links' ) ),
			__( 'Delete', 'erdo-draft-links' )
		);

		return $title . $this->row_actions( $row_actions );
	}

	protected function column_author( $item ): string {
		return esc_html( $item->author_name );
	}

	protected function column_message( $item ): string {
		return esc_html( $item->message );
	}

	protected function column_status( $item ): string {
		$status = $item->status;
		$label  = Erdo_Draft_Links_DB::get_feedback_status_label( $status );

		$next_status = Erdo_Draft_Links_DB::FEEDBACK_STATUS_DONE === $status
			? Erdo_Draft_Links_DB::FEEDBACK_STATUS_OPEN
			: Erdo_Draft_Links_DB::FEEDBACK_STATUS_DONE;

		$next_label = Erdo_Draft_Links_DB::FEEDBACK_STATUS_DONE === $next_status
			? __( 'Mark as Completed', 'erdo-draft-links' )
			: __( 'Mark as In Progress', 'erdo-draft-links' );

		$toggle_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'erdo_draft_feedback_status',
					'feedback_id' => $item->id,
					'status'      => $next_status,
					'tab'         => 'feedback',
				),
				admin_url( 'tools.php?page=erdo-draft-links-manager' )
			),
			'erdo_draft_feedback_status_' . $item->id
		);

		return sprintf(
			'<span class="erdo-draft-feedback-status erdo-draft-feedback-status--%1$s">%2$s</span><br /><a href="%3$s">%4$s</a>',
			esc_attr( $status ),
			esc_html( $label ),
			esc_url( $toggle_url ),
			esc_html( $next_label )
		);
	}

	protected function column_date( $item ): string {
		$ts = strtotime( $item->created_at );
		return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) );
	}

	protected function column_default( $item, $column_name ): string {
		return '';
	}

	public function prepare_items(): void {
		$per_page     = 20;
		$current_page = $this->get_pagenum();

		$this->set_pagination_args( array(
			'total_items' => $this->db->get_feedback_count( $this->post_id ),
			'per_page'    => $per_page,
		) );

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			array(),
		);

		$this->items = $this->db->get_feedback_paginated( $current_page, $per_page, $this->post_id );
	}
}
