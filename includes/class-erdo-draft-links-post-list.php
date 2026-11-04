<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Review" column to the Posts/Pages lists of supported post types
 * (latest decision + open comments) and a "Copy share link" row action.
 */
class Erdo_Draft_Links_Post_List {

	private Erdo_Draft_Links_DB $db;
	private Erdo_Draft_Links_Token $token;
	private Erdo_Draft_Links_Review $review;

	public function __construct( Erdo_Draft_Links_DB $db, Erdo_Draft_Links_Token $token, Erdo_Draft_Links_Review $review ) {
		$this->db     = $db;
		$this->token  = $token;
		$this->review = $review;
	}

	public function register( Erdo_Draft_Links_Loader $loader ): void {
		$loader->add_action( 'current_screen', $this, 'maybe_hook_list' );
	}

	public function maybe_hook_list( WP_Screen $screen ): void {
		if ( 'edit' !== $screen->base || ! in_array( $screen->post_type, Erdo_Draft_Links_Post_Types::get_supported(), true ) ) {
			return;
		}

		add_filter( "manage_{$screen->post_type}_posts_columns", array( $this, 'add_column' ) );
		add_action( "manage_{$screen->post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_filter( is_post_type_hierarchical( $screen->post_type ) ? 'page_row_actions' : 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		wp_enqueue_style( 'erdo-draft-links-admin', plugins_url( 'assets/css/admin.css', ERDO_DRAFT_LINKS_PLUGIN_FILE ), array(), ERDO_DRAFT_LINKS_VERSION );
		wp_enqueue_script( 'erdo-draft-links-admin-list', plugins_url( 'assets/js/admin-list.js', ERDO_DRAFT_LINKS_PLUGIN_FILE ), array( 'wp-a11y' ), ERDO_DRAFT_LINKS_VERSION, true );
	}

	public function add_column( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			// Right before the date column, or at the end if there is none.
			if ( 'date' === $key ) {
				$new['erdo_review'] = __( 'Review', 'erdo-draft-links' );
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new['erdo_review'] ) ) {
			$new['erdo_review'] = __( 'Review', 'erdo-draft-links' );
		}
		return $new;
	}

	public function render_column( string $column, int $post_id ): void {
		if ( 'erdo_review' !== $column ) {
			return;
		}

		$review = $this->review->get_post_review( $post_id );
		$open   = $this->db->count_open_feedback( $post_id );
		$parts  = array();

		if ( $review ) {
			if ( Erdo_Draft_Links_DB::REVIEW_APPROVED === $review['status'] ) {
				$parts[] = $review['is_stale']
					? '<span class="erdo-dl-badge erdo-dl-badge--warning">' . esc_html__( 'Approved, then edited', 'erdo-draft-links' ) . '</span>'
					: '<span class="erdo-dl-badge erdo-dl-badge--approved">' . esc_html__( 'Approved', 'erdo-draft-links' ) . '</span>';
			} else {
				$parts[] = '<span class="erdo-dl-badge erdo-dl-badge--changes">' . esc_html__( 'Changes requested', 'erdo-draft-links' ) . '</span>';
			}
		}

		if ( $open ) {
			$parts[] = sprintf(
				'<a class="erdo-dl-cell-sub" href="%1$s">%2$s</a>',
				esc_url( Erdo_Draft_Links_Admin_Pages::url( 'feedback', array( 'post_id' => $post_id ) ) ),
				/* translators: %d: number of open comments */
				esc_html( sprintf( _n( '%d open comment', '%d open comments', $open, 'erdo-draft-links' ), $open ) )
			);
		}

		if ( ! $parts ) {
			$parts[] = $this->db->get_active_links_by_post( $post_id )
				? '<span class="erdo-dl-muted">' . esc_html__( 'Shared, waiting', 'erdo-draft-links' ) . '</span>'
				: '<span class="erdo-dl-muted" aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Not shared', 'erdo-draft-links' ) . '</span>';
		}

		echo implode( '', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
	}

	/**
	 * "Copy share link" for the newest link that still opens.
	 */
	public function row_actions( array $actions, WP_Post $post ): array {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		foreach ( $this->db->get_active_links_by_post( $post->ID ) as $link ) {
			if ( null !== $link->max_views && (int) $link->view_count >= (int) $link->max_views ) {
				continue;
			}
			$url = $this->token->link_url( $link );
			if ( '' === $url ) {
				continue;
			}
			$actions['erdo_copy_link'] = sprintf(
				'<button type="button" class="button-link erdo-dl-copy" data-url="%1$s" data-copied="%2$s">%3$s</button>',
				esc_url( $url ),
				esc_attr__( 'Copied!', 'erdo-draft-links' ),
				esc_html__( 'Copy share link', 'erdo-draft-links' )
			);
			break;
		}
		return $actions;
	}
}
