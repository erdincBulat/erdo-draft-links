<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Erdo_Draft_Links_Admin_List extends WP_List_Table {

	private Erdo_Draft_Links_DB $db;
	private string $current_filter;

	public function __construct( Erdo_Draft_Links_DB $db ) {
		parent::__construct( array(
			'singular' => 'erdo_draft_link',
			'plural'   => 'erdo_draft_links',
			'ajax'     => false,
		) );
		$this->db             = $db;
		$this->current_filter = isset( $_GET['link_status'] ) ? sanitize_key( $_GET['link_status'] ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
	}

	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox" />',
			'post_title' => __( 'Post', 'erdo-draft-links' ),
			'ghost_link' => __( 'Link', 'erdo-draft-links' ),
			'status'     => __( 'Status', 'erdo-draft-links' ),
			'view_count' => __( 'Views', 'erdo-draft-links' ),
			'expires_at' => __( 'Expires', 'erdo-draft-links' ),
			'created_at' => __( 'Created', 'erdo-draft-links' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'view_count' => array( 'view_count', false ),
			'expires_at' => array( 'expires_at', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	protected function get_bulk_actions(): array {
		return array(
			'delete' => __( 'Delete', 'erdo-draft-links' ),
		);
	}

	public function get_views(): array {
		$counts = array(
			'all'     => $this->db->get_links_count( 'all' ),
			'active'  => $this->db->get_links_count( 'active' ),
			'expired' => $this->db->get_links_count( 'expired' ),
			'revoked' => $this->db->get_links_count( 'revoked' ),
		);

		$base_url = admin_url( 'tools.php?page=erdo-draft-links-manager' );
		$views    = array();

		$labels = array(
			'all'     => __( 'All', 'erdo-draft-links' ),
			'active'  => __( 'Active', 'erdo-draft-links' ),
			'expired' => __( 'Expired', 'erdo-draft-links' ),
			'revoked' => __( 'Revoked', 'erdo-draft-links' ),
		);

		foreach ( $labels as $key => $label ) {
			$url     = 'all' === $key ? $base_url : add_query_arg( 'link_status', $key, $base_url );
			$current = $this->current_filter === $key ? ' class="current"' : '';
			/* translators: %s: status label, %d: count */
			$views[ $key ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$current,
				esc_html( $label ),
				(int) $counts[ $key ]
			);
		}

		return $views;
	}

	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="link_ids[]" value="%d" />', (int) $item->id );
	}

	protected function column_post_title( $item ): string {
		$post  = get_post( (int) $item->post_id );
		/* translators: %d: post ID number */
		$title = $post ? esc_html( get_the_title( $post ) ) : sprintf( __( '(Post #%d — deleted)', 'erdo-draft-links' ), (int) $item->post_id );

		$ghost_url = $post ? add_query_arg( 'erdo_token', $item->token_raw, get_permalink( $post ) ) : '';

		$edit_link   = $post ? get_edit_post_link( $post->ID ) : '';
		$revoke_url  = wp_nonce_url(
			add_query_arg( array( 'action' => 'erdo_draft_link_revoke', 'link_id' => $item->id ), admin_url( 'tools.php?page=erdo-draft-links-manager' ) ),
			'erdo_draft_link_action_' . $item->id
		);
		$delete_url  = wp_nonce_url(
			add_query_arg( array( 'action' => 'erdo_draft_link_delete', 'link_id' => $item->id ), admin_url( 'tools.php?page=erdo-draft-links-manager' ) ),
			'erdo_draft_link_action_' . $item->id
		);

		$row_actions = array();
		if ( $edit_link ) {
			$row_actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), __( 'Edit Post', 'erdo-draft-links' ) );
		}
		if ( $ghost_url ) {
			$row_actions['view_page'] = sprintf(
				'<a href="%s" target="_blank">%s</a>',
				esc_url( $ghost_url ),
				__( 'View Page', 'erdo-draft-links' )
			);
		}
		if ( $item->is_active ) {
			$row_actions['revoke'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $revoke_url ),
				__( 'Revoke', 'erdo-draft-links' )
			);
		}
		$row_actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\')">%s</a>',
			esc_url( $delete_url ),
			esc_js( __( 'Are you sure you want to delete this ghost link?', 'erdo-draft-links' ) ),
			__( 'Delete', 'erdo-draft-links' )
		);

		return $title . $this->row_actions( $row_actions );
	}

	protected function column_ghost_link( $item ): string {
		$post = get_post( (int) $item->post_id );
		if ( ! $post ) {
			return '&#8212;';
		}

		$ghost_url = add_query_arg( 'erdo_token', $item->token_raw, get_permalink( $post ) );

		return sprintf(
			'<div class="erdo-draft-links-reveal" data-url="%1$s">' .
				'<button type="button" class="button button-small erdo-draft-links-reveal-toggle" aria-expanded="false">%2$s</button>' .
				'<span class="erdo-draft-links-reveal-content" hidden>' .
					'<input type="text" class="erdo-draft-links-reveal-input" value="%1$s" readonly onclick="this.select()" />' .
					'<button type="button" class="button button-small erdo-draft-links-copy" title="%3$s"><span class="dashicons dashicons-clipboard"></span></button>' .
				'</span>' .
			'</div>',
			esc_url( $ghost_url ),
			esc_html__( 'View Link', 'erdo-draft-links' ),
			esc_attr__( 'Copy', 'erdo-draft-links' )
		);
	}

	protected function column_status( $item ): string {
		$now = current_time( 'timestamp', true );

		if ( ! $item->is_active ) {
			return '<span class="erdo-draft-links-badge erdo-draft-links-badge--revoked">' . esc_html__( 'Revoked', 'erdo-draft-links' ) . '</span>';
		}
		if ( $item->expires_at && strtotime( $item->expires_at ) < $now ) {
			return '<span class="erdo-draft-links-badge erdo-draft-links-badge--expired">' . esc_html__( 'Expired', 'erdo-draft-links' ) . '</span>';
		}
		return '<span class="erdo-draft-links-badge erdo-draft-links-badge--active">' . esc_html__( 'Active', 'erdo-draft-links' ) . '</span>';
	}

	protected function column_view_count( $item ): string {
		return esc_html( number_format_i18n( (int) $item->view_count ) );
	}

	protected function column_expires_at( $item ): string {
		if ( ! $item->expires_at ) {
			return '<span class="erdo-draft-links-never">' . esc_html__( 'Never', 'erdo-draft-links' ) . '</span>';
		}
		$ts   = strtotime( $item->expires_at );
		$date = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
		$diff = human_time_diff( $ts, current_time( 'timestamp', true ) );
		$past = $ts < current_time( 'timestamp', true );

		return sprintf(
			'<abbr title="%s">%s</abbr>',
			esc_attr( $date ),
			$past
				/* translators: %s: human-readable time ago */
				? sprintf( esc_html__( '%s ago', 'erdo-draft-links' ), esc_html( $diff ) )
				/* translators: %s: human-readable time remaining */
				: sprintf( esc_html__( 'in %s', 'erdo-draft-links' ), esc_html( $diff ) )
		);
	}

	protected function column_created_at( $item ): string {
		$ts = strtotime( $item->created_at );
		return esc_html( wp_date( get_option( 'date_format' ), $ts ) );
	}

	protected function column_default( $item, $column_name ): string {
		return '';
	}

	public function prepare_items(): void {
		$per_page     = 20;
		$current_page = $this->get_pagenum();

		$this->set_pagination_args( array(
			'total_items' => $this->db->get_links_count( $this->current_filter ),
			'per_page'    => $per_page,
		) );

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		$this->items = $this->db->get_links_paginated( $current_page, $per_page, $this->current_filter );
	}
}
