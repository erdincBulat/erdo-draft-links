<?php
defined( 'ABSPATH' ) || exit;

class Erdo_Draft_Links_Admin {

	private Erdo_Draft_Links_DB $db;
	private Erdo_Draft_Links_Token $token;
	private Erdo_Draft_Links_Review $review;

	public function __construct( Erdo_Draft_Links_DB $db, Erdo_Draft_Links_Token $token, Erdo_Draft_Links_Review $review ) {
		$this->db     = $db;
		$this->token  = $token;
		$this->review = $review;
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
						'label'         => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'expiry'        => array(
							'type'              => 'string',
							'default'           => '24h',
							'enum'              => array( '24h', '48h', '7d', '30d', 'never', 'custom' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'custom_expiry' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						// Not sanitized: stored only as a hash, compared as typed.
						'password'      => array(
							'type'    => 'string',
							'default' => '',
						),
						'max_views'     => array(
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						),
					)
				),
			)
		);

		register_rest_route(
			$namespace,
			'/link/(?P<post_id>\d+)/update',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_update_link' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array_merge(
					$this->post_id_arg(),
					array(
						'link_id'       => array(
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'label'         => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'expiry'        => array(
							'type' => 'string',
							'enum' => array( '24h', '48h', '7d', '30d', 'never', 'custom' ),
						),
						'custom_expiry' => array(
							'type'              => 'string',
							'default'           => '',
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
				'args'                => array_merge(
					$this->post_id_arg(),
					array(
						'link_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
					)
				),
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
		// serve a stale response to the editor sidebar.
		nocache_headers();

		$post_id = (int) $request->get_param( 'post_id' );
		$links   = $this->db->get_active_links_by_post( $post_id );

		return new WP_REST_Response(
			array(
				'links'      => array_map( fn( $link ) => $this->link_to_response( $link ), $links ),
				'review'     => $this->review->get_post_review( $post_id ),
				'open_count' => $this->db->count_open_feedback( $post_id ),
			),
			200
		);
	}

	public function rest_generate_link( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$label   = mb_substr( (string) $request->get_param( 'label' ), 0, 100 );
		$expiry  = $request->get_param( 'expiry' );

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, Erdo_Draft_Links_Post_Types::get_supported(), true ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Draft links are not enabled for this post type.', 'erdo-draft-links' ) ), 400 );
		}
		if ( 'auto-draft' === $post->post_status ) {
			return new WP_REST_Response( array( 'error' => __( 'Save the post first, then generate a draft link.', 'erdo-draft-links' ) ), 400 );
		}
		if ( 'trash' === $post->post_status ) {
			return new WP_REST_Response( array( 'error' => __( 'Draft links cannot be generated for posts in the trash.', 'erdo-draft-links' ) ), 400 );
		}

		$expires_at = $this->expires_at_from_request( $request );
		if ( false === $expires_at ) {
			return new WP_REST_Response( array( 'error' => __( 'Please choose a valid future date and time.', 'erdo-draft-links' ) ), 400 );
		}

		$token_data = $this->token->generate();
		$password   = (string) $request->get_param( 'password' );
		$link_id    = $this->db->insert_link(
			array(
				'post_id'       => $post_id,
				'label'         => $label,
				'token_hash'    => $token_data['hash'],
				'token_enc'     => $token_data['enc'],
				'expires_at'    => $expires_at,
				'created_by'    => get_current_user_id(),
				'password_hash' => '' !== $password ? wp_hash_password( $password ) : '',
				'max_views'     => (int) $request->get_param( 'max_views' ),
			)
		);

		if ( ! $link_id ) {
			return new WP_REST_Response( array( 'error' => __( 'Could not save the link. Please try again.', 'erdo-draft-links' ) ), 500 );
		}

		$link = $this->db->get_link_by_id( $link_id );
		return new WP_REST_Response( $this->link_to_response( $link ), 201 );
	}

	/**
	 * Expiry from the request's expiry/custom_expiry params: GMT datetime,
	 * null for "never", or false for an invalid custom date.
	 */
	private function expires_at_from_request( WP_REST_Request $request ): string|null|false {
		$expiry = (string) $request->get_param( 'expiry' );
		if ( 'custom' === $expiry ) {
			return $this->compute_custom_expires_at( (string) $request->get_param( 'custom_expiry' ) ) ?? false;
		}
		return $this->token->compute_expires_at( $expiry );
	}

	public function rest_update_link( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$link    = $this->db->get_link_by_id( (int) $request->get_param( 'link_id' ) );

		if ( ! $link || (int) $link->post_id !== $post_id ) {
			return new WP_REST_Response( array( 'error' => __( 'This link does not belong to that post.', 'erdo-draft-links' ) ), 404 );
		}
		if ( ! $link->is_active ) {
			return new WP_REST_Response( array( 'error' => __( 'This link was turned off. Generate a new one instead.', 'erdo-draft-links' ) ), 400 );
		}

		$fields = array();
		if ( null !== $request->get_param( 'label' ) ) {
			$fields['label'] = mb_substr( (string) $request->get_param( 'label' ), 0, 100 );
		}
		if ( null !== $request->get_param( 'expiry' ) ) {
			$expires_at = $this->expires_at_from_request( $request );
			if ( false === $expires_at ) {
				return new WP_REST_Response( array( 'error' => __( 'Please choose a valid future date and time.', 'erdo-draft-links' ) ), 400 );
			}
			$fields['expires_at'] = $expires_at;
		}

		if ( $fields ) {
			$this->db->update_link( (int) $link->id, $fields );
		}

		return new WP_REST_Response( $this->link_to_response( $this->db->get_link_by_id( (int) $link->id ) ), 200 );
	}

	public function rest_revoke_link( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$link_id = (int) $request->get_param( 'link_id' );

		$link = $this->db->get_link_by_id( $link_id );
		if ( ! $link || (int) $link->post_id !== $post_id ) {
			return new WP_REST_Response( array( 'error' => __( 'This link does not belong to that post.', 'erdo-draft-links' ) ), 404 );
		}

		$this->db->revoke_link_by_id( $link_id );
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
		$links = $this->db->get_active_links_by_post( $post->ID );
		?>
		<div id="erdo-draft-links-classic-wrap" data-post-id="<?php echo esc_attr( $post->ID ); ?>">

			<div class="erdo-draft-links-list" id="erdo-draft-links-list">
				<?php foreach ( $links as $link ) : ?>
					<?php echo $this->render_link_row( $link ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built via esc_*() calls inside render_link_row(). ?>
				<?php endforeach; ?>
			</div>

			<div class="erdo-draft-links-add-link">
				<p>
					<label for="erdo-draft-links-label-classic">
						<?php esc_html_e( 'Label (optional):', 'erdo-draft-links' ); ?>
					</label>
					<input type="text" id="erdo-draft-links-label-classic" class="widefat" maxlength="100" placeholder="<?php esc_attr_e( 'e.g. Client A', 'erdo-draft-links' ); ?>" />
				</p>
				<p>
					<label for="erdo-draft-links-expiry-classic">
						<?php esc_html_e( 'Expiry:', 'erdo-draft-links' ); ?>
					</label>
					<select name="erdo_draft_link_expiry" id="erdo-draft-links-expiry-classic" class="widefat">
						<option value="24h"><?php esc_html_e( '24 Hours', 'erdo-draft-links' ); ?></option>
						<option value="48h"><?php esc_html_e( '48 Hours', 'erdo-draft-links' ); ?></option>
						<option value="7d" selected><?php esc_html_e( '7 Days', 'erdo-draft-links' ); ?></option>
						<option value="30d"><?php esc_html_e( '30 Days', 'erdo-draft-links' ); ?></option>
						<option value="never"><?php esc_html_e( 'No Expiry', 'erdo-draft-links' ); ?></option>
						<option value="custom"><?php esc_html_e( 'Custom Date & Time…', 'erdo-draft-links' ); ?></option>
					</select>
				</p>
				<p id="erdo-draft-links-custom-expiry-row-classic" style="display:none;">
					<label for="erdo-draft-links-custom-expiry-classic">
						<?php esc_html_e( 'Expires on:', 'erdo-draft-links' ); ?>
					</label>
					<input type="datetime-local" id="erdo-draft-links-custom-expiry-classic" class="widefat" />
				</p>
				<p>
					<label for="erdo-draft-links-password-classic">
						<?php esc_html_e( 'Password (optional)', 'erdo-draft-links' ); ?>
					</label>
					<input type="password" id="erdo-draft-links-password-classic" class="widefat" autocomplete="new-password" />
				</p>
				<p>
					<label for="erdo-draft-links-max-views-classic">
						<?php esc_html_e( 'Stop working after this many opens (optional)', 'erdo-draft-links' ); ?>
					</label>
					<input type="number" min="0" id="erdo-draft-links-max-views-classic" class="widefat" />
				</p>
				<div class="erdo-draft-links-actions">
					<button type="button" class="button button-primary erdo-draft-links-generate">
						<?php esc_html_e( 'Generate Draft Link', 'erdo-draft-links' ); ?>
					</button>
				</div>
			</div>

			<span class="erdo-draft-links-spinner spinner" style="display:none;float:none;margin:4px 0;"></span>
			<div class="erdo-draft-links-notice" style="display:none;"></div>
		</div>
		<?php
	}

	private function render_link_row( object $link ): string {
		$label = '' !== $link->label ? $link->label : __( 'Untitled link', 'erdo-draft-links' );

		ob_start();
		?>
		<div class="erdo-draft-links-link-row" data-link-id="<?php echo esc_attr( $link->id ); ?>">
			<div class="erdo-draft-links-link-header">
				<span class="erdo-draft-links-link-label"><?php echo esc_html( $label ); ?></span>
				<button type="button" class="button-link erdo-draft-links-revoke" data-link-id="<?php echo esc_attr( $link->id ); ?>">
					<?php esc_html_e( 'Revoke', 'erdo-draft-links' ); ?>
				</button>
			</div>
			<div class="erdo-draft-links-url-row">
				<input type="text"
				       readonly
				       class="erdo-draft-links-url widefat"
				       value="<?php echo esc_attr( $this->token->link_url( $link ) ); ?>"
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
		</div>
		<?php
		return (string) ob_get_clean();
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
				'restUrl'       => esc_url_raw( rest_url( 'erdo-draft-links/v1' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'postTypes'     => Erdo_Draft_Links_Post_Types::get_supported(),
				'defaultExpiry' => '7d',
				'expiries'      => array(
					array( 'value' => '24h',    'label' => __( '24 Hours', 'erdo-draft-links' ) ),
					array( 'value' => '48h',    'label' => __( '48 Hours', 'erdo-draft-links' ) ),
					array( 'value' => '7d',     'label' => __( '7 Days',   'erdo-draft-links' ) ),
					array( 'value' => '30d',    'label' => __( '30 Days',  'erdo-draft-links' ) ),
					array( 'value' => 'never',  'label' => __( 'No Expiry', 'erdo-draft-links' ) ),
					array( 'value' => 'custom', 'label' => __( 'Custom Date & Time…', 'erdo-draft-links' ) ),
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
					'copy'     => __( 'Copy', 'erdo-draft-links' ),
					'copied'   => __( 'Copied!', 'erdo-draft-links' ),
					'generate' => __( 'Generate Draft Link', 'erdo-draft-links' ),
					'revoke'   => __( 'Revoke', 'erdo-draft-links' ),
					'noExpiry' => __( 'No expiry.', 'erdo-draft-links' ),
					'untitled' => __( 'Untitled link', 'erdo-draft-links' ),
					/* translators: %s: formatted date */
					'expires'  => __( 'Expires: %s', 'erdo-draft-links' ),
					/* translators: %d: view count */
					'views'    => __( 'Views: %d', 'erdo-draft-links' ),
					'error'    => __( 'An error occurred. Please try again.', 'erdo-draft-links' ),
					'invalidCustomExpiry' => __( 'Please choose a valid future date and time.', 'erdo-draft-links' ),
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

		if ( isset( $_POST['action'] ) && 'erdo_draft_feedback_reply' === sanitize_key( wp_unslash( $_POST['action'] ) ) ) {
			$feedback_id = isset( $_POST['feedback_id'] ) ? absint( $_POST['feedback_id'] ) : 0;
			if ( ! $feedback_id ) {
				return;
			}
			check_admin_referer( 'erdo_draft_feedback_reply_' . $feedback_id );
			$feedback = $this->db->get_feedback_by_id( $feedback_id );
			if ( ! $feedback ) {
				return;
			}
			$this->deny_unless_can_manage_post_row( (int) $feedback->post_id );
			$reply = isset( $_POST['reply'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reply'] ) ) : '';
			if ( '' !== $reply ) {
				$user = wp_get_current_user();
				$this->db->add_reply( $feedback, (int) $user->ID, $user->display_name, $reply );
			}
			wp_safe_redirect( add_query_arg( array( 'tab' => 'feedback', 'edl_message' => 'feedback_replied' ), admin_url( $page ) ) );
			exit;
		}

		if ( isset( $_POST['action'] ) && 'erdo_draft_links_save_settings' === sanitize_key( wp_unslash( $_POST['action'] ) ) ) {
			check_admin_referer( 'erdo_draft_links_save_settings' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'erdo-draft-links' ) );
			}
			$selected = isset( $_POST['erdo_draft_link_post_types'] ) && is_array( $_POST['erdo_draft_link_post_types'] )
				? array_map( 'sanitize_key', wp_unslash( $_POST['erdo_draft_link_post_types'] ) )
				: array();
			// Only persist slugs that are actually selectable, so a tampered
			// request can't store an arbitrary/internal post type.
			$valid    = wp_list_pluck( Erdo_Draft_Links_Post_Types::get_selectable(), 'name' );
			$selected = array_values( array_intersect( $selected, $valid ) );
			update_option( Erdo_Draft_Links_Post_Types::OPTION_KEY, $selected );
			wp_safe_redirect( add_query_arg( array( 'tab' => 'settings', 'edl_message' => 'settings_saved' ), admin_url( $page ) ) );
			exit;
		}

		if ( 'erdo_draft_feedback_delete' === $action ) {
			$feedback_id = isset( $_GET['feedback_id'] ) ? absint( $_GET['feedback_id'] ) : 0;
			if ( ! $feedback_id ) {
				return;
			}
			check_admin_referer( 'erdo_draft_feedback_delete_' . $feedback_id );
			$feedback = $this->db->get_feedback_by_id( $feedback_id );
			if ( ! $feedback ) {
				return;
			}
			$this->deny_unless_can_manage_post_row( (int) $feedback->post_id );
			$this->db->delete_feedback( $feedback_id );
			wp_safe_redirect( add_query_arg( array( 'tab' => 'feedback', 'edl_message' => 'feedback_deleted' ), admin_url( $page ) ) );
			exit;
		}

		if ( 'erdo_draft_feedback_status' === $action ) {
			$feedback_id = isset( $_GET['feedback_id'] ) ? absint( $_GET['feedback_id'] ) : 0;
			$status      = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! $feedback_id ) {
				return;
			}
			check_admin_referer( 'erdo_draft_feedback_status_' . $feedback_id );
			$feedback = $this->db->get_feedback_by_id( $feedback_id );
			if ( ! $feedback ) {
				return;
			}
			$this->deny_unless_can_manage_post_row( (int) $feedback->post_id );
			$this->db->update_feedback_status( $feedback_id, $status );
			wp_safe_redirect( add_query_arg( array( 'tab' => 'feedback', 'edl_message' => 'feedback_status_updated' ), admin_url( $page ) ) );
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

		$link = $this->db->get_link_by_id( $link_id );
		if ( ! $link ) {
			return;
		}
		$this->deny_unless_can_manage_post_row( (int) $link->post_id );

		if ( 'erdo_draft_link_revoke' === $action ) {
			$this->db->revoke_link_by_id( $link_id );
			wp_safe_redirect( add_query_arg( 'edl_message', 'revoked', admin_url( $page ) ) );
			exit;
		}

		if ( 'erdo_draft_link_delete' === $action ) {
			$this->db->delete_link_by_id( $link_id );
			wp_safe_redirect( add_query_arg( 'edl_message', 'deleted', admin_url( $page ) ) );
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
			$link = $id > 0 ? $this->db->get_link_by_id( $id ) : null;
			// Silently skip rows this user may not manage (e.g. a tampered request).
			if ( $link && $this->can_manage_post_row( (int) $link->post_id ) ) {
				$this->db->delete_link_by_id( $id );
				++$deleted;
			}
		}
		wp_safe_redirect( add_query_arg( array( 'edl_message' => 'bulk_deleted', 'edl_count' => $deleted ), admin_url( 'tools.php?page=erdo-draft-links-manager' ) ) );
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

		// gl_* are the pre-2.0 parameter names, still read so old bookmarks work.
		// phpcs:disable WordPress.Security.NonceVerification
		$message = isset( $_GET['edl_message'] ) ? sanitize_key( $_GET['edl_message'] ) : ( isset( $_GET['edl_message'] ) ? sanitize_key( $_GET['edl_message'] ) : '' );
		$count   = isset( $_GET['edl_count'] ) ? absint( $_GET['edl_count'] ) : ( isset( $_GET['gl_count'] ) ? absint( $_GET['gl_count'] ) : 0 );
		// phpcs:enable WordPress.Security.NonceVerification

		$last_cleanup = get_option( 'erdo_draft_link_last_cleanup' );
		$base_url     = admin_url( 'tools.php?page=erdo-draft-links-manager' );
		?>
		<div class="wrap erdo-draft-links-manager">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Erdo Draft Links', 'erdo-draft-links' ); ?>
			</h1>
			<hr class="wp-header-end">

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $base_url ); ?>" class="nav-tab <?php echo 'links' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Links', 'erdo-draft-links' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'feedback', $base_url ) ); ?>" class="nav-tab <?php echo 'feedback' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php
					printf(
						/* translators: %d: number of feedback entries */
						esc_html__( 'Feedback (%d)', 'erdo-draft-links' ),
						(int) $this->db->get_feedback_count( 0, $this->scope_author_id() )
					);
					?>
				</a>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $base_url ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">
						<?php esc_html_e( 'Settings', 'erdo-draft-links' ); ?>
					</a>
				<?php endif; ?>
			</h2>

			<?php if ( $message ) : ?>
				<div class="notice notice-success is-dismissible"><p>
				<?php
				switch ( $message ) {
					case 'revoked':
						esc_html_e( 'Draft link revoked.', 'erdo-draft-links' );
						break;
					case 'deleted':
						esc_html_e( 'Draft link deleted.', 'erdo-draft-links' );
						break;
					case 'bulk_deleted':
						/* translators: %d: number of deleted links */
						printf( esc_html( _n( '%d draft link deleted.', '%d draft links deleted.', $count, 'erdo-draft-links' ) ), (int) $count );
						break;
					case 'feedback_deleted':
						esc_html_e( 'Feedback deleted.', 'erdo-draft-links' );
						break;
					case 'feedback_status_updated':
						esc_html_e( 'Feedback status updated.', 'erdo-draft-links' );
						break;
					case 'feedback_replied':
						esc_html_e( 'Reply saved.', 'erdo-draft-links' );
						break;
					case 'settings_saved':
						esc_html_e( 'Settings saved.', 'erdo-draft-links' );
						break;
				}
				?>
				</p></div>
			<?php endif; ?>

			<?php if ( 'feedback' === $tab ) : ?>

				<?php
				// Not wrapped in a <form> like the Links tab: this table has no bulk
				// actions or search box, and each row renders its own reply <form> —
				// nesting that inside an outer <form> would be invalid HTML.
				$feedback_table = new Erdo_Draft_Links_Feedback_List( $this->db, 0, $this->scope_author_id() );
				$feedback_table->prepare_items();
				$feedback_table->display();
				?>

			<?php elseif ( 'settings' === $tab && current_user_can( 'manage_options' ) ) : ?>

				<?php $this->render_settings_tab(); ?>

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
				$list_table = new Erdo_Draft_Links_Admin_List( $this->db, $this->token, $this->scope_author_id() );
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

	private function render_settings_tab(): void {
		$supported = Erdo_Draft_Links_Post_Types::get_supported();
		?>
		<h2><?php esc_html_e( 'Supported Post Types', 'erdo-draft-links' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Choose which post types can have draft links generated for them.', 'erdo-draft-links' ); ?>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'erdo_draft_links_save_settings' ); ?>
			<input type="hidden" name="action" value="erdo_draft_links_save_settings" />
			<?php foreach ( Erdo_Draft_Links_Post_Types::get_selectable() as $post_type ) : ?>
				<label class="erdo-draft-links-post-type-option">
					<input type="checkbox"
					       name="erdo_draft_link_post_types[]"
					       value="<?php echo esc_attr( $post_type->name ); ?>"
					       <?php checked( in_array( $post_type->name, $supported, true ) ); ?>
					/>
					<?php echo esc_html( $post_type->labels->singular_name ); ?>
					<code><?php echo esc_html( $post_type->name ); ?></code>
				</label>
			<?php endforeach; ?>
			<p>
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Save Changes', 'erdo-draft-links' ); ?>
				</button>
			</p>
		</form>
		<?php if ( has_filter( 'erdo_draft_links_supported_post_types' ) ) : ?>
			<p class="description">
				<?php esc_html_e( 'Note: the erdo_draft_links_supported_post_types filter is also active in code and may override these settings.', 'erdo-draft-links' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	public function on_delete_post( int $post_id ): void {
		$this->db->delete_by_post( $post_id );
		$this->db->delete_feedback_by_post( $post_id );
	}

	/**
	 * $value is the raw "Y-m-d\TH:i" (or "Y-m-d H:i[:s]") string from an HTML
	 * `<input type="datetime-local">`, which carries no timezone — interpreted
	 * as the site's configured local time, the same convention WP core uses
	 * for scheduling posts. Returns a UTC MySQL datetime, or null if the value
	 * is malformed or not in the future.
	 */
	private function compute_custom_expires_at( string $value ): ?string {
		$local = str_replace( 'T', ' ', trim( $value ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $local ) ) {
			return null;
		}

		$gmt_string = get_gmt_from_date( $local, 'Y-m-d H:i:s' );
		if ( strtotime( $gmt_string ) <= time() ) {
			return null;
		}

		return $gmt_string;
	}

	private function link_to_response( object $link ): array {
		return array(
			'id'              => (int) $link->id,
			'label'           => $link->label,
			'url'             => $this->token->link_url( $link ),
			// ISO 8601 UTC for machines, plus a ready-made string in the site's
			// timezone and date format for the editor UIs.
			'expires_at'      => $link->expires_at ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $link->expires_at ) ) : null,
			'expires_display' => $link->expires_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $link->expires_at ) ) : null,
			'view_count'      => (int) $link->view_count,
			'max_views'       => null !== $link->max_views ? (int) $link->max_views : null,
			'last_viewed_at'  => $link->last_viewed_at ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $link->last_viewed_at ) ) : null,
			'has_password'    => '' !== (string) $link->password_hash,
			'is_active'       => (bool) $link->is_active,
		) + $this->review->get_link_review( $link );
	}

	/**
	 * Users who can't edit others' posts only see links and feedback for their
	 * own posts. Returns the author id to filter by, or 0 for no filter.
	 */
	private function scope_author_id(): int {
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
