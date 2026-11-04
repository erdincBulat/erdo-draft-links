<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Client feedback" dashboard widget: the newest open comments and change
 * requests on posts the user can edit.
 */
class Erdo_Draft_Links_Dashboard {

	const LIMIT = 5;

	private Erdo_Draft_Links_DB $db;

	public function __construct( Erdo_Draft_Links_DB $db ) {
		$this->db = $db;
	}

	public function register( Erdo_Draft_Links_Loader $loader ): void {
		$loader->add_action( 'wp_dashboard_setup', $this, 'add_widget' );
	}

	public function add_widget(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'erdo_draft_links_feedback', __( 'Client feedback', 'erdo-draft-links' ), array( $this, 'render' ) );
		add_action(
			'admin_enqueue_scripts',
			static function ( string $hook ): void {
				if ( 'index.php' === $hook ) {
					wp_enqueue_style( 'erdo-draft-links-admin', plugins_url( 'assets/css/admin.css', ERDO_DRAFT_LINKS_PLUGIN_FILE ), array(), ERDO_DRAFT_LINKS_VERSION );
				}
			}
		);
	}

	public function render(): void {
		$query = array(
			'status'    => 'open',
			'author_id' => current_user_can( 'edit_others_posts' ) ? 0 : get_current_user_id(),
		);
		$total = $this->db->get_feedback_count( $query );
		$items = $total ? $this->db->get_feedback_paginated( 1, self::LIMIT, $query ) : array();

		if ( ! $items ) {
			echo '<p class="erdo-dl-muted">' . esc_html__( 'No open feedback. When a client comments on a shared draft, it shows up here.', 'erdo-draft-links' ) . '</p>';
			return;
		}

		echo '<ul class="erdo-dl-dash">';
		foreach ( $items as $item ) {
			$edit = get_edit_post_link( (int) $item->post_id );
			printf(
				'<li><div class="erdo-dl-dash__meta"><strong>%1$s</strong> %2$s %3$s <span class="erdo-dl-muted">· %4$s</span></div><div class="erdo-dl-dash__msg">%5$s</div></li>',
				esc_html( $item->author_name ),
				esc_html_x( 'on', 'feedback on a post', 'erdo-draft-links' ),
				$edit ? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $edit ), esc_html( get_the_title( (int) $item->post_id ) ?: __( '(no title)', 'erdo-draft-links' ) ) ) : esc_html( get_the_title( (int) $item->post_id ) ),
				/* translators: %s: human-readable time, e.g. "2 hours" */
				esc_html( sprintf( __( '%s ago', 'erdo-draft-links' ), human_time_diff( strtotime( $item->created_at . ' UTC' ), time() ) ) ),
				esc_html( wp_trim_words( (string) $item->message, 24 ) )
			);
		}
		echo '</ul>';

		printf(
			'<p class="erdo-dl-dash__all"><a href="%1$s">%2$s</a></p>',
			esc_url( Erdo_Draft_Links_Admin_Pages::url( 'feedback' ) ),
			/* translators: %d: number of open feedback items */
			esc_html( sprintf( _n( 'View %d open item', 'View all %d open items', $total, 'erdo-draft-links' ), $total ) )
		);
	}
}
