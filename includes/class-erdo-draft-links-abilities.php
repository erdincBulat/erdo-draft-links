<?php
defined( 'ABSPATH' ) || exit;

/**
 * Exposes draft links to AI agents, MCP adapters and other clients through
 * the WordPress Abilities API (WP 6.9+). Each ability runs the matching
 * internal REST route with rest_do_request(), so validation, permission
 * checks (edit_post on that post) and output stay identical to what the
 * editor uses. Nothing is registered on older WordPress versions.
 */
class Erdo_Draft_Links_Abilities {

	const CATEGORY = 'erdo-draft-links';

	public function register( Erdo_Draft_Links_Loader $loader ): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$loader->add_action( 'wp_abilities_api_categories_init', $this, 'register_category' );
		$loader->add_action( 'wp_abilities_api_init', $this, 'register_abilities' );
	}

	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Draft review links', 'erdo-draft-links' ),
				'description' => __( 'Share unpublished posts with clients through private review links, and read their feedback and approvals.', 'erdo-draft-links' ),
			)
		);
	}

	public function register_abilities(): void {
		$post_id = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'ID of the post or page.', 'erdo-draft-links' ),
		);

		$this->add(
			'create-link',
			__( 'Create a review link', 'erdo-draft-links' ),
			__( 'Creates a private link that lets someone without a WordPress account view this post (even a draft) and leave feedback. Returns the shareable URL.', 'erdo-draft-links' ),
			array(
				'type'                 => 'object',
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
				'properties'           => array(
					'post_id'   => $post_id,
					'label'     => array(
						'type'        => 'string',
						'maxLength'   => 100,
						'description' => __( 'Who the link is for, e.g. "Client A".', 'erdo-draft-links' ),
					),
					'expiry'    => array(
						'type'        => 'string',
						'enum'        => array( '24h', '48h', '7d', '30d', 'never' ),
						'description' => __( 'How long the link works. Defaults to the site setting.', 'erdo-draft-links' ),
					),
					'max_views' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Stop working after this many opens (0 = no limit).', 'erdo-draft-links' ),
					),
				),
			),
			fn( array $input ) => $this->dispatch(
				'POST',
				'/link/' . (int) $input['post_id'] . '/generate',
				array(
					'label'     => (string) ( $input['label'] ?? '' ),
					'expiry'    => (string) ( $input['expiry'] ?? Erdo_Draft_Links_Settings::get( 'default_expiry' ) ),
					'max_views' => (int) ( $input['max_views'] ?? 0 ),
				)
			),
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			)
		);

		$this->add(
			'list-links',
			__( 'List review links', 'erdo-draft-links' ),
			__( 'Lists the review links of a post that still work, with their URL, expiry, open count and review decision, plus the post’s overall review status and number of open comments.', 'erdo-draft-links' ),
			$this->post_only_schema( $post_id ),
			fn( array $input ) => $this->dispatch( 'GET', '/link/' . (int) $input['post_id'] ),
			array( 'readonly' => true )
		);

		$this->add(
			'revoke-link',
			__( 'Turn off a review link', 'erdo-draft-links' ),
			__( 'Turns off one review link of a post immediately. Whoever has it can no longer open the post. This cannot be undone; create a new link instead.', 'erdo-draft-links' ),
			array(
				'type'                 => 'object',
				'required'             => array( 'post_id', 'link_id' ),
				'additionalProperties' => false,
				'properties'           => array(
					'post_id' => $post_id,
					'link_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'ID of the link, from list-links.', 'erdo-draft-links' ),
					),
				),
			),
			fn( array $input ) => $this->dispatch( 'POST', '/link/' . (int) $input['post_id'] . '/revoke', array( 'link_id' => (int) $input['link_id'] ) ),
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			)
		);

		$this->add(
			'list-feedback',
			__( 'List review feedback', 'erdo-draft-links' ),
			__( 'Lists what reviewers left on a post through its review links: comments, notes pinned to page elements, approvals and change requests, each with replies and an open/resolved status. Reviewer email addresses are not included.', 'erdo-draft-links' ),
			$this->post_only_schema( $post_id ),
			fn( array $input ) => $this->without_emails( $this->dispatch( 'GET', '/posts/' . (int) $input['post_id'] . '/feedback' ) ),
			array( 'readonly' => true )
		);

		$this->add(
			'get-review-status',
			__( 'Get review status', 'erdo-draft-links' ),
			__( 'Returns the latest review decision for a post (approved or changes requested), who made it and when, and whether the post was edited after that decision.', 'erdo-draft-links' ),
			$this->post_only_schema( $post_id ),
			function ( array $input ) {
				$result = $this->dispatch( 'GET', '/link/' . (int) $input['post_id'] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return array(
					'review'     => $result['review'] ?? null,
					'open_count' => (int) ( $result['open_count'] ?? 0 ),
				);
			},
			array( 'readonly' => true )
		);
	}

	private function add( string $name, string $label, string $description, array $input_schema, callable $execute, array $annotations ): void {
		wp_register_ability(
			self::CATEGORY . '/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input_schema,
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => $execute,
				'permission_callback' => array( $this, 'can_edit' ),
				'meta'                => array(
					'annotations' => $annotations,
					'public'      => true,
				),
			)
		);
	}

	/**
	 * @param mixed $input
	 */
	public function can_edit( $input = null ): bool {
		$post_id = is_array( $input ) ? (int) ( $input['post_id'] ?? 0 ) : 0;
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	private function post_only_schema( array $post_id ): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
			'properties'           => array( 'post_id' => $post_id ),
		);
	}

	/**
	 * Runs one of the plugin's REST routes in-process.
	 *
	 * @return array|WP_Error
	 */
	private function dispatch( string $method, string $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/erdo-draft-links/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		if ( $response->is_error() ) {
			return $response->as_error();
		}
		if ( $response->get_status() >= 400 ) {
			return new WP_Error(
				'erdo_draft_links_failed',
				is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : __( 'The request failed.', 'erdo-draft-links' ),
				array( 'status' => $response->get_status() )
			);
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Agents don't need reviewers' email addresses; keep them out of the output.
	 *
	 * @param array|WP_Error $data
	 * @return array|WP_Error
	 */
	private function without_emails( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		unset( $data['author_email'] );
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->without_emails( $value );
			}
		}
		return $data;
	}
}
