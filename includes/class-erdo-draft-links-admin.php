<?php
defined( 'ABSPATH' ) || exit;

class Erdo_Draft_Links_Admin {

	private Erdo_Draft_Links_DB $db;
	private Erdo_Draft_Links_Token $token;

	public function __construct( Erdo_Draft_Links_DB $db, Erdo_Draft_Links_Token $token ) {
		$this->db    = $db;
		$this->token = $token;
	}

	public function register( Erdo_Draft_Links_Loader $loader ): void {
		$loader->add_action( 'rest_api_init',               $this, 'register_rest_routes' );
		$loader->add_action( 'add_meta_boxes',              $this, 'register_meta_box' );
		$loader->add_action( 'enqueue_block_editor_assets', $this, 'enqueue_block_editor_assets' );
		$loader->add_action( 'admin_enqueue_scripts',       $this, 'enqueue_classic_editor_assets' );
		$loader->add_action( 'before_delete_post',          $this, 'on_delete_post', 10, 1 );
		$loader->add_action( 'admin_menu',                  $this, 'register_admin_menu' );
		$loader->add_action( 'admin_init',                  $this, 'handle_admin_actions' );
		$loader->add_action( 'admin_enqueue_scripts',       $this, 'enqueue_manager_assets' );
	}

	// -------------------------------------------------------------------------
	// REST API
	// -------------------------------------------------------------------------

	public function register_rest_routes(): void {
		$namespace = 'erdo-draft-links/v1';

		register_rest_route(
			$namespace,
			'/link/(?P<post_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_get_link' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => $this->post_id_arg(),
			)
		);

		register_rest_route(
			$namespace,
			'/link/(?P<post_id>\d+)/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_generate_link' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array_merge(
					$this->post_id_arg(),
					array(
						'expiry' => array(
							'type'              => 'string',
							'default'           => '24h',
							'enum'              => array( '24h', '48h', '7d', 'never' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
					)
				),
			)
		);

		register_rest_route(
			$namespace,
			'/link/(?P<post_id>\d+)/revoke',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_revoke_link' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => $this->post_id_arg(),
			)
		);
	}

	public function rest_permission( WP_REST_Request $request ): bool|WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to manage draft links for this post.', 'erdo-draft-links' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function rest_get_link( WP_REST_Request $request ): WP_REST_Response {
		// This status can change at any time (link generated/revoked elsewhere),
		// so make sure caching plugins/proxies (e.g. LiteSpeed Cache) never
		// serve a stale "exists: false" response to the editor sidebar.
		nocache_headers();

		$post_id = (int) $request->get_param( 'post_id' );
		$link    = $this->db->get_link_by_post( $post_id );

		if ( ! $link || ! $link->is_active ) {
			return new WP_REST_Response( array( 'exists' => false ), 200 );
		}

		return new WP_REST_Response( $this->link_to_response( $link, $post_id ), 200 );
	}

	public function rest_generate_link( WP_REST_Request $request ): WP_REST_Response {
		$post_id    = (int) $request->get_param( 'post_id' );
		$expiry     = $request->get_param( 'expiry' );
		$expires_at = $this->compute_expires_at( $expiry );

		$token_data = $this->token->generate();
		$saved      = $this->db->upsert_link( $post_id, $token_data['hash'], $token_data['raw'], $expires_at );

		if ( ! $saved ) {
			return new WP_REST_Response( array( 'error' => __( 'Could not save the link. Please try again.', 'erdo-draft-links' ) ), 500 );
		}

		$link = $this->db->get_link_by_post( $post_id );
		return new WP_REST_Response( $this->link_to_response( $link, $post_id ), 201 );
	}

	public function rest_revoke_link( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$this->db->revoke_link( $post_id );
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	// -------------------------------------------------------------------------
	// Meta Box (Classic Editor)
	// -------------------------------------------------------------------------

	public function register_meta_box(): void {
		// In the block editor the "Erdo Draft Links" PluginSidebar (sidebar.js)
		// provides the same controls via REST. This PHP meta box is only wired
		// up by classic.js, which isn't enqueued for the block editor — so
		// registering it there would leave dead, unresponsive buttons.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && $screen->is_block_editor() ) {
			return;
		}

		foreach ( Erdo_Draft_Links_Post_Types::get_supported() as $post_type ) {
			add_meta_box(
				'erdo-draft-links-meta-box',
				__( 'Erdo Draft Links', 'erdo-draft-links' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'erdo_draft_link_meta_box_' . $post->ID, 'erdo_draft_link_nonce' );
		$link       = $this->db->get_link_by_post( $post->ID );
		$has_active = $link && (bool) $link->is_active;
		?>
		<div id="erdo-draft-links-classic-wrap" data-post-id="<?php echo esc_attr( $post->ID ); ?>">

			<?php if ( $has_active ) : ?>
				<div class="erdo-draft-links-url-row">
					<input type="text"
					       readonly
					       class="erdo-draft-links-url widefat"
					       value="<?php echo esc_attr( $this->build_url( $post->ID, $link->token_raw ) ); ?>"
					/>
					<button type="button" class="button erdo-draft-links-copy">
						<?php esc_html_e( 'Copy', 'erdo-draft-links' ); ?>
					</button>
				</div>
				<p class="description erdo-draft-links-meta">
					<?php
					if ( $link->expires_at ) {
						printf(
							/* translators: %s: formatted expiry date */
							esc_html__( 'Expires: %s', 'erdo-draft-links' ),
							esc_html(
								wp_date(
									get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
									strtotime( $link->expires_at )
								)
							)
						);
					} else {
						esc_html_e( 'No expiry.', 'erdo-draft-links' );
					}
					?>
				</p>
				<p class="description erdo-draft-links-meta">
					<?php
					printf(
						/* translators: %d: number of views */
						esc_html__( 'Views: %d', 'erdo-draft-links' ),
						(int) $link->view_count
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( ! $has_active ) : ?>
				<p>
					<label for="erdo-draft-links-expiry-classic">
						<?php esc_html_e( 'Expiry:', 'erdo-draft-links' ); ?>
					</label>
					<select name="erdo_draft_link_expiry" id="erdo-draft-links-expiry-classic" class="widefat">
						<option value="24h"><?php esc_html_e( '24 Hours', 'erdo-draft-links' ); ?></option>
						<option value="48h"><?php esc_html_e( '48 Hours', 'erdo-draft-links' ); ?></option>
						<option value="7d"><?php esc_html_e( '7 Days', 'erdo-draft-links' ); ?></option>
						<option value="never"><?php esc_html_e( 'No Expiry', 'erdo-draft-links' ); ?></option>
					</select>
				</p>
			<?php endif; ?>

			<div class="erdo-draft-links-actions">
				<?php if ( ! $has_active ) : ?>
					<button type="button" class="button button-primary erdo-draft-links-generate">
						<?php esc_html_e( 'Generate Draft Link', 'erdo-draft-links' ); ?>
					</button>
				<?php else : ?>
					<button type="button" class="button erdo-draft-links-regenerate">
						<?php esc_html_e( 'Regenerate', 'erdo-draft-links' ); ?>
					</button>
					<button type="button" class="button erdo-draft-links-revoke">
						<?php esc_html_e( 'Revoke', 'erdo-draft-links' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<span class="erdo-draft-links-spinner spinner" style="display:none;float:none;margin:4px 0;"></span>
			<div class="erdo-draft-links-notice" style="display:none;"></div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Script enqueue
	// -------------------------------------------------------------------------

	public function enqueue_block_editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->post_type, Erdo_Draft_Links_Post_Types::get_supported(), true ) ) {
			return;
		}

		$asset_file = ERDO_DRAFT_LINKS_PLUGIN_DIR . 'assets/js/build/sidebar.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = include $asset_file;

		wp_enqueue_script(
			'erdo-draft-links-sidebar',
			plugins_url( 'assets/js/build/sidebar.js', ERDO_DRAFT_LINKS_PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'erdo-draft-links-sidebar', 'erdo-draft-links', ERDO_DRAFT_LINKS_PLUGIN_DIR . 'languages' );

		wp_localize_script(
			'erdo-draft-links-sidebar',
			'erdoDraftLinksData',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'erdo-draft-links/v1' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'postTypes' => Erdo_Draft_Links_Post_Types::get_supported(),
				'expiries'  => array(
					array( 'value' => '24h',   'label' => __( '24 Hours', 'erdo-draft-links' ) ),
					array( 'value' => '48h',   'label' => __( '48 Hours', 'erdo-draft-links' ) ),
					array( 'value' => '7d',    'label' => __( '7 Days',   'erdo-draft-links' ) ),
					array( 'value' => 'never', 'label' => __( 'No Expiry', 'erdo-draft-links' ) ),
				),
			)
		);

		wp_enqueue_style(
			'erdo-draft-links-admin',
			plugins_url( 'assets/css/admin.css', ERDO_DRAFT_LINKS_PLUGIN_FILE ),
			array(),
			ERDO_DRAFT_LINKS_VERSION
		);
	}

	public function enqueue_classic_editor_assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! in_array( $post->post_type, Erdo_Draft_Links_Post_Types::get_supported(), true ) ) {
			return;
		}
		// Skip if Block Editor is active.
		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return;
		}

		$asset_file = ERDO_DRAFT_LINKS_PLUGIN_DIR . 'assets/js/build/classic.asset.php';
		$deps       = array( 'wp-i18n' );
		$version    = ERDO_DRAFT_LINKS_VERSION;
		if ( file_exists( $asset_file ) ) {
			$asset   = include $asset_file;
			$deps    = $asset['dependencies'];
			$version = $asset['version'];
		}

		wp_enqueue_script(
			'erdo-draft-links-classic',
			plugins_url( 'assets/js/build/classic.js', ERDO_DRAFT_LINKS_PLUGIN_FILE ),
			$deps,
			$version,
			true
		);

		wp_set_script_translations( 'erdo-draft-links-classic', 'erdo-draft-links', ERDO_DRAFT_LINKS_PLUGIN_DIR . 'languages' );

		wp_localize_script(
			'erdo-draft-links-classic',
			'erdoDraftLinksClassicData',
			array(
				'restUrl' => esc_url_raw( rest_url( 'erdo-draft-links/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'copy'       => __( 'Copy', 'erdo-draft-links' ),
					'copied'     => __( 'Copied!', 'erdo-draft-links' ),
					'generate'   => __( 'Generate Draft Link', 'erdo-draft-links' ),
					'regenerate' => __( 'Regenerate', 'erdo-draft-links' ),
					'revoke'     => __( 'Revoke', 'erdo-draft-links' ),
					'noExpiry'   => __( 'No expiry.', 'erdo-draft-links' ),
					/* translators: %s: formatted date */
					'expires'    => __( 'Expires: %s', 'erdo-draft-links' ),
					/* translators: %d: view count */
					'views'      => __( 'Views: %d', 'erdo-draft-links' ),
					'error'      => __( 'An error occurred. Please try again.', 'erdo-draft-links' ),
				),
			)
		);

		wp_enqueue_style(
			'erdo-draft-links-admin',
			plugins_url( 'assets/css/admin.css', ERDO_DRAFT_LINKS_PLUGIN_FILE ),
			array(),
			ERDO_DRAFT_LINKS_VERSION
		);
	}

	// -------------------------------------------------------------------------
	// Admin Menu & Manager Page
	// -------------------------------------------------------------------------

	public function register_admin_menu(): void {
		add_management_page(
			__( 'Erdo Draft Links', 'erdo-draft-links' ),
			__( 'Erdo Draft Links', 'erdo-draft-links' ),
			'edit_posts',
			'erdo-draft-links-manager',
			array( $this, 'render_manager_page' )
		);
	}

	public function handle_admin_actions(): void {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$page   = 'tools.php?page=erdo-draft-links-manager';

		if ( 'erdo_draft_feedback_delete' === $action ) {
			$feedback_id = isset( $_GET['feedback_id'] ) ? absint( $_GET['feedback_id'] ) : 0;
			if ( ! $feedback_id ) {
				return;
			}
			check_admin_referer( 'erdo_draft_feedback_delete_' . $feedback_id );
			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'erdo-draft-links' ) );
			}
			$this->db->delete_feedback( $feedback_id );
			wp_safe_redirect( add_query_arg( array( 'tab' => 'feedback', 'gl_message' => 'feedback_deleted' ), admin_url( $page ) ) );
			exit;
		}

		if ( 'erdo_draft_feedback_status' === $action ) {
			$feedback_id = isset( $_GET['feedback_id'] ) ? absint( $_GET['feedback_id'] ) : 0;
			$status      = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! $feedback_id ) {
				return;
			}
			check_admin_referer( 'erdo_draft_feedback_status_' . $feedback_id );
			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'erdo-draft-links' ) );
			}
			$this->db->update_feedback_status( $feedback_id, $status );
			wp_safe_redirect( add_query_arg( array( 'tab' => 'feedback', 'gl_message' => 'feedback_status_updated' ), admin_url( $page ) ) );
			exit;
		}

		$link_id = isset( $_GET['link_id'] ) ? absint( $_GET['link_id'] ) : 0;

		if ( ! $link_id || ! in_array( $action, array( 'erdo_draft_link_revoke', 'erdo_draft_link_delete' ), true ) ) {
			// Check bulk delete — action/action2 come from WP_List_Table's own form.
			$bulk_action2 = isset( $_GET['action2'] ) ? sanitize_key( wp_unslash( $_GET['action2'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'delete' === $action || 'delete' === $bulk_action2 ) {
				$this->handle_bulk_delete();
			}
			return;
		}

		check_admin_referer( 'erdo_draft_link_action_' . $link_id );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'erdo-draft-links' ) );
		}

		if ( 'erdo_draft_link_revoke' === $action ) {
			$link = $this->db->get_link_by_id( $link_id );
			if ( $link ) {
				$this->db->revoke_link( (int) $link->post_id );
			}
			wp_safe_redirect( add_query_arg( 'gl_message', 'revoked', admin_url( $page ) ) );
			exit;
		}

		if ( 'erdo_draft_link_delete' === $action ) {
			$this->db->delete_link_by_id( $link_id );
			wp_safe_redirect( add_query_arg( 'gl_message', 'deleted', admin_url( $page ) ) );
			exit;
		}
	}

	private function handle_bulk_delete(): void {
		if ( ! isset( $_GET['link_ids'] ) || ! is_array( $_GET['link_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		check_admin_referer( 'bulk-erdo_draft_links' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$ids     = array_map( 'absint', $_GET['link_ids'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( $id > 0 ) {
				$this->db->delete_link_by_id( $id );
				++$deleted;
			}
		}
		wp_safe_redirect( add_query_arg( array( 'gl_message' => 'bulk_deleted', 'gl_count' => $deleted ), admin_url( 'tools.php?page=erdo-draft-links-manager' ) ) );
		exit;
	}

	public function enqueue_manager_assets( string $hook ): void {
		if ( 'tools_page_erdo-draft-links-manager' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'erdo-draft-links-admin',
			plugins_url( 'assets/css/admin.css', ERDO_DRAFT_LINKS_PLUGIN_FILE ),
			array(),
			ERDO_DRAFT_LINKS_VERSION
		);
		wp_enqueue_script(
			'erdo-draft-links-admin-list',
			plugins_url( 'assets/js/admin-list.js', ERDO_DRAFT_LINKS_PLUGIN_FILE ),
			array(),
			ERDO_DRAFT_LINKS_VERSION,
			true
		);
	}

	public function render_manager_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'erdo-draft-links' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'links'; // phpcs:ignore WordPress.Security.NonceVerification

		$message = isset( $_GET['gl_message'] ) ? sanitize_key( $_GET['gl_message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$count   = isset( $_GET['gl_count'] ) ? absint( $_GET['gl_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		$last_cleanup = get_option( 'erdo_draft_link_last_cleanup' );
		$base_url     = admin_url( 'tools.php?page=erdo-draft-links-manager' );
		?>
		<div class="wrap erdo-draft-links-manager">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Erdo Draft Links', 'erdo-draft-links' ); ?>
			</h1>
			<hr class="wp-header-end">

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $base_url ); ?>" class="nav-tab <?php echo 'feedback' !== $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Links', 'erdo-draft-links' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'feedback', $base_url ) ); ?>" class="nav-tab <?php echo 'feedback' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php
					printf(
						/* translators: %d: number of feedback entries */
						esc_html__( 'Feedback (%d)', 'erdo-draft-links' ),
						$this->db->get_feedback_count()
					);
					?>
				</a>
			</h2>

			<?php if ( $message ) : ?>
				<div class="notice notice-success is-dismissible"><p>
				<?php
				switch ( $message ) {
					case 'revoked':
						esc_html_e( 'Ghost link revoked.', 'erdo-draft-links' );
						break;
					case 'deleted':
						esc_html_e( 'Ghost link deleted.', 'erdo-draft-links' );
						break;
					case 'bulk_deleted':
						/* translators: %d: number of deleted links */
						printf( esc_html__( '%d ghost link(s) deleted.', 'erdo-draft-links' ), (int) $count );
						break;
					case 'feedback_deleted':
						esc_html_e( 'Feedback deleted.', 'erdo-draft-links' );
						break;
					case 'feedback_status_updated':
						esc_html_e( 'Feedback status updated.', 'erdo-draft-links' );
						break;
				}
				?>
				</p></div>
			<?php endif; ?>

			<?php if ( 'feedback' === $tab ) : ?>

				<?php
				$feedback_table = new Erdo_Draft_Links_Feedback_List( $this->db );
				$feedback_table->prepare_items();
				?>
				<form method="get">
					<input type="hidden" name="page" value="erdo-draft-links-manager" />
					<input type="hidden" name="tab" value="feedback" />
					<?php $feedback_table->display(); ?>
				</form>

			<?php else : ?>

				<?php if ( $last_cleanup ) : ?>
					<p class="erdo-draft-links-cleanup-note">
						<?php
						printf(
							/* translators: 1: cleanup date, 2: number of links deleted */
							esc_html__( 'Last auto-cleanup: %1$s (%2$d expired link(s) removed).', 'erdo-draft-links' ),
							esc_html( $last_cleanup['time'] ),
							(int) $last_cleanup['deleted']
						);
						?>
					</p>
				<?php endif; ?>

				<?php
				$list_table = new Erdo_Draft_Links_Admin_List( $this->db );
				$list_table->prepare_items();
				?>
				<form method="get">
					<input type="hidden" name="page" value="erdo-draft-links-manager" />
					<?php
					$list_table->views();
					$list_table->search_box( __( 'Search', 'erdo-draft-links' ), 'erdo_draft_link' );
					$list_table->display();
					?>
				</form>

			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	public function on_delete_post( int $post_id ): void {
		$this->db->delete_by_post( $post_id );
		$this->db->delete_feedback_by_post( $post_id );
	}

	private function compute_expires_at( string $expiry ): ?string {
		$map = array(
			'24h' => '+24 hours',
			'48h' => '+48 hours',
			'7d'  => '+7 days',
		);
		if ( ! isset( $map[ $expiry ] ) ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', strtotime( $map[ $expiry ], current_time( 'timestamp', true ) ) );
	}

	private function build_url( int $post_id, string $token_raw ): string {
		return add_query_arg( 'erdo_token', $token_raw, get_permalink( $post_id ) );
	}

	private function link_to_response( object $link, int $post_id ): array {
		return array(
			'url'        => $this->build_url( $post_id, $link->token_raw ),
			'expires_at' => $link->expires_at,
			'view_count' => (int) $link->view_count,
			'is_active'  => (bool) $link->is_active,
		);
	}

	private function post_id_arg(): array {
		return array(
			'post_id' => array(
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
		);
	}
}
