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
	private ?object $active_link = null;

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

		// The review bar is built by JS in a Shadow DOM and mounted on <html>, so
		// it shows up however the theme or page builder rendered the post. The
		// footer only carries a no-JS fallback form; wp_footer fires even with
		// builder templates (e.g. Elementor Canvas) that skip the_content.
		$loader->add_action( 'wp_enqueue_scripts', $this, 'enqueue_review_bar' );
		$loader->add_action( 'wp_footer', $this, 'render_noscript_form' );

		$loader->add_filter( 'wp_robots', $this, 'filter_robots' );

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
		$this->active_link    = $cookie_data['link'];

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
	// Review bar — the visitor's comments, notes on the page and approve /
	// request-changes buttons (assets/js/review-bar.js). Each visitor only
	// sees the conversation on their own link.
	// -------------------------------------------------------------------------

	private function is_previewing(): bool {
		return null !== $this->active_post_id && is_singular() && get_queried_object_id() === $this->active_post_id;
	}

	public function enqueue_review_bar(): void {
		if ( ! $this->is_previewing() ) {
			return;
		}

		wp_enqueue_script(
			'erdo-draft-links-review-bar',
			plugins_url( 'assets/js/review-bar.js', ERDO_DRAFT_LINKS_PLUGIN_FILE ),
			array(),
			ERDO_DRAFT_LINKS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$link = $this->active_link;

		wp_localize_script(
			'erdo-draft-links-review-bar',
			'erdoDraftReview',
			array(
				'restUrl'     => rest_url( 'erdo-draft-links/v1/' ),
				'nonce'       => wp_create_nonce( 'erdo_draft_feedback_' . (int) $this->active_link_id ),
				// Logged-in viewers need the REST nonce too: without it the REST API
				// runs as user 0 and the link nonce above (made for them) won't verify.
				'wpNonce'     => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'cssUrl'      => add_query_arg( 'ver', ERDO_DRAFT_LINKS_VERSION, plugins_url( 'assets/css/review-bar.css', ERDO_DRAFT_LINKS_PLUGIN_FILE ) ),
				'site'        => wp_strip_all_tags( get_bloginfo( 'name' ) ),
				'isPublished' => 'publish' === get_post_status( $this->active_post_id ),
				'expiresAt'   => $link && $link->expires_at ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $link->expires_at . ' UTC' ) ) : null,
				'locale'      => str_replace( '_', '-', determine_locale() ),
				'i18n'        => $this->review_bar_strings(),
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function review_bar_strings(): array {
		return array(
			'draftPreview'       => __( 'Draft preview', 'erdo-draft-links' ),
			'preview'            => __( 'Preview', 'erdo-draft-links' ),
			/* translators: %s: relative time, e.g. "in 3 days" */
			'expires'            => __( 'Link expires %s', 'erdo-draft-links' ),
			'reviewBar'          => __( 'Review toolbar', 'erdo-draft-links' ),
			'comments'           => __( 'Comments', 'erdo-draft-links' ),
			'addNote'            => __( 'Note on page', 'erdo-draft-links' ),
			'approve'            => __( 'Approve', 'erdo-draft-links' ),
			'requestChanges'     => __( 'Request changes', 'erdo-draft-links' ),
			'collapse'           => __( 'Hide toolbar', 'erdo-draft-links' ),
			'review'             => __( 'Review', 'erdo-draft-links' ),
			'close'              => __( 'Close', 'erdo-draft-links' ),
			'cancel'             => __( 'Cancel', 'erdo-draft-links' ),
			'send'               => __( 'Send', 'erdo-draft-links' ),
			'sending'            => __( 'Sending…', 'erdo-draft-links' ),
			'reply'              => __( 'Reply', 'erdo-draft-links' ),
			'sendReply'          => __( 'Send reply', 'erdo-draft-links' ),
			'yourName'           => __( 'Your name', 'erdo-draft-links' ),
			'yourEmail'          => __( 'Email (optional)', 'erdo-draft-links' ),
			'emailHelp'          => __( 'Only used to let you know when someone replies.', 'erdo-draft-links' ),
			'changeName'         => __( 'Change', 'erdo-draft-links' ),
			/* translators: %s: reviewer name */
			'commentingAs'       => __( 'Commenting as %s', 'erdo-draft-links' ),
			'commentLabel'       => __( 'Your comment', 'erdo-draft-links' ),
			'commentPlaceholder' => __( 'What do you think?', 'erdo-draft-links' ),
			'notePlaceholder'    => __( 'What should change here?', 'erdo-draft-links' ),
			'noteHint'           => __( 'Click the part of the page you want to comment on. Press Esc to cancel.', 'erdo-draft-links' ),
			/* translators: %d: note number */
			'noteN'              => __( 'Note %d', 'erdo-draft-links' ),
			'showOnPage'         => __( 'Show on page', 'erdo-draft-links' ),
			'notFound'           => __( 'This part of the page has changed since the note was left.', 'erdo-draft-links' ),
			'empty'              => __( 'No comments yet. Write one below, or use “Note on page” to point at something specific.', 'erdo-draft-links' ),
			'resolved'           => __( 'Resolved', 'erdo-draft-links' ),
			'team'               => __( 'Team', 'erdo-draft-links' ),
			'approvedItem'       => __( 'Approved', 'erdo-draft-links' ),
			'changesItem'        => __( 'Requested changes', 'erdo-draft-links' ),
			'approveTitle'       => __( 'Approve this draft?', 'erdo-draft-links' ),
			'approveHelp'        => __( 'The author will be told it is good to go.', 'erdo-draft-links' ),
			'approveMessage'     => __( 'Message (optional)', 'erdo-draft-links' ),
			'changesTitle'       => __( 'Request changes', 'erdo-draft-links' ),
			'changesHelp'        => __( 'Tell the author what should change. Your comments and notes stay attached.', 'erdo-draft-links' ),
			'changesMessage'     => __( 'What should change?', 'erdo-draft-links' ),
			/* translators: %s: reviewer name */
			'approvedBy'         => __( 'Approved by %s', 'erdo-draft-links' ),
			/* translators: %s: reviewer name */
			'changesBy'          => __( 'Changes requested by %s', 'erdo-draft-links' ),
			'editedSince'        => __( 'Updated since the last review', 'erdo-draft-links' ),
			'thanksApproved'     => __( 'Thanks! The author has been told you approved it.', 'erdo-draft-links' ),
			'thanksChanges'      => __( 'Thanks! The author has your change requests.', 'erdo-draft-links' ),
			'thanksComment'      => __( 'Comment sent.', 'erdo-draft-links' ),
			'thanksNote'         => __( 'Note added.', 'erdo-draft-links' ),
			'error'              => __( 'An error occurred. Please try again.', 'erdo-draft-links' ),
			'loadError'          => __( 'Could not load the conversation.', 'erdo-draft-links' ),
		);
	}

	/**
	 * Plain form for visitors without JavaScript (the review bar needs it).
	 */
	public function render_noscript_form(): void {
		if ( ! $this->is_previewing() ) {
			return;
		}

		$sent = isset( $_GET['erdo_feedback'] ) && 'sent' === $_GET['erdo_feedback']; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<noscript>
			<form method="post" class="erdo-draft-feedback-noscript" style="max-width:40rem;margin:2rem auto;padding:1rem;border:1px solid #dcdcde;font:16px/1.5 system-ui,sans-serif;">
				<?php if ( $sent ) : ?>
					<p role="status"><?php esc_html_e( 'Thanks! Your feedback has been sent.', 'erdo-draft-links' ); ?></p>
				<?php endif; ?>
				<?php wp_nonce_field( 'erdo_draft_feedback_' . (int) $this->active_link_id, 'erdo_feedback_nonce' ); ?>
				<p>
					<label for="erdo-feedback-name"><?php esc_html_e( 'Your name', 'erdo-draft-links' ); ?></label><br />
					<input type="text" id="erdo-feedback-name" name="erdo_feedback_name" maxlength="100" required="required" />
				</p>
				<p>
					<label for="erdo-feedback-message"><?php esc_html_e( 'Your comment', 'erdo-draft-links' ); ?></label><br />
					<textarea id="erdo-feedback-message" name="erdo_feedback_message" rows="4" cols="50" maxlength="5000" required="required"></textarea>
				</p>
				<p><button type="submit" name="erdo_draft_feedback_submit" value="1"><?php esc_html_e( 'Send', 'erdo-draft-links' ); ?></button></p>
			</form>
		</noscript>
		<?php
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
