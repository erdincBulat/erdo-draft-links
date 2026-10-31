<?php
defined( 'ABSPATH' ) || exit;

/**
 * The "Draft Links" admin menu: Links, Feedback and Settings pages, their
 * row/bulk actions, and the redirect from the pre-2.0 Tools page.
 */
class Erdo_Draft_Links_Admin_Pages {

	const SLUG          = 'erdo-draft-links';
	const FEEDBACK_SLUG = 'erdo-draft-links-feedback';
	const LEGACY_SLUG   = 'erdo-draft-links-manager';

	private Erdo_Draft_Links_DB $db;
	private Erdo_Draft_Links_Token $token;
	private Erdo_Draft_Links_Review $review;
	private Erdo_Draft_Links_Settings $settings;

	/** @var string[] Hook suffixes of our pages, for asset loading. */
	private array $hooks = array();

	public function __construct( Erdo_Draft_Links_DB $db, Erdo_Draft_Links_Token $token, Erdo_Draft_Links_Review $review, Erdo_Draft_Links_Settings $settings ) {
		$this->db       = $db;
		$this->token    = $token;
		$this->review   = $review;
		$this->settings = $settings;
	}

	public function register( Erdo_Draft_Links_Loader $loader ): void {
		$loader->add_action( 'admin_menu', $this, 'register_menu' );
		$loader->add_action( 'admin_page_access_denied', $this, 'redirect_legacy_page' );
		$loader->add_action( 'admin_init', $this, 'handle_actions' );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets' );
		$loader->add_filter( 'set-screen-option', $this, 'save_screen_option', 10, 3 );
		$loader->add_filter( 'plugin_action_links_' . plugin_basename( ERDO_DRAFT_LINKS_PLUGIN_FILE ), $this, 'plugin_action_links' );
	}

	/**
	 * Admin URL of one of our pages.
	 *
	 * @param string $page 'links' | 'feedback' | 'settings'.
	 */
	public static function url( string $page = 'links', array $args = array() ): string {
		$slugs = array(
			'links'    => self::SLUG,
			'feedback' => self::FEEDBACK_SLUG,
			'settings' => Erdo_Draft_Links_Settings::PAGE,
		);
		return add_query_arg( $args, admin_url( 'admin.php?page=' . ( $slugs[ $page ] ?? self::SLUG ) ) );
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	public function register_menu(): void {
		$open   = current_user_can( 'edit_posts' ) ? $this->db->get_feedback_count(
			array(
				'status'    => 'open',
				'author_id' => $this->scope_author_id(),
			)
		) : 0;
		$bubble = $open ? sprintf(
			' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
			$open,
			esc_html( number_format_i18n( $open ) ),
			/* translators: %d: number of open feedback items */
			esc_html( sprintf( _n( '%d open comment', '%d open comments', $open, 'erdo-draft-links' ), $open ) )
		) : '';

		add_menu_page(
			__( 'Draft Links', 'erdo-draft-links' ),
			__( 'Draft Links', 'erdo-draft-links' ) . $bubble,
			'edit_posts',
			self::SLUG,
			array( $this, 'render_links_page' ),
			'dashicons-share-alt2',
			26
		);

		$this->hooks[] = (string) add_submenu_page( self::SLUG, __( 'Share links', 'erdo-draft-links' ), __( 'Links', 'erdo-draft-links' ), 'edit_posts', self::SLUG, array( $this, 'render_links_page' ) );
		$this->hooks[] = (string) add_submenu_page( self::SLUG, __( 'Feedback', 'erdo-draft-links' ), __( 'Feedback', 'erdo-draft-links' ) . $bubble, 'edit_posts', self::FEEDBACK_SLUG, array( $this, 'render_feedback_page' ) );
		$this->hooks[] = (string) add_submenu_page( self::SLUG, __( 'Draft Links settings', 'erdo-draft-links' ), __( 'Settings', 'erdo-draft-links' ), 'manage_options', Erdo_Draft_Links_Settings::PAGE, array( $this->settings, 'render_page' ) );

		foreach ( array_slice( $this->hooks, 0, 2 ) as $i => $hook ) {
			add_action( 'load-' . $hook, array( $this, 0 === $i ? 'add_links_screen_option' : 'add_feedback_screen_option' ) );
		}
	}

	public function add_links_screen_option(): void {
		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => 'erdo_draft_links_per_page',
			)
		);
	}

	public function add_feedback_screen_option(): void {
		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => 'erdo_draft_feedback_per_page',
			)
		);
	}

	/**
	 * @param mixed $keep
	 * @return mixed
	 */
	public function save_screen_option( $keep, string $option, $value ) {
		return in_array( $option, array( 'erdo_draft_links_per_page', 'erdo_draft_feedback_per_page' ), true ) ? max( 1, min( 200, (int) $value ) ) : $keep;
	}

	public function plugin_action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::url( 'settings' ) ), esc_html__( 'Settings', 'erdo-draft-links' ) )
		);
		return $links;
	}

	/**
	 * Tools → Erdo Draft Links (before 2.0) no longer exists. Bookmarks and
	 * old notification emails point there, so send them to the new page.
	 * Fires just before WordPress would show "not allowed" for the old slug.
	 */
	public function redirect_legacy_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::LEGACY_SLUG !== $_GET['page'] ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'links';
		$args = array();
		foreach ( array( 'link_status', 'paged', 's', 'orderby', 'order' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		wp_safe_redirect( self::url( in_array( $tab, array( 'feedback', 'settings' ), true ) ? $tab : 'links', $args ) );
		exit;
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'erdo-draft-links-admin', plugins_url( 'assets/css/admin.css', ERDO_DRAFT_LINKS_PLUGIN_FILE ), array(), ERDO_DRAFT_LINKS_VERSION );
		wp_enqueue_script( 'erdo-draft-links-admin-list', plugins_url( 'assets/js/admin-list.js', ERDO_DRAFT_LINKS_PLUGIN_FILE ), array( 'wp-a11y' ), ERDO_DRAFT_LINKS_VERSION, true );
	}

	// -------------------------------------------------------------------------
	// Actions
	// -------------------------------------------------------------------------

	public function handle_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- each branch verifies its own nonce.
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( ! in_array( $page, array( self::SLUG, self::FEEDBACK_SLUG ), true ) ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( '' === $action || '-1' === $action ) {
			$action = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		}
		// phpcs:enable WordPress.Security.NonceVerification

		if ( self::FEEDBACK_SLUG === $page ) {
			$this->handle_feedback_action( $action );
		} else {
			$this->handle_link_action( $action );
		}
	}

	private function handle_link_action( string $action ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bulk nonce checked below.
		if ( in_array( $action, array( 'revoke', 'delete' ), true ) && isset( $_GET['link_ids'] ) ) {
			check_admin_referer( 'bulk-erdo_draft_links' );
			$done = 0;
			foreach ( array_map( 'absint', (array) wp_unslash( $_GET['link_ids'] ) ) as $id ) {
				$link = $id ? $this->db->get_link_by_id( $id ) : null;
				// Silently skip rows this user may not manage (e.g. a tampered request).
				if ( ! $link || ! $this->can_manage_post_row( (int) $link->post_id ) ) {
					continue;
				}
				'delete' === $action ? $this->db->delete_link_by_id( $id ) : $this->db->revoke_link_by_id( $id );
				++$done;
			}
			$this->redirect( 'links', 'delete' === $action ? 'bulk_deleted' : 'bulk_revoked', $done );
		}

		if ( ! in_array( $action, array( 'erdo_draft_link_revoke', 'erdo_draft_link_delete', 'erdo_draft_link_extend' ), true ) ) {
			return;
		}

		$link_id = isset( $_GET['link_id'] ) ? absint( $_GET['link_id'] ) : 0;
		check_admin_referer( 'erdo_draft_link_action_' . $link_id );
		$link = $link_id ? $this->db->get_link_by_id( $link_id ) : null;
		if ( ! $link ) {
			$this->redirect( 'links' );
		}
		$this->deny_unless_can_manage_post_row( (int) $link->post_id );

		switch ( $action ) {
			case 'erdo_draft_link_revoke':
				$this->db->revoke_link_by_id( $link_id );
				$this->redirect( 'links', 'revoked' );
				break;
			case 'erdo_draft_link_delete':
				$this->db->delete_link_by_id( $link_id );
				$this->redirect( 'links', 'deleted' );
				break;
			case 'erdo_draft_link_extend':
				$this->db->update_link( $link_id, array( 'expires_at' => $this->token->compute_expires_at( '7d' ) ) );
				$this->redirect( 'links', 'extended' );
				break;
		}
	}

	private function handle_feedback_action( string $action ): void {
		// Reply (POST from the row's own form).
		if ( 'erdo_draft_feedback_reply' === $action && isset( $_POST['feedback_id'] ) ) {
			$id = absint( $_POST['feedback_id'] );
			check_admin_referer( 'erdo_draft_feedback_reply_' . $id );
			$item = $this->find_feedback( $id );

			$reply = isset( $_POST['reply'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['reply'] ) ) ) : '';
			if ( '' !== $reply ) {
				$user     = wp_get_current_user();
				$reply_id = $this->db->add_reply( $item, (int) $user->ID, $user->display_name, mb_substr( $reply, 0, 5000 ) );
				if ( $reply_id ) {
					/** This action is documented in includes/class-erdo-draft-links-feedback-api.php */
					do_action( 'erdo_draft_links_reply_added', $item, $reply_id );
				}
			}
			$this->redirect( 'feedback', 'feedback_replied', 0, true );
		}

		// Bulk.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bulk nonce checked below.
		if ( in_array( $action, array( 'resolve', 'reopen', 'delete' ), true ) && isset( $_GET['feedback_ids'] ) ) {
			check_admin_referer( 'bulk-erdo_draft_feedbacks' );
			$done = 0;
			foreach ( array_map( 'absint', (array) wp_unslash( $_GET['feedback_ids'] ) ) as $id ) {
				$item = $id ? $this->db->get_feedback_by_id( $id ) : null;
				if ( ! $item || 0 !== (int) $item->parent_id || ! $this->can_manage_post_row( (int) $item->post_id ) ) {
					continue;
				}
				if ( 'delete' === $action ) {
					$this->db->delete_feedback( $id );
				} elseif ( Erdo_Draft_Links_DB::FEEDBACK_TYPE_APPROVAL !== $item->type ) {
					$this->db->update_feedback_status( $id, 'resolve' === $action ? Erdo_Draft_Links_DB::FEEDBACK_STATUS_DONE : Erdo_Draft_Links_DB::FEEDBACK_STATUS_OPEN );
				}
				++$done;
			}
			$this->redirect( 'feedback', 'delete' === $action ? 'feedback_bulk_deleted' : 'feedback_bulk_updated', $done, true );
		}

		if ( ! in_array( $action, array( 'erdo_draft_feedback_status', 'erdo_draft_feedback_delete' ), true ) ) {
			return;
		}

		$id = isset( $_GET['feedback_id'] ) ? absint( $_GET['feedback_id'] ) : 0;
		check_admin_referer( $action . '_' . $id );
		$item = $this->find_feedback( $id );

		if ( 'erdo_draft_feedback_delete' === $action ) {
			$this->db->delete_feedback( $id );
			$this->redirect( 'feedback', 'feedback_deleted', 0, true );
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( in_array( $status, array( Erdo_Draft_Links_DB::FEEDBACK_STATUS_OPEN, Erdo_Draft_Links_DB::FEEDBACK_STATUS_DONE ), true ) ) {
			$this->db->update_feedback_status( (int) $item->id, $status );
		}
		$this->redirect( 'feedback', Erdo_Draft_Links_DB::FEEDBACK_STATUS_DONE === $status ? 'feedback_resolved' : 'feedback_reopened', 0, true );
	}

	/**
	 * A top-level feedback item the current user may manage, or wp_die().
	 */
	private function find_feedback( int $id ): object {
		$item = $id ? $this->db->get_feedback_by_id( $id ) : null;
		if ( ! $item || 0 !== (int) $item->parent_id ) {
			$this->redirect( 'feedback' );
		}
		$this->deny_unless_can_manage_post_row( (int) $item->post_id );
		return $item;
	}

	/**
	 * Back to the list with a notice, keeping its filters/view/page.
	 *
	 * @return never
	 */
	private function redirect( string $page, string $message = '', int $count = 0, bool $keep_view = false ): void {
		$args = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		foreach ( array( 'link_status', 'feedback_status', 'post_id', 'link_id', 'paged', 's', 'orderby', 'order' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && ( $keep_view || 'link_id' !== $key ) ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $message ) {
			$args['edl_message'] = $message;
		}
		if ( $count ) {
			$args['edl_count'] = $count;
		}
		wp_safe_redirect( self::url( $page, $args ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Pages
	// -------------------------------------------------------------------------

	public function render_links_page(): void {
		$table = new Erdo_Draft_Links_Admin_List( $this->db, $this->token, $this->review, $this->scope_author_id() );
		$table->prepare_items();
		?>
		<div class="wrap erdo-dl-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Share links', 'erdo-draft-links' ); ?></h1>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
			if ( '' !== $search ) {
				/* translators: %s: search terms */
				printf( '<span class="subtitle">' . esc_html__( 'Search results for: %s', 'erdo-draft-links' ) . '</span>', '<strong>' . esc_html( $search ) . '</strong>' );
			}
			?>
			<hr class="wp-header-end">
			<?php $this->render_notice(); ?>
			<p class="erdo-dl-intro"><?php esc_html_e( 'Links are created from the post editor’s “Share for review” panel. Anyone with an active link can open the draft without logging in.', 'erdo-draft-links' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( isset( $_GET['link_status'] ) ) {
					printf( '<input type="hidden" name="link_status" value="%s" />', esc_attr( sanitize_key( $_GET['link_status'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}
				$table->views();
				$table->search_box( __( 'Search links', 'erdo-draft-links' ), 'erdo-dl-links' );
				$table->display();
				?>
			</form>
			<?php $this->render_cleanup_note(); ?>
		</div>
		<?php
	}

	public function render_feedback_page(): void {
		$table = new Erdo_Draft_Links_Feedback_List( $this->db, $this->scope_author_id() );
		$table->prepare_items();
		?>
		<div class="wrap erdo-dl-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Feedback', 'erdo-draft-links' ); ?></h1>
			<hr class="wp-header-end">
			<?php
			$this->render_notice();
			$table->render_filter_notice();
			?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::FEEDBACK_SLUG ); ?>" />
				<?php
				// phpcs:disable WordPress.Security.NonceVerification.Recommended
				foreach ( array( 'feedback_status', 'post_id', 'link_id' ) as $key ) {
					if ( isset( $_GET[ $key ] ) ) {
						printf( '<input type="hidden" name="%1$s" value="%2$s" />', esc_attr( $key ), esc_attr( sanitize_key( $_GET[ $key ] ) ) );
					}
				}
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
				$table->views();
				$table->display();
				?>
			</form>
			<?php $table->render_reply_forms(); ?>
		</div>
		<?php
	}

	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['edl_message'] ) ? sanitize_key( $_GET['edl_message'] ) : '';
		$count   = isset( $_GET['edl_count'] ) ? absint( $_GET['edl_count'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'revoked'               => __( 'Link turned off.', 'erdo-draft-links' ),
			'deleted'               => __( 'Link deleted.', 'erdo-draft-links' ),
			'extended'              => __( 'Link now expires in 7 days.', 'erdo-draft-links' ),
			/* translators: %d: number of links */
			'bulk_deleted'          => sprintf( _n( '%d link deleted.', '%d links deleted.', $count, 'erdo-draft-links' ), $count ),
			/* translators: %d: number of links */
			'bulk_revoked'          => sprintf( _n( '%d link turned off.', '%d links turned off.', $count, 'erdo-draft-links' ), $count ),
			'feedback_replied'      => __( 'Reply sent.', 'erdo-draft-links' ),
			'feedback_resolved'     => __( 'Marked as resolved.', 'erdo-draft-links' ),
			'feedback_reopened'     => __( 'Reopened.', 'erdo-draft-links' ),
			'feedback_deleted'      => __( 'Feedback deleted.', 'erdo-draft-links' ),
			/* translators: %d: number of feedback items */
			'feedback_bulk_updated' => sprintf( _n( '%d item updated.', '%d items updated.', $count, 'erdo-draft-links' ), $count ),
			/* translators: %d: number of feedback items */
			'feedback_bulk_deleted' => sprintf( _n( '%d item deleted.', '%d items deleted.', $count, 'erdo-draft-links' ), $count ),
		);
		if ( isset( $messages[ $message ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $message ] ) );
		}
	}

	private function render_cleanup_note(): void {
		$last = get_option( 'erdo_draft_link_last_cleanup' );
		$days = (int) Erdo_Draft_Links_Settings::get( 'cleanup_days' );
		echo '<p class="description erdo-dl-cleanup-note">';
		/* translators: %d: number of days */
		echo esc_html( sprintf( _n( 'Expired links are deleted automatically %d day after they expire.', 'Expired links are deleted automatically %d days after they expire.', $days, 'erdo-draft-links' ), $days ) );
		if ( is_array( $last ) && ! empty( $last['time'] ) ) {
			echo ' ';
			/* translators: 1: date of the last cleanup, 2: number of links removed */
			echo esc_html( sprintf( __( 'Last run: %1$s (%2$d removed).', 'erdo-draft-links' ), $last['time'], (int) $last['deleted'] ) );
		}
		if ( current_user_can( 'manage_options' ) ) {
			printf( ' <a href="%1$s">%2$s</a>', esc_url( self::url( 'settings' ) ), esc_html__( 'Change', 'erdo-draft-links' ) );
		}
		echo '</p>';
	}

	// -------------------------------------------------------------------------
	// Permissions
	// -------------------------------------------------------------------------

	/**
	 * Users who can't edit others' posts only see links and feedback for their
	 * own posts. Returns the author id to filter by, or 0 for no filter.
	 */
	public function scope_author_id(): int {
		return current_user_can( 'edit_others_posts' ) ? 0 : get_current_user_id();
	}

	/**
	 * Whether the current user may act on a link/feedback row of this post.
	 * Rows whose post was deleted fall back to edit_others_posts.
	 */
	private function can_manage_post_row( int $post_id ): bool {
		return get_post( $post_id )
			? current_user_can( 'edit_post', $post_id )
			: current_user_can( 'edit_others_posts' );
	}

	private function deny_unless_can_manage_post_row( int $post_id ): void {
		if ( ! $this->can_manage_post_row( $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'erdo-draft-links' ), '', array( 'response' => 403 ) );
		}
	}
}
