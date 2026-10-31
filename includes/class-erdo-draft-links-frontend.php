<?php
defined( 'ABSPATH' ) || exit;

class Erdo_Draft_Links_Frontend {

	const QUERY_VAR = 'erdo_review';

	/** Wrong password attempts allowed per link and visitor IP per window. */
	const UNLOCK_MAX_ATTEMPTS = 10;
	const UNLOCK_WINDOW       = 15 * MINUTE_IN_SECONDS;

	private Erdo_Draft_Links_DB $db;
	private Erdo_Draft_Links_Token $token;
	private Erdo_Draft_Links_Session $session;
	private Erdo_Draft_Links_Status_Page $status_page;
	private Erdo_Draft_Links_Notifications $notifications;

	private ?int $active_link_id = null;
	private ?int $active_post_id = null;

	public function __construct( Erdo_Draft_Links_DB $db, Erdo_Draft_Links_Token $token, Erdo_Draft_Links_Session $session, Erdo_Draft_Links_Status_Page $status_page, Erdo_Draft_Links_Notifications $notifications ) {
		$this->db            = $db;
		$this->token         = $token;
		$this->session       = $session;
		$this->status_page   = $status_page;
		$this->notifications = $notifications;
	}

	public function register( Erdo_Draft_Links_Loader $loader ): void {
		// Pretty /review/<token>/ URLs.
		$loader->add_action( 'init', $this, 'add_rewrite_rules' );
		$loader->add_action( 'init', $this, 'maybe_flush_rewrite_rules', 99 );
		$loader->add_filter( 'query_vars', $this, 'add_query_var' );

		// Priority 1: intercept token URL before any other processing.
		$loader->add_action( 'template_redirect', $this, 'handle_token_request', 1 );

		// Priority 1: runs before WP_Query builds SQL; adds draft to allowed statuses.
		$loader->add_action( 'pre_get_posts', $this, 'allow_draft_for_cookie', 1, 1 );

		// Grants edit_post/read_post for the shared post only, so page builders (e.g.
		// Elementor) and block bindings render for visitors with a preview cookie.
		$loader->add_filter( 'user_has_cap', $this, 'grant_preview_edit_cap', 10, 3 );

		// Priority 5: after the token redirect (priority 1) has had a chance to run/exit.
		$loader->add_action( 'template_redirect', $this, 'handle_feedback_submission', 5 );

		// wp_footer fires on every front-end page regardless of how the page builder
		// rendered the content, so the form appears even with builders (e.g. Elementor
		// Canvas templates) that bypass the the_content filter entirely.
		$loader->add_action( 'wp_footer', $this, 'render_feedback_form_in_footer' );

		$loader->add_action( 'wp_enqueue_scripts', $this, 'enqueue_feedback_assets' );

		$loader->add_filter( 'wp_robots', $this, 'filter_robots' );

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
		$cookie_data = $this->session->current();
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
		$feedback_id = $this->db->add_feedback( $post_id, $cookie_data['link_id'], $name, $message );

		if ( ! $feedback_id ) {
			return new WP_Error( 'erdo_feedback_failed', __( 'An error occurred. Please try again.', 'erdo-draft-links' ), array( 'status' => 500 ) );
		}

		$this->notifications->feedback( $cookie_data['link'], $name, $message );

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
	// Pretty URLs: /review/<token>/ → same handler as ?erdo_token=.
	// -------------------------------------------------------------------------

	public function add_rewrite_rules(): void {
		add_rewrite_rule( '^' . preg_quote( Erdo_Draft_Links_Token::review_base(), '/' ) . '/([A-Za-z0-9]{32})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public function maybe_flush_rewrite_rules(): void {
		if ( get_option( Erdo_Draft_Links_Migrator::FLUSH_RULES_OPTION ) ) {
			delete_option( Erdo_Draft_Links_Migrator::FLUSH_RULES_OPTION );
			flush_rewrite_rules( false );
		}
	}

	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	// -------------------------------------------------------------------------
	// Step 1: Token URL → validate → set cookie → redirect to clean URL
	// -------------------------------------------------------------------------

	public function handle_token_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token itself is the credential.
		$raw_token = isset( $_GET['erdo_token'] ) ? sanitize_text_field( wp_unslash( $_GET['erdo_token'] ) ) : (string) get_query_var( self::QUERY_VAR );
		if ( '' === $raw_token ) {
			return;
		}

		// Keep the token URL (and whatever it answers) out of search indexes,
		// caches and the Referer header sent to any third-party asset.
		$this->send_private_headers();
		header( 'Referrer-Policy: no-referrer' );

		// Format guard: exactly 32 alphanumeric characters.
		if ( ! ctype_alnum( $raw_token ) || strlen( $raw_token ) !== 32 ) {
			$this->status_page->render( Erdo_Draft_Links_Status_Page::STATE_INVALID );
		}

		// Chat apps, mail scanners and browser prefetches open links on their
		// own. They get a neutral page: no view counted, no "first view" email,
		// no cookie, and nothing about the draft (not even its title).
		if ( $this->is_automated_request() ) {
			$this->render_neutral_page();
		}

		$link = $this->db->get_link_by_token_hash( $this->token->hash( $raw_token ) );

		if ( ! $link || ! $this->token->verify( $raw_token, $link->token_hash ) ) {
			$this->status_page->render( Erdo_Draft_Links_Status_Page::STATE_INVALID );
		}

		$self_url = $this->token->url_for_token( $raw_token, (int) $link->post_id );

		$dead_state = null;
		if ( ! $link->is_active ) {
			$dead_state = Erdo_Draft_Links_Status_Page::STATE_REVOKED;
		} elseif ( $this->token->is_expired( $link->expires_at ) ) {
			$dead_state = Erdo_Draft_Links_Status_Page::STATE_EXPIRED;
		} elseif ( null !== $link->max_views && (int) $link->view_count >= (int) $link->max_views ) {
			$dead_state = Erdo_Draft_Links_Status_Page::STATE_LIMIT;
		}

		if ( null !== $dead_state ) {
			if ( $this->is_post_request() && isset( $_POST['erdo_request_link'] ) ) {
				$this->handle_link_request( $link );
			}
			$this->status_page->render(
				$dead_state,
				array(
					'form_action' => $self_url,
					'link_id'     => (int) $link->id,
					'can_request' => true,
				)
			);
		}

		if ( '' !== (string) $link->password_hash ) {
			$this->require_password( $link, $self_url );
		}

		if ( 0 === (int) $link->view_count ) {
			$this->notifications->first_view( $link );
		}

		$this->session->start( $link );
		$this->db->record_view( (int) $link->id );

		wp_safe_redirect( get_permalink( (int) $link->post_id ) );
		exit;
	}

	/**
	 * Shows the password form until the right password is POSTed. Wrong
	 * attempts are rate limited per link and visitor IP.
	 */
	private function require_password( object $link, string $self_url ): void {
		$args = array(
			'form_action' => $self_url,
			'link_id'     => (int) $link->id,
		);

		if ( ! $this->is_post_request() || ! isset( $_POST['erdo_link_password'] ) ) {
			$this->status_page->render( Erdo_Draft_Links_Status_Page::STATE_PASSWORD, $args );
		}

		$nonce = isset( $_POST['erdo_unlock_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['erdo_unlock_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'erdo_draft_link_unlock_' . (int) $link->id ) ) {
			$args['error'] = __( 'The form expired. Please try again.', 'erdo-draft-links' );
			$this->status_page->render( Erdo_Draft_Links_Status_Page::STATE_PASSWORD, $args );
		}

		$attempts_key = 'erdo_dl_unlock_' . (int) $link->id . '_' . md5( $this->client_ip() );
		$attempts     = (int) get_transient( $attempts_key );
		if ( $attempts >= self::UNLOCK_MAX_ATTEMPTS ) {
			$args['status'] = 429;
			$args['error']  = __( 'Too many attempts. Please wait a few minutes and try again.', 'erdo-draft-links' );
			$this->status_page->render( Erdo_Draft_Links_Status_Page::STATE_PASSWORD, $args );
		}

		// Passwords are compared as typed; sanitizing could alter them.
		$password = (string) wp_unslash( $_POST['erdo_link_password'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! wp_check_password( $password, (string) $link->password_hash ) ) {
			set_transient( $attempts_key, $attempts + 1, self::UNLOCK_WINDOW );
			$args['status'] = 401;
			$args['error']  = __( 'That password is not correct.', 'erdo-draft-links' );
			$this->status_page->render( Erdo_Draft_Links_Status_Page::STATE_PASSWORD, $args );
		}

		delete_transient( $attempts_key );
	}

	/**
	 * "Ask for a new link" on a dead link's page — emails the link's owners,
	 * at most once per link per hour.
	 */
	private function handle_link_request( object $link ): void {
		$nonce = isset( $_POST['erdo_request_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['erdo_request_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'erdo_draft_link_request_' . (int) $link->id ) ) {
			return;
		}

		$throttle_key = 'erdo_dl_requested_' . (int) $link->id;
		if ( ! get_transient( $throttle_key ) ) {
			set_transient( $throttle_key, 1, HOUR_IN_SECONDS );
			$this->notifications->link_requested( $link );
		}

		// Same answer whether or not an email went out, so the page reveals nothing.
		$this->status_page->render( Erdo_Draft_Links_Status_Page::STATE_REQUEST_SENT );
	}

	private function is_post_request(): bool {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}

	private function client_ip(): string {
		// REMOTE_ADDR only — forwarded headers are client-controlled.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Link unfurlers (Slack, WhatsApp, Teams, iMessage…), mail link scanners
	 * (e.g. Outlook Safe Links) and speculative prefetches.
	 */
	private function is_automated_request(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'HEAD' === $method ) {
			return true;
		}

		foreach ( array( 'HTTP_PURPOSE', 'HTTP_SEC_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ' ) as $header ) {
			if ( isset( $_SERVER[ $header ] ) && false !== stripos( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), 'prefetch' ) ) {
				return true;
			}
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$is_bot     = '' === $user_agent || (bool) preg_match(
			'/Slackbot|Slack-ImgProxy|facebookexternalhit|Facebot|WhatsApp|Twitterbot|LinkedInBot|TelegramBot|Discordbot|SkypeUriPreview|MicrosoftPreview|Microsoft Office|Teams|BingPreview|Google-PageRenderer|Googlebot|bingbot|Applebot|redditbot|Pinterest|Embedly|Iframely|vkShare|Viber|Snapchat|Mattermost|Rocket\.Chat|GoogleImageProxy|python-requests|curl\/|Wget/i',
			$user_agent
		);

		/**
		 * Filters whether a draft link request comes from an automated client
		 * (link preview, mail scanner, prefetch) rather than a person.
		 *
		 * @param bool   $is_bot     Detection result.
		 * @param string $user_agent The request's User-Agent header.
		 */
		return (bool) apply_filters( 'erdo_draft_links_is_automated_request', $is_bot, $user_agent );
	}

	private function render_neutral_page(): void {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		$site = get_bloginfo( 'name' );
		printf(
			'<!DOCTYPE html><html><head><meta charset="%1$s"><meta name="robots" content="noindex, nofollow"><title>%2$s</title><meta property="og:title" content="%2$s"><meta property="og:description" content="%3$s"></head><body><p>%3$s</p></body></html>',
			esc_attr( get_option( 'blog_charset' ) ),
			esc_html( $site ),
			esc_html__( 'Private draft preview. Open this link in your browser to view it.', 'erdo-draft-links' )
		);
		exit;
	}

	private function send_private_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet' );
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

		$cookie_data = $this->session->current();
		if ( null === $cookie_data ) {
			return;
		}

		$active_post_id = $cookie_data['post_id'];

		// Security: only expand status for the specific post ID this cookie grants access to.
		$queried_p       = absint( $query->get( 'p' ) );
		$queried_page_id = absint( $query->get( 'page_id' ) );

		if ( $queried_p !== $active_post_id && $queried_page_id !== $active_post_id ) {
			// Try slug-based matching only if the post is known and has a real slug.
			// Draft posts commonly have an empty post_name until first published, and
			// 'name'/'pagename' are also empty on ID-based queries — comparing empty
			// strings would otherwise false-match an unrelated post and leak it.
			$post = get_post( $active_post_id );
			if ( ! $post || '' === $post->post_name ) {
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
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- reserved cache-buster constant name, recognized by caching plugins/CDNs; cannot be prefixed.
			define( 'DONOTCACHEPAGE', true );
		}
		$this->send_private_headers();
	}

	public function filter_robots( array $robots ): array {
		if ( null === $this->active_post_id ) {
			return $robots;
		}
		// Same directives as the X-Robots-Tag header.
		unset( $robots['follow'], $robots['max-image-preview'] );
		return array_merge(
			$robots,
			array(
				'noindex'   => true,
				'nofollow'  => true,
				'noarchive' => true,
				'nosnippet' => true,
			)
		);
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

		if ( ! in_array( $requested_cap, array( 'edit_post', 'read_post' ), true ) || $object_id !== $this->active_post_id ) {
			return $allcaps;
		}

		foreach ( $caps as $cap ) {
			$allcaps[ $cap ] = true;
		}

		return $allcaps;
	}

	// -------------------------------------------------------------------------
	// Preview feedback — lets the visitor leave a name + message on the
	// shared draft, stored in the plugin's own table and emailed to the site admin.
	// Each visitor only sees feedback left through their own link.
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

					<?php
					$history = $this->db->get_feedback_by_link( $link_id );
					$replies = $this->db->get_replies( wp_list_pluck( $history, 'id' ) );
					?>
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
										<?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $item->created_at . ' UTC' ) ) ); ?>
									</span>
									<?php foreach ( $replies[ (int) $item->id ] ?? array() as $reply ) : ?>
										<div class="erdo-draft-feedback-reply">
											<span class="erdo-draft-feedback-reply-label"><?php echo esc_html( $reply->author_name ); ?></span>
											<p class="erdo-draft-feedback-reply-message"><?php echo esc_html( $reply->message ); ?></p>
										</div>
									<?php endforeach; ?>
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

		$cookie_data = $this->session->current();
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
		$feedback_id = $this->db->add_feedback( $post_id, $cookie_data['link_id'], $name, $message );

		if ( $feedback_id ) {
			$this->notifications->feedback( $cookie_data['link'], $name, $message );
		}

		wp_safe_redirect( add_query_arg( 'erdo_feedback', 'sent', get_permalink( $post_id ) ) );
		exit;
	}
}
