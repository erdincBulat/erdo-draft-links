<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Draft Links → Links: every share link, newest first.
 */
class Erdo_Draft_Links_Admin_List extends WP_List_Table {

	private Erdo_Draft_Links_DB $db;
	private Erdo_Draft_Links_Token $token;
	private Erdo_Draft_Links_Review $review;
	private int $author_id;
	private string $current_filter;

	/** @var array<int, int> Open feedback per link id, for the current page. */
	private array $open_counts = array();

	/**
	 * @param int $author_id Only list links for this author's posts (0 = all).
	 */
	public function __construct( Erdo_Draft_Links_DB $db, Erdo_Draft_Links_Token $token, Erdo_Draft_Links_Review $review, int $author_id = 0 ) {
		parent::__construct(
			array(
				'singular' => 'erdo_draft_link',
				'plural'   => 'erdo_draft_links',
				'ajax'     => false,
			)
		);
		$this->db             = $db;
		$this->token          = $token;
		$this->review         = $review;
		$this->author_id      = $author_id;
		$this->current_filter = isset( $_GET['link_status'] ) ? sanitize_key( $_GET['link_status'] ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
	}

	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox" />',
			'post_title' => __( 'Post', 'erdo-draft-links' ),
			'label'      => __( 'Link', 'erdo-draft-links' ),
			'status'     => __( 'Status', 'erdo-draft-links' ),
			'view_count' => __( 'Opened', 'erdo-draft-links' ),
			'review'     => __( 'Review', 'erdo-draft-links' ),
			'feedback'   => __( 'Open comments', 'erdo-draft-links' ),
			'created_at' => __( 'Created', 'erdo-draft-links' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'status'     => array( 'expires_at', false ),
			'view_count' => array( 'view_count', true ),
			'created_at' => array( 'created_at', true ),
		);
	}

	protected function get_primary_column_name(): string {
		return 'post_title';
	}

	protected function get_bulk_actions(): array {
		return array(
			'revoke' => __( 'Turn off', 'erdo-draft-links' ),
			'delete' => __( 'Delete', 'erdo-draft-links' ),
		);
	}

	public function get_views(): array {
		$labels = array(
			'all'     => __( 'All', 'erdo-draft-links' ),
			'active'  => __( 'Active', 'erdo-draft-links' ),
			'expired' => __( 'Expired', 'erdo-draft-links' ),
			'revoked' => __( 'Turned off', 'erdo-draft-links' ),
		);

		$views = array();
		foreach ( $labels as $key => $label ) {
			$count = $this->db->get_links_count(
				array(
					'status'    => $key,
					'author_id' => $this->author_id,
				)
			);
			if ( 'all' !== $key && 0 === $count ) {
				continue;
			}
			$views[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( Erdo_Draft_Links_Admin_Pages::url( 'links', 'all' === $key ? array() : array( 'link_status' => $key ) ) ),
				$this->current_filter === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}
		return $views;
	}

	public function no_items(): void {
		esc_html_e( 'No share links yet. Open a draft and use “Create share link” in the Share for review panel.', 'erdo-draft-links' );
	}

	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="erdo-dl-link-%1$d">%2$s</label><input type="checkbox" id="erdo-dl-link-%1$d" name="link_ids[]" value="%1$d" />',
			(int) $item->id,
			esc_html__( 'Select link', 'erdo-draft-links' )
		);
	}

	private function action_url( string $action, object $item ): string {
		return wp_nonce_url(
			Erdo_Draft_Links_Admin_Pages::url(
				'links',
				array(
					'action'  => $action,
					'link_id' => (int) $item->id,
				)
			),
			'erdo_draft_link_action_' . $item->id
		);
	}

	protected function column_post_title( $item ): string {
		$post = get_post( (int) $item->post_id );

		if ( ! $post ) {
			/* translators: %d: post ID number */
			$title = '<strong>' . esc_html( sprintf( __( '(Post #%d — deleted)', 'erdo-draft-links' ), (int) $item->post_id ) ) . '</strong>';
		} else {
			$edit_link = get_edit_post_link( $post->ID );
			$title     = $edit_link
				? sprintf( '<strong><a class="row-title" href="%1$s">%2$s</a></strong>', esc_url( $edit_link ), esc_html( get_the_title( $post ) ?: __( '(no title)', 'erdo-draft-links' ) ) )
				: '<strong>' . esc_html( get_the_title( $post ) ) . '</strong>';

			$status_object = get_post_status_object( $post->post_status );
			if ( $status_object && 'publish' !== $post->post_status ) {
				$title .= ' <span class="post-state">— ' . esc_html( $status_object->label ) . '</span>';
			}
		}

		$url     = $post && $this->is_live( $item ) ? $this->token->link_url( $item ) : '';
		$actions = array();

		if ( $url ) {
			$actions['copy'] = sprintf(
				'<button type="button" class="button-link erdo-dl-copy" data-url="%1$s" data-copied="%2$s">%3$s</button>',
				esc_url( $url ),
				esc_attr__( 'Copied!', 'erdo-draft-links' ),
				esc_html__( 'Copy link', 'erdo-draft-links' )
			);
			$actions['view'] = sprintf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>', esc_url( $url ), esc_html__( 'Open', 'erdo-draft-links' ), esc_html__( '(opens in a new tab)', 'erdo-draft-links' ) );
		}
		if ( $item->is_active ) {
			$actions['extend'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $this->action_url( 'erdo_draft_link_extend', $item ) ), esc_html__( 'Expire in 7 days', 'erdo-draft-links' ) );
			$actions['revoke'] = sprintf(
				'<a href="%1$s" onclick="return confirm(\'%2$s\')">%3$s</a>',
				esc_url( $this->action_url( 'erdo_draft_link_revoke', $item ) ),
				esc_js( __( 'Turn off this link? Anyone who has it will no longer be able to open the draft.', 'erdo-draft-links' ) ),
				esc_html__( 'Turn off', 'erdo-draft-links' )
			);
		}
		$actions['delete'] = sprintf(
			'<a href="%1$s" class="submitdelete" onclick="return confirm(\'%2$s\')">%3$s</a>',
			esc_url( $this->action_url( 'erdo_draft_link_delete', $item ) ),
			esc_js( __( 'Delete this link? Its feedback stays on the post.', 'erdo-draft-links' ) ),
			esc_html__( 'Delete', 'erdo-draft-links' )
		);

		return $title . $this->row_actions( $actions );
	}

	protected function column_label( $item ): string {
		$out = '' !== (string) $item->label
			? '<span class="erdo-dl-cell-label">' . esc_html( $item->label ) . '</span>'
			: '<span class="erdo-dl-muted">' . esc_html__( 'Untitled link', 'erdo-draft-links' ) . '</span>';

		$notes = array();
		if ( '' !== (string) $item->password_hash ) {
			$notes[] = '<span class="dashicons dashicons-lock" aria-hidden="true"></span>' . esc_html__( 'Password', 'erdo-draft-links' );
		}
		if ( null !== $item->max_views ) {
			/* translators: %d: maximum number of opens */
			$notes[] = esc_html( sprintf( _n( 'Opens %d time', 'Opens %d times', (int) $item->max_views, 'erdo-draft-links' ), (int) $item->max_views ) );
		}
		if ( $notes ) {
			$out .= '<span class="erdo-dl-cell-sub">' . implode( ' · ', $notes ) . '</span>';
		}
		return $out;
	}

	private function is_live( object $item ): bool {
		return $item->is_active
			&& ! $this->token->is_expired( $item->expires_at )
			&& ( null === $item->max_views || (int) $item->view_count < (int) $item->max_views );
	}

	protected function column_status( $item ): string {
		if ( ! $item->is_active ) {
			return $this->badge( 'off', __( 'Turned off', 'erdo-draft-links' ) );
		}
		if ( $this->token->is_expired( $item->expires_at ) ) {
			return $this->badge( 'off', __( 'Expired', 'erdo-draft-links' ) ) . $this->when( $item->expires_at );
		}
		if ( null !== $item->max_views && (int) $item->view_count >= (int) $item->max_views ) {
			return $this->badge( 'warning', __( 'Open limit reached', 'erdo-draft-links' ) );
		}
		if ( ! $item->expires_at ) {
			return $this->badge( 'active', __( 'Active', 'erdo-draft-links' ) ) . '<span class="erdo-dl-cell-sub">' . esc_html__( 'No expiry', 'erdo-draft-links' ) . '</span>';
		}
		return $this->badge( 'active', __( 'Active', 'erdo-draft-links' ) ) . $this->when( $item->expires_at );
	}

	/**
	 * "Expires in 3 days" / "Expired 2 days ago", with the exact date on hover.
	 */
	private function when( string $gmt ): string {
		$ts   = strtotime( $gmt . ' UTC' );
		$diff = human_time_diff( $ts, time() );
		return sprintf(
			'<span class="erdo-dl-cell-sub" title="%1$s">%2$s</span>',
			esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) ),
			$ts < time()
				/* translators: %s: human-readable time, e.g. "2 days" */
				? esc_html( sprintf( __( 'Expired %s ago', 'erdo-draft-links' ), $diff ) )
				/* translators: %s: human-readable time, e.g. "3 days" */
				: esc_html( sprintf( __( 'Expires in %s', 'erdo-draft-links' ), $diff ) )
		);
	}

	private function badge( string $type, string $label ): string {
		return sprintf( '<span class="erdo-dl-badge erdo-dl-badge--%1$s">%2$s</span>', esc_attr( $type ), esc_html( $label ) );
	}

	protected function column_view_count( $item ): string {
		$views = (int) $item->view_count;
		if ( 0 === $views ) {
			return '<span class="erdo-dl-muted">' . esc_html__( 'Not yet', 'erdo-draft-links' ) . '</span>';
		}
		/* translators: %s: number of times opened */
		$out = esc_html( sprintf( _n( '%s time', '%s times', $views, 'erdo-draft-links' ), number_format_i18n( $views ) ) );
		if ( $item->last_viewed_at ) {
			/* translators: %s: human-readable time, e.g. "2 hours" */
			$out .= '<span class="erdo-dl-cell-sub">' . esc_html( sprintf( __( 'Last %s ago', 'erdo-draft-links' ), human_time_diff( strtotime( $item->last_viewed_at . ' UTC' ), time() ) ) ) . '</span>';
		}
		return $out;
	}

	protected function column_review( $item ): string {
		$review = $this->review->get_link_review( $item );

		switch ( $review['review_status'] ) {
			case Erdo_Draft_Links_DB::REVIEW_APPROVED:
				$out = $review['is_stale']
					? $this->badge( 'warning', __( 'Approved, then edited', 'erdo-draft-links' ) )
					: $this->badge( 'approved', __( 'Approved', 'erdo-draft-links' ) );
				break;
			case Erdo_Draft_Links_DB::REVIEW_CHANGES:
				$out = $this->badge( 'changes', __( 'Changes requested', 'erdo-draft-links' ) );
				break;
			default:
				return '<span class="erdo-dl-muted">' . esc_html__( 'Waiting', 'erdo-draft-links' ) . '</span>';
		}

		if ( '' !== $review['reviewer_name'] ) {
			/* translators: %s: reviewer name */
			$out .= '<span class="erdo-dl-cell-sub">' . esc_html( sprintf( __( 'by %s', 'erdo-draft-links' ), $review['reviewer_name'] ) ) . '</span>';
		}
		return $out;
	}

	protected function column_feedback( $item ): string {
		$open = $this->open_counts[ (int) $item->id ] ?? 0;
		$url  = Erdo_Draft_Links_Admin_Pages::url( 'feedback', array( 'link_id' => (int) $item->id ) );

		if ( 0 === $open ) {
			return sprintf( '<a class="erdo-dl-muted" href="%1$s">%2$s</a>', esc_url( $url ), esc_html__( 'None', 'erdo-draft-links' ) );
		}
		return sprintf(
			'<a class="erdo-dl-count" href="%1$s" aria-label="%3$s">%2$s</a>',
			esc_url( $url ),
			esc_html( number_format_i18n( $open ) ),
			/* translators: %d: number of open comments */
			esc_attr( sprintf( _n( '%d open comment', '%d open comments', $open, 'erdo-draft-links' ), $open ) )
		);
	}

	protected function column_created_at( $item ): string {
		$out  = esc_html( wp_date( get_option( 'date_format' ), strtotime( $item->created_at . ' UTC' ) ) );
		$user = (int) $item->created_by ? get_userdata( (int) $item->created_by ) : false;
		if ( $user ) {
			/* translators: %s: user display name */
			$out .= '<span class="erdo-dl-cell-sub">' . esc_html( sprintf( __( 'by %s', 'erdo-draft-links' ), $user->display_name ) ) . '</span>';
		}
		return $out;
	}

	protected function column_default( $item, $column_name ): string {
		return '';
	}

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'erdo_draft_links_per_page', 20 );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$query = array(
			'status'    => $this->current_filter,
			'author_id' => $this->author_id,
			'search'    => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'orderby'   => isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'id',
			'order'     => isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : 'desc',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->set_pagination_args(
			array(
				'total_items' => $this->db->get_links_count( $query ),
				'per_page'    => $per_page,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), $this->get_primary_column_name() );

		$this->items       = $this->db->get_links_paginated( $this->get_pagenum(), $per_page, $query );
		$this->open_counts = $this->db->count_open_feedback_by_link( wp_list_pluck( $this->items, 'id' ) );
	}
}
