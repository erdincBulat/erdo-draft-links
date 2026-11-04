<?php
defined( 'ABSPATH' ) || exit;

/**
 * Optional AI helpers for editors, run on the site owner's own AI provider
 * through the WordPress AI Client (WP 7.0+, keys entered under Settings →
 * Connectors). The plugin never stores or sees an API key and nothing runs
 * on its own: every request is started by an editor clicking a button.
 *
 * What is sent: comment text, the post title and (for "what changed") the
 * post text. Reviewer names are replaced with "Reviewer"; emails and IP
 * addresses are never sent.
 */
class Erdo_Draft_Links_AI {

	const NAMESPACE        = 'erdo-draft-links/v1';
	const STATUS_TRANSIENT = 'erdo_draft_link_ai_status';
	const CACHE_TTL        = 15 * MINUTE_IN_SECONDS;
	const MAX_TEXT         = 12000;

	const PREFERRED_MODELS = array(
		'claude-haiku-5-5',
		'claude-haiku-4-5',
		'gpt-5-mini',
		'gpt-4.1-mini',
		'gemini-flash-lite-latest',
		'gemini-flash-latest',
	);

	/** Post meta holding the "what changed" note shown to reviewers. */
	const NOTE_META = '_erdo_dl_changes_note';

	private Erdo_Draft_Links_DB $db;
	private Erdo_Draft_Links_Token $token;

	public function __construct( Erdo_Draft_Links_DB $db, Erdo_Draft_Links_Token $token ) {
		$this->db    = $db;
		$this->token = $token;
	}

	public function register( Erdo_Draft_Links_Loader $loader ): void {
		$loader->add_action( 'rest_api_init', $this, 'register_rest_routes' );
		// A connector added or removed changes what's available.
		$loader->add_action( 'update_option', $this, 'maybe_reset_status', 10, 1 );
		$loader->add_action( 'add_option', $this, 'maybe_reset_status', 10, 1 );
		$loader->add_action( 'delete_option', $this, 'maybe_reset_status', 10, 1 );
	}

	// -------------------------------------------------------------------------
	// Availability
	// -------------------------------------------------------------------------

	/**
	 * Whether the helpers are switched on (setting + filter), regardless of
	 * whether an AI provider is connected.
	 */
	public static function enabled(): bool {
		/**
		 * Filters whether the AI helpers are offered at all.
		 *
		 * @param bool $enabled The "AI helpers" setting.
		 */
		return (bool) apply_filters( 'erdo_draft_links_ai_enabled', (bool) Erdo_Draft_Links_Settings::get( 'ai_enabled' ) );
	}

	/**
	 * @return array{enabled: bool, available: bool, connectors_url: string}
	 */
	public function status(): array {
		$enabled   = self::enabled() && function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' );
		$available = false;

		if ( $enabled && wp_supports_ai() ) {
			$cached = get_transient( self::STATUS_TRANSIENT );
			if ( false === $cached ) {
				$cached = wp_ai_client_prompt( 'ping' )->is_supported_for_text_generation() ? 'yes' : 'no';
				set_transient( self::STATUS_TRANSIENT, $cached, HOUR_IN_SECONDS );
			}
			$available = 'yes' === $cached;
		}

		return array(
			'enabled'        => $enabled,
			'available'      => $available,
			'connectors_url' => current_user_can( 'manage_options' ) ? admin_url( 'options-connectors.php' ) : '',
		);
	}

	/**
	 * @param string $option Option being updated.
	 */
	public function maybe_reset_status( $option ): void {
		if ( is_string( $option ) && ( str_starts_with( $option, 'connectors_' ) || Erdo_Draft_Links_Settings::OPTION === $option ) ) {
			delete_transient( self::STATUS_TRANSIENT );
		}
	}

	// -------------------------------------------------------------------------
	// REST
	// -------------------------------------------------------------------------

	public function register_rest_routes(): void {
		$post_id = array(
			'type'              => 'integer',
			'required'          => true,
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
		);
		$item_id = array(
			'type'              => 'integer',
			'required'          => true,
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
		);

		register_rest_route(
			self::NAMESPACE,
			'/ai',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn() => rest_ensure_response( $this->status() ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			)
		);

		$routes = array(
			'summarize'     => array( 'post_id' => $post_id ),
			'reply'         => array(
				'post_id' => $post_id,
				'item_id' => $item_id,
			),
			'translate'     => array(
				'post_id' => $post_id,
				'item_id' => $item_id,
			),
			'share-message' => array(
				'post_id' => $post_id,
				'link_id' => $item_id,
			),
			'changes'       => array( 'post_id' => $post_id ),
		);
		foreach ( $routes as $route => $args ) {
			register_rest_route(
				self::NAMESPACE,
				'/posts/(?P<post_id>\d+)/ai/' . $route,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_' . str_replace( '-', '_', $route ) ),
					'permission_callback' => array( $this, 'can_use' ),
					'args'                => $args,
				)
			);
		}

		// Saving the note is not an AI call; it works without a provider too.
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<post_id>\d+)/changes-note',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => rest_ensure_response( self::get_note( (int) $r['post_id'] ) ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array( 'post_id' => $post_id ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_save_note' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'post_id' => $post_id,
						'text'    => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
			)
		);
	}

	public function can_edit( WP_REST_Request $request ): bool {
		return current_user_can( 'edit_post', (int) $request['post_id'] );
	}

	public function can_use( WP_REST_Request $request ): bool|WP_Error {
		if ( ! $this->can_edit( $request ) ) {
			return false;
		}
		if ( ! self::enabled() ) {
			return new WP_Error( 'erdo_dl_ai_disabled', __( 'The AI helpers are switched off on Draft Links → Settings.', 'erdo-draft-links' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Groups the open feedback on a post into a de-duplicated to-do list.
	 */
	public function rest_summarize( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$items   = array_values(
			array_filter(
				$this->db->get_feedback_for_post( $post_id ),
				fn( $i ) => Erdo_Draft_Links_DB::FEEDBACK_STATUS_OPEN === $i->status && Erdo_Draft_Links_DB::FEEDBACK_TYPE_APPROVAL !== $i->type
			)
		);
		if ( ! $items ) {
			return new WP_Error( 'erdo_dl_ai_nothing', __( 'There is no open feedback to summarise.', 'erdo-draft-links' ), array( 'status' => 400 ) );
		}

		$replies = $this->db->get_replies( wp_list_pluck( $items, 'id' ) );
		$lines   = array();
		foreach ( $items as $item ) {
			$line = sprintf( '[#%d] (%s) Reviewer: %s', (int) $item->id, $item->type, $this->clip( (string) $item->message, 1500 ) );
			if ( $item->selector ) {
				$line .= ' [pinned to page element: ' . $this->clip( (string) $item->selector, 200 ) . ']';
			}
			foreach ( $replies[ (int) $item->id ] ?? array() as $reply ) {
				$line .= "\n    " . ( (int) $reply->user_id > 0 ? 'Editor' : 'Reviewer' ) . ': ' . $this->clip( (string) $reply->message, 600 );
			}
			$lines[] = $line;
		}

		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'tasks' ),
			'properties'           => array(
				'tasks' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'task', 'item_ids' ),
						'properties'           => array(
							'task'     => array( 'type' => 'string' ),
							'item_ids' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
						),
					),
				),
			),
		);

		$result = $this->generate(
			'summarize',
			sprintf(
				"You turn client feedback on a web page draft into a short to-do list for the person editing it.\n- Merge items that ask for the same change.\n- Each task is one concrete, imperative sentence (\"Make the hero image smaller\").\n- Skip feedback that asks for nothing (praise, thanks).\n- Keep the order of importance the feedback suggests; otherwise page order.\n- item_ids lists the [#id] numbers each task comes from.\n- Write the tasks in the language with locale code %s.",
				$this->editor_locale()
			),
			sprintf( "Page title: %s\n\nOpen feedback:\n%s", get_the_title( $post_id ), implode( "\n", $lines ) ),
			$schema
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$known = array_map( 'intval', wp_list_pluck( $items, 'id' ) );
		$tasks = array();
		foreach ( (array) ( $result['tasks'] ?? array() ) as $task ) {
			$text = trim( (string) ( $task['task'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$tasks[] = array(
				'task'     => $text,
				'item_ids' => array_values( array_intersect( array_map( 'intval', (array) ( $task['item_ids'] ?? array() ) ), $known ) ),
			);
		}

		return rest_ensure_response( array( 'tasks' => $tasks ) );
	}

	/**
	 * A suggested reply the editor can change before sending.
	 */
	public function rest_reply( WP_REST_Request $request ) {
		$item = $this->get_item( $request );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$thread  = 'Reviewer: ' . $this->clip( (string) $item->message, 3000 );
		$replies = $this->db->get_replies( array( (int) $item->id ) );
		foreach ( $replies[ (int) $item->id ] ?? array() as $reply ) {
			$thread .= "\n" . ( (int) $reply->user_id > 0 ? 'Editor' : 'Reviewer' ) . ': ' . $this->clip( (string) $reply->message, 1500 );
		}

		$text = $this->generate(
			'reply',
			"You draft the editor's next reply to a client who is reviewing a web page draft.\n- Friendly, brief and professional: 1–3 sentences.\n- Acknowledge the point and say it will be looked at. Never claim a change is already done, and don't promise dates.\n- Reply in the same language the reviewer wrote in.\n- Return only the reply text, with no greeting line, signature or quotes.",
			sprintf( "Page title: %s\n\nConversation:\n%s", get_the_title( (int) $item->post_id ), $thread )
		);

		return is_wp_error( $text ) ? $text : rest_ensure_response( array( 'text' => $text ) );
	}

	/**
	 * Translates a reviewer's comment into the editor's language.
	 */
	public function rest_translate( WP_REST_Request $request ) {
		$item = $this->get_item( $request );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$text = $this->generate(
			'translate',
			sprintf(
				'Translate the user message into the language with locale code %s. Keep the meaning and tone; keep names, URLs and code unchanged. If it is already in that language, return it unchanged. Return only the translation.',
				$this->editor_locale()
			),
			$this->clip( (string) $item->message, 3000 )
		);

		return is_wp_error( $text ) ? $text : rest_ensure_response( array( 'text' => $text ) );
	}

	/**
	 * A short message to send along with a share link.
	 */
	public function rest_share_message( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$link    = $this->db->get_link_by_id( (int) $request['link_id'] );
		if ( ! $link || (int) $link->post_id !== $post_id || ! $link->is_active ) {
			return new WP_Error( 'erdo_dl_ai_no_link', __( 'This link does not belong to that post.', 'erdo-draft-links' ), array( 'status' => 404 ) );
		}

		$facts = array(
			'Page title: ' . get_the_title( $post_id ),
			'Website: ' . wp_strip_all_tags( get_bloginfo( 'name' ) ),
			'Expires: ' . ( $link->expires_at
				? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $link->expires_at . ' UTC' ) )
				: 'never' ),
			'Password protected: ' . ( '' !== (string) $link->password_hash ? 'yes (the password is sent separately)' : 'no' ),
			'Is the page already published: ' . ( 'publish' === get_post_status( $post_id ) ? 'yes' : 'no, it is a draft' ),
		);

		$text = $this->generate(
			'share-message',
			sprintf(
				"You write a short message an editor sends to a client together with a private review link to a web page.\n- 3–5 short sentences, friendly and plain, suitable for email or a chat app.\n- Explain that no login is needed, that they can comment, pin notes to parts of the page, and approve or request changes from the bar at the bottom of the page.\n- Mention when the link expires, and the password note if there is one.\n- Put the literal placeholder {link} on its own line where the link goes. Do not invent a name, greeting name or signature.\n- Write in the language with locale code %s.",
				get_locale()
			),
			implode( "\n", $facts )
		);
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$url  = $this->token->link_url( $link );
		$text = str_contains( $text, '{link}' ) ? str_replace( '{link}', $url, $text ) : $text . "\n\n" . $url;

		return rest_ensure_response( array( 'text' => $text ) );
	}

	/**
	 * Summarises what changed since the version the reviewer last saw.
	 */
	public function rest_changes( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$post    = get_post( $post_id );
		$base    = $post ? $this->reviewed_version( $post ) : null;
		if ( ! $base ) {
			return new WP_Error( 'erdo_dl_ai_no_base', __( 'There is no saved version from the last review to compare with. Revisions may be switched off for this post type.', 'erdo-draft-links' ), array( 'status' => 400 ) );
		}

		$before = $this->plain( $base->post_title, $base->post_content );
		$after  = $this->plain( $post->post_title, $post->post_content );
		if ( $before === $after ) {
			return new WP_Error( 'erdo_dl_ai_unchanged', __( 'The text hasn’t changed since the last review.', 'erdo-draft-links' ), array( 'status' => 400 ) );
		}

		$text = $this->generate(
			'changes',
			sprintf(
				"You tell a client, who already reviewed a web page draft, what is different in the new version.\n- 2–5 short bullet points starting with \"- \", most noticeable change first.\n- Plain client language; no HTML, block or code terms.\n- Only describe differences between BEFORE and AFTER; ignore whitespace and formatting-only changes.\n- Write in the language with locale code %s. Return only the bullets.",
				get_locale()
			),
			"BEFORE:\n" . $before . "\n\nAFTER:\n" . $after
		);

		return is_wp_error( $text ) ? $text : rest_ensure_response( array( 'text' => $text ) );
	}

	public function rest_save_note( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['post_id'];
		$text    = trim( mb_substr( (string) $request['text'], 0, 2000 ) );

		if ( '' === $text ) {
			delete_post_meta( $post_id, self::NOTE_META );
		} else {
			update_post_meta(
				$post_id,
				self::NOTE_META,
				array(
					'text' => $text,
					'at'   => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}

		return rest_ensure_response( self::get_note( $post_id ) );
	}

	/**
	 * @return array{text: string, at: ?string}
	 */
	public static function get_note( int $post_id ): array {
		$note = get_post_meta( $post_id, self::NOTE_META, true );
		return array(
			'text' => is_array( $note ) ? (string) ( $note['text'] ?? '' ) : '',
			'at'   => is_array( $note ) && ! empty( $note['at'] ) ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $note['at'] . ' UTC' ) ) : null,
		);
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Runs one prompt. With a schema the decoded JSON array is returned,
	 * otherwise the trimmed text. Identical requests within 15 minutes reuse
	 * the earlier answer so a double click isn't billed twice.
	 *
	 * @return string|array|WP_Error
	 */
	private function generate( string $feature, string $system, string $prompt, ?array $schema = null ) {
		$status = $this->status();
		if ( ! $status['available'] ) {
			return new WP_Error( 'erdo_dl_ai_unavailable', __( 'No AI provider is connected. Add your own API key under Settings → Connectors.', 'erdo-draft-links' ), array( 'status' => 400 ) );
		}

		$cache_key = 'erdo_dl_ai_' . md5( $feature . "\0" . $system . "\0" . $prompt );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			->using_temperature( 0.3 )
			->using_max_tokens( 1200 );

		/**
		 * Filters the models tried first, in order. These are short, simple
		 * tasks, so small fast models keep the site owner's bill low. When none
		 * of them is available, the AI Client picks one of the connected
		 * provider's models itself.
		 *
		 * @param string[] $models  Model identifiers.
		 * @param string   $feature summarize|reply|translate|share-message|changes.
		 */
		$models = array_values( array_filter( (array) apply_filters( 'erdo_draft_links_ai_models', self::PREFERRED_MODELS, $feature ), 'is_string' ) );
		if ( $models ) {
			$builder = $builder->using_model_preference( ...$models );
		}
		if ( $schema ) {
			$builder = $builder->as_json_response( $schema );
		}

		$text = $builder->generate_text();
		if ( is_wp_error( $text ) ) {
			return new WP_Error(
				'erdo_dl_ai_failed',
				/* translators: %s: error message from the AI provider */
				sprintf( __( 'The AI request failed: %s', 'erdo-draft-links' ), $text->get_error_message() ),
				array( 'status' => 502 )
			);
		}

		$result = trim( (string) $text );
		if ( $schema ) {
			$decoded = json_decode( $this->strip_code_fence( $result ), true );
			if ( ! is_array( $decoded ) ) {
				return new WP_Error( 'erdo_dl_ai_bad_json', __( 'The AI answer could not be read. Please try again.', 'erdo-draft-links' ), array( 'status' => 502 ) );
			}
			$result = $decoded;
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * @return object|WP_Error A top-level feedback row that belongs to the post in the URL.
	 */
	private function get_item( WP_REST_Request $request ) {
		$item = $this->db->get_feedback_by_id( (int) $request['item_id'] );
		if ( ! $item || (int) $item->post_id !== (int) $request['post_id'] || (int) $item->parent_id > 0 || '' === trim( (string) $item->message ) ) {
			return new WP_Error( 'erdo_dl_ai_no_item', __( 'That feedback item was not found on this post.', 'erdo-draft-links' ), array( 'status' => 404 ) );
		}
		return $item;
	}

	/**
	 * The revision the reviewer saw: the newest one saved at or before the
	 * last review decision, or before the oldest working link if nobody has
	 * decided yet.
	 */
	private function reviewed_version( WP_Post $post ): ?WP_Post {
		$review = get_post_meta( $post->ID, Erdo_Draft_Links_Review::META_KEY, true );
		$since  = is_array( $review ) && ! empty( $review['modified'] ) ? (string) $review['modified'] : '';

		if ( '' === $since ) {
			$links = $this->db->get_active_links_by_post( $post->ID );
			if ( ! $links ) {
				return null;
			}
			$since = (string) min( wp_list_pluck( $links, 'created_at' ) );
		}

		foreach ( wp_get_post_revisions( $post->ID, array( 'order' => 'DESC' ) ) as $revision ) {
			if ( $revision->post_modified_gmt <= $since ) {
				return $revision;
			}
		}
		return null;
	}

	private function plain( string $title, string $content ): string {
		// Block markup lives in HTML comments, which strip_tags() drops too.
		$text = wp_strip_all_tags( strip_shortcodes( $content ) );
		$text = preg_replace( "/[ \t]+/", ' ', (string) $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );
		return $this->clip( 'Title: ' . $title . "\n" . trim( (string) $text ), self::MAX_TEXT );
	}

	private function clip( string $text, int $max ): string {
		return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) . '…' : $text;
	}

	private function strip_code_fence( string $text ): string {
		return (string) preg_replace( '/^```(?:json)?\s*|\s*```$/', '', $text );
	}

	private function editor_locale(): string {
		return get_user_locale();
	}
}
