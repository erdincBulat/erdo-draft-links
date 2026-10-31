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
		$loader->add_action( 'transition_post_status',      $this, 'maybe_close_links_on_publish', 10, 3 );
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
						<?php $default_expiry = Erdo_Draft_Links_Settings::get( 'default_expiry' ); ?>
						<option value="24h" <?php selected( $default_expiry, '24h' ); ?>><?php esc_html_e( '24 Hours', 'erdo-draft-links' ); ?></option>
						<option value="48h" <?php selected( $default_expiry, '48h' ); ?>><?php esc_html_e( '48 Hours', 'erdo-draft-links' ); ?></option>
						<option value="7d" <?php selected( $default_expiry, '7d' ); ?>><?php esc_html_e( '7 Days', 'erdo-draft-links' ); ?></option>
						<option value="30d" <?php selected( $default_expiry, '30d' ); ?>><?php esc_html_e( '30 Days', 'erdo-draft-links' ); ?></option>
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
				'defaultExpiry' => Erdo_Draft_Links_Settings::get( 'default_expiry' ),
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
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * With the "turn off links when published" setting on, a draft's share
	 * links stop working the moment it goes live.
	 */
	public function maybe_close_links_on_publish( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! Erdo_Draft_Links_Settings::get( 'close_on_publish' ) ) {
			return;
		}
		foreach ( $this->db->get_active_links_by_post( $post->ID ) as $link ) {
			$this->db->revoke_link_by_id( (int) $link->id );
		}
	}

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
