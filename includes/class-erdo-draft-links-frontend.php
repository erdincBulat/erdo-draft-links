<?php
defined( 'ABSPATH' ) || exit;

class Erdo_Draft_Links_Frontend {

	private Erdo_Draft_Links_DB $db;
	private Erdo_Draft_Links_Token $token;

	private ?int $active_link_id = null;
	private ?int $active_post_id = null;

	public function __construct( Erdo_Draft_Links_DB $db, Erdo_Draft_Links_Token $token ) {
		$this->db    = $db;
		$this->token = $token;
	}

	public function register( Erdo_Draft_Links_Loader $loader ): void {
		// Priority 1: intercept token URL before any other processing.
		$loader->add_action( 'template_redirect', $this, 'handle_token_request', 1 );

		// Priority 1: runs before WP_Query builds SQL; adds draft to allowed statuses.
		$loader->add_action( 'pre_get_posts', $this, 'allow_draft_for_cookie', 1, 1 );

		// Grants edit_post for the shared post only, so page builders (e.g. Elementor)
		// render their content for non-logged-in visitors with a valid preview cookie.
		$loader->add_filter( 'user_has_cap', $this, 'grant_preview_edit_cap', 10, 3 );

		// Priority 5: after the token redirect (priority 1) has had a chance to run/exit.
		$loader->add_action( 'template_redirect', $this, 'handle_feedback_submission', 5 );

		// wp_footer fires on every front-end page regardless of how the page builder
		// rendered the content, so the form appears even with builders (e.g. Elementor
		// Canvas templates) that bypass the the_content filter entirely.
		$loader->add_action( 'wp_footer', $this, 'render_feedback_form_in_footer' );

		$loader->add_action( 'wp_enqueue_scripts', $this, 'enqueue_feedback_assets' );

		$loader->add_action( 'rest_api_init', $this, 'register_rest_routes' );
	}

	// -------------------------------------------------------------------------
	// REST API — AJAX feedback submission (no page reload).
	// -------------------------------------------------------------------------

	public function register_rest_routes(): void {
		register_rest_route(
			'erdo-draft-links/v1',
			'/feedback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_submit_feedback' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'name'    => array( 'required' => true ),
					'message' => array( 'required' => true ),
					'nonce'   => array( 'required' => true ),
				),
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_submit_feedback( WP_REST_Request $request ) {
		$cookie_data = $this->find_valid_cookie_data();
		if ( null === $cookie_data ) {
			return new WP_Error( 'erdo_invalid_session', __( 'An error occurred. Please try again.', 'erdo-draft-links' ), array( 'status' => 403 ) );
		}

		$nonce = sanitize_text_field( (string) $request->get_param( 'nonce' ) );
		if ( ! wp_verify_nonce( $nonce, 'erdo_draft_feedback_' . $cookie_data['link_id'] ) ) {
			return new WP_Error( 'erdo_invalid_nonce', __( 'An error occurred. Please try again.', 'erdo-draft-links' ), array( 'status' => 403 ) );
		}

		$name    = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );

		if ( '' === $name || '' === $message ) {
			return new WP_Error( 'erdo_missing_fields', __( 'An error occurred. Please try again.', 'erdo-draft-links' ), array( 'status' => 400 ) );
		}

		$post_id     = $cookie_data['post_id'];
		$feedback_id = $this->db->add_feedback( $post_id, $name, $message );

		if ( ! $feedback_id ) {
			return new WP_Error( 'erdo_feedback_failed', __( 'An error occurred. Please try again.', 'erdo-draft-links' ), array( 'status' => 500 ) );
		}

		$this->send_feedback_notification( $post_id, $name, $message );

		$status  = Erdo_Draft_Links_DB::FEEDBACK_STATUS_OPEN;
		$initial = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );

		return rest_ensure_response(
			array(
				'success' => true,
				'item'    => array(
					'author_name'  => $name,
					'message'      => $message,
					'status'       => $status,
					'status_label' => Erdo_Draft_Links_DB::get_feedback_status_label( $status ),
					'date'         => wp_date( get_option( 'date_format' ) ),
					'initial'      => mb_strtoupper( $initial ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Step 1: Token URL → validate → set cookie → redirect to clean URL
	// -------------------------------------------------------------------------

	public function handle_token_request(): void {
		if ( ! isset( $_GET['erdo_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$raw_token = sanitize_text_field( wp_unslash( $_GET['erdo_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		// Format guard: exactly 32 alphanumeric characters.
		if ( ! ctype_alnum( $raw_token ) || strlen( $raw_token ) !== 32 ) {
			$this->die_invalid();
		}

		$prefix = substr( $raw_token, 0, 20 );
		$link   = $this->db->get_active_link_by_token_prefix( $prefix );

		if ( ! $link || ! $this->token->verify( $raw_token, $link->token_hash ) ) {
			$this->die_invalid();
		}

		if ( ! $link->is_active ) {
			$this->die_revoked();
		}

		if ( $this->token->is_expired( $link->expires_at ) ) {
			$this->die_expired();
		}

		$this->set_access_cookie( (int) $link->id, $link->token_hash );
		$this->db->increment_view_count( (int) $link->id );

		wp_safe_redirect( get_permalink( (int) $link->post_id ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Step 2: pre_get_posts — fires before SQL is built.
	// If a valid erdo_draft_link cookie exists and the query targets the linked post,
	// expand post_status to include draft/private so the SQL finds the post.
	// -------------------------------------------------------------------------

	public function allow_draft_for_cookie( WP_Query $query ): void {
		// Only act on the main front-end query.
		if ( ! $query->is_main_query() || is_admin() ) {
			return;
		}

		$cookie_data = $this->find_valid_cookie_data();
		if ( null === $cookie_data ) {
			return;
		}

		$active_post_id = $cookie_data['post_id'];

		// Security: only expand status for the specific post ID this cookie grants access to.
		$queried_p       = absint( $query->get( 'p' ) );
		$queried_page_id = absint( $query->get( 'page_id' ) );

		if ( $queried_p !== $active_post_id && $queried_page_id !== $active_post_id ) {
			// Try slug-based matching only if the post is known.
			$post = get_post( $active_post_id );
			if ( ! $post ) {
				return;
			}
			$name     = $query->get( 'name' );
			$pagename = $query->get( 'pagename' );
			if ( $name !== $post->post_name && $pagename !== $post->post_name ) {
				return;
			}
		}

		// Expand post_status to include non-public statuses.
		$statuses = array( 'publish', 'draft', 'private', 'pending', 'future' );
		$query->set( 'post_status', $statuses );

		$this->active_link_id = $cookie_data['link_id'];
		$this->active_post_id = $active_post_id;

		// This response shows draft content via a temporary preview cookie and
		// may include feedback status that changes over time. Prevent caching
		// plugins, CDNs, and browsers from storing it — otherwise a revoked
		// link or an updated feedback status would keep showing a stale copy.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}

	// -------------------------------------------------------------------------
	// Page builder compatibility (Elementor, Divi, Beaver Builder, etc.)
	//
	// Page builders only render their stored layout for non-public posts when
	// the visitor can edit that post. Visitors with a valid preview cookie have
	// no such capability by default, so the builder silently falls back to the
	// raw (often empty) post_content. Granting edit_post for this single post ID
	// makes any builder that follows this standard WordPress check render normally.
	// -------------------------------------------------------------------------

	public function grant_preview_edit_cap( array $allcaps, array $caps, array $args ): array {
		if ( null === $this->active_post_id ) {
			return $allcaps;
		}

		$requested_cap = $args[0] ?? '';
		$object_id     = isset( $args[2] ) ? (int) $args[2] : 0;

		if ( 'edit_post' !== $requested_cap || $object_id !== $this->active_post_id ) {
			return $allcaps;
		}

		foreach ( $caps as $cap ) {
			$allcaps[ $cap ] = true;
		}

		return $allcaps;
	}

	// -------------------------------------------------------------------------
	// Preview feedback — lets the visitor leave a name + message on the
	// shared draft, stored as a comment and emailed to the site admin.
	// -------------------------------------------------------------------------

	public function enqueue_feedback_assets(): void {
		if ( null === $this->active_post_id || get_queried_object_id() !== $this->active_post_id ) {
			return;
		}

		wp_enqueue_style(
			'erdo-draft-links-frontend',
			plugins_url( 'assets/css/frontend.css', ERDO_DRAFT_LINKS_PLUGIN_FILE ),
			array(),
			ERDO_DRAFT_LINKS_VERSION
		);

		wp_enqueue_script(
			'erdo-draft-links-frontend',
			plugins_url( 'assets/js/frontend.js', ERDO_DRAFT_LINKS_PLUGIN_FILE ),
			array(),
			ERDO_DRAFT_LINKS_VERSION,
			true
		);

		wp_localize_script(
			'erdo-draft-links-frontend',
			'erdoDraftFeedback',
			array(
				'restUrl' => rest_url( 'erdo-draft-links/v1/feedback' ),
				'i18n'    => array(
					'submit'  => __( 'Send Feedback', 'erdo-draft-links' ),
					'sending' => __( 'Sending…', 'erdo-draft-links' ),
					'success' => __( 'Thanks! Your feedback has been sent.', 'erdo-draft-links' ),
					'error'   => __( 'An error occurred. Please try again.', 'erdo-draft-links' ),
				),
			)
		);
	}

	public function render_feedback_form_in_footer(): void {
		if ( null === $this->active_post_id || ! is_singular() ) {
			return;
		}

		if ( get_queried_object_id() !== $this->active_post_id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built via esc_*() calls inside render_feedback_form().
		echo $this->render_feedback_form( (int) $this->active_link_id );
	}

	private function render_feedback_form( int $link_id ): string {
		$sent = isset( $_GET['erdo_feedback'] ) && 'sent' === $_GET['erdo_feedback']; // phpcs:ignore WordPress.Security.NonceVerification

		ob_start();
		?>
		<div class="erdo-draft-feedback">
			<button type="button" class="erdo-draft-feedback-toggle" aria-expanded="false" aria-controls="erdo-draft-feedback-panel">
				<svg class="erdo-draft-feedback-toggle-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
					<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
				</svg>
				<span class="erdo-draft-feedback-toggle-label"><?php esc_html_e( 'Feedback', 'erdo-draft-links' ); ?></span>
			</button>
			<div class="erdo-draft-feedback-panel" id="erdo-draft-feedback-panel" hidden <?php echo $sent ? 'data-auto-open="1"' : ''; ?>>
				<div class="erdo-draft-feedback-header">
					<div class="erdo-draft-feedback-heading">
						<h3 class="erdo-draft-feedback-title"><?php esc_html_e( 'Leave Feedback', 'erdo-draft-links' ); ?></h3>
						<p class="erdo-draft-feedback-description">
							<?php esc_html_e( 'Have comments about this draft? Let the author know below.', 'erdo-draft-links' ); ?>
						</p>
					</div>
					<button type="button" class="erdo-draft-feedback-close" aria-label="<?php esc_attr_e( 'Close', 'erdo-draft-links' ); ?>">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
							<path d="M18 6 6 18"></path>
							<path d="m6 6 12 12"></path>
						</svg>
					</button>
				</div>
				<div class="erdo-draft-feedback-body">
					<div class="erdo-draft-feedback-notice" id="erdo-draft-feedback-notice">
						<?php if ( $sent ) : ?>
							<div class="erdo-draft-feedback-success" role="status">
								<svg class="erdo-draft-feedback-success-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
									<path d="M20 6 9 17l-5-5"></path>
								</svg>
								<span><?php esc_html_e( 'Thanks! Your feedback has been sent.', 'erdo-draft-links' ); ?></span>
							</div>
						<?php endif; ?>
					</div>

					<form method="post" class="erdo-draft-feedback-form" id="erdo-draft-feedback-form">
						<?php wp_nonce_field( 'erdo_draft_feedback_' . $link_id, 'erdo_feedback_nonce' ); ?>
						<p class="erdo-draft-feedback-field">
							<label for="erdo-feedback-name"><?php esc_html_e( 'Name', 'erdo-draft-links' ); ?></label>
							<input type="text" id="erdo-feedback-name" name="erdo_feedback_name" maxlength="100" required="required" />
						</p>
						<p class="erdo-draft-feedback-field">
							<label for="erdo-feedback-message"><?php esc_html_e( 'Your feedback', 'erdo-draft-links' ); ?></label>
							<textarea id="erdo-feedback-message" name="erdo_feedback_message" rows="4" maxlength="2000" required="required"></textarea>
						</p>
						<p>
							<button type="submit" name="erdo_draft_feedback_submit" value="1" class="erdo-draft-feedback-submit">
								<span class="erdo-draft-feedback-submit-label"><?php esc_html_e( 'Send Feedback', 'erdo-draft-links' ); ?></span>
							</button>
						</p>
					</form>

					<?php $history = $this->db->get_feedback_paginated( 1, 50, (int) $this->active_post_id ); ?>
					<div class="erdo-draft-feedback-history" id="erdo-draft-feedback-history" <?php echo empty( $history ) ? 'hidden' : ''; ?>>
						<h4 class="erdo-draft-feedback-history-title"><?php esc_html_e( 'Past Feedback', 'erdo-draft-links' ); ?></h4>
						<ul class="erdo-draft-feedback-history-list" id="erdo-draft-feedback-history-list">
							<?php foreach ( $history as $item ) : ?>
								<?php
								$item_status = $item->status;
								$item_label  = Erdo_Draft_Links_DB::get_feedback_status_label( $item_status );
								$initial     = function_exists( 'mb_substr' ) ? mb_substr( $item->author_name, 0, 1 ) : substr( $item->author_name, 0, 1 );
								?>
								<li class="erdo-draft-feedback-history-item">
									<div class="erdo-draft-feedback-history-head">
										<span class="erdo-draft-feedback-history-author-row">
											<span class="erdo-draft-feedback-history-avatar" aria-hidden="true"><?php echo esc_html( mb_strtoupper( $initial ) ); ?></span>
											<span class="erdo-draft-feedback-history-author"><?php echo esc_html( $item->author_name ); ?></span>
										</span>
										<span class="erdo-draft-feedback-status erdo-draft-feedback-status--<?php echo esc_attr( $item_status ); ?>"><?php echo esc_html( $item_label ); ?></span>
									</div>
									<p class="erdo-draft-feedback-history-message"><?php echo esc_html( $item->message ); ?></p>
									<span class="erdo-draft-feedback-history-date">
										<?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $item->created_at ) ) ); ?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function handle_feedback_submission(): void {
		if ( ! isset( $_POST['erdo_draft_feedback_submit'] ) ) {
			return;
		}

		$cookie_data = $this->find_valid_cookie_data();
		if ( null === $cookie_data ) {
			return;
		}

		$nonce = isset( $_POST['erdo_feedback_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['erdo_feedback_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'erdo_draft_feedback_' . $cookie_data['link_id'] ) ) {
			return;
		}

		$name    = isset( $_POST['erdo_feedback_name'] ) ? sanitize_text_field( wp_unslash( $_POST['erdo_feedback_name'] ) ) : '';
		$message = isset( $_POST['erdo_feedback_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['erdo_feedback_message'] ) ) : '';

		if ( '' === $name || '' === $message ) {
			return;
		}

		$post_id     = $cookie_data['post_id'];
		$feedback_id = $this->db->add_feedback( $post_id, $name, $message );

		if ( $feedback_id ) {
			$this->send_feedback_notification( $post_id, $name, $message );
		}

		wp_safe_redirect( add_query_arg( 'erdo_feedback', 'sent', get_permalink( $post_id ) ) );
		exit;
	}

	private function send_feedback_notification( int $post_id, string $name, string $message ): void {
		$post_title = get_the_title( $post_id );

		$subject = sprintf(
			/* translators: %s: post title */
			__( 'New feedback on draft preview: %s', 'erdo-draft-links' ),
			$post_title
		);

		$body = sprintf(
			/* translators: 1: commenter name, 2: post title, 3: feedback message, 4: admin URL to manage feedback */
			__( "%1\$s left feedback on the draft preview of \"%2\$s\":\n\n%3\$s\n\nManage feedback: %4\$s", 'erdo-draft-links' ),
			$name,
			$post_title,
			$message,
			admin_url( 'tools.php?page=erdo-draft-links-manager&tab=feedback' )
		);

		wp_mail( get_option( 'admin_email' ), $subject, $body );
	}

	// -------------------------------------------------------------------------
	// Cookie helpers
	// -------------------------------------------------------------------------

	private function find_valid_cookie_data(): ?array {
		foreach ( $_COOKIE as $name => $value ) {
			if ( strpos( $name, 'erdo_draft_link_' ) !== 0 ) {
				continue;
			}
			$link_id = (int) substr( $name, strlen( 'erdo_draft_link_' ) );
			if ( $link_id <= 0 ) {
				continue;
			}
			$link = $this->db->get_link_by_id( $link_id );
			if ( ! $link || ! $link->is_active ) {
				continue;
			}
			if ( $this->token->is_expired( $link->expires_at ) ) {
				continue;
			}
			$expected = $this->token->cookie_value( $link->token_hash );
			if ( hash_equals( $expected, sanitize_text_field( wp_unslash( $value ) ) ) ) {
				return array(
					'link_id' => $link_id,
					'post_id' => (int) $link->post_id,
				);
			}
		}
		return null;
	}

	private function set_access_cookie( int $link_id, string $token_hash ): void {
		$name  = 'erdo_draft_link_' . $link_id;
		$value = $this->token->cookie_value( $token_hash );

		setcookie(
			$name,
			$value,
			array(
				'expires'  => time() + 3600,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	private function die_invalid(): void {
		wp_die(
			esc_html__( 'This link is invalid or has expired.', 'erdo-draft-links' ),
			esc_html__( 'Erdo Draft Links', 'erdo-draft-links' ),
			array( 'response' => 403 )
		);
	}

	private function die_expired(): void {
		wp_die(
			esc_html__( 'This Erdo Draft Links has expired and is no longer accessible.', 'erdo-draft-links' ),
			esc_html__( 'Erdo Draft Links', 'erdo-draft-links' ),
			array( 'response' => 410 )
		);
	}

	private function die_revoked(): void {
		wp_die(
			esc_html__( 'This Erdo Draft Links has been revoked.', 'erdo-draft-links' ),
			esc_html__( 'Erdo Draft Links', 'erdo-draft-links' ),
			array( 'response' => 410 )
		);
	}
}
