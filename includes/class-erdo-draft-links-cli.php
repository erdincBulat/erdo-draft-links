<?php
defined( 'ABSPATH' ) || exit;

/**
 * Manage Erdo Draft Links from WP-CLI.
 */
class Erdo_Draft_Links_CLI extends WP_CLI_Command {

	private Erdo_Draft_Links_DB $db;
	private Erdo_Draft_Links_Token $token;

	public function __construct( Erdo_Draft_Links_DB $db, Erdo_Draft_Links_Token $token ) {
		$this->db    = $db;
		$this->token = $token;
	}

	/**
	 * Generates a new draft link for a post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The ID of the post or page to generate a link for.
	 *
	 * [--label=<label>]
	 * : An optional label for the link (e.g. "Client A").
	 *
	 * [--expiry=<expiry>]
	 * : Expiry preset.
	 * ---
	 * default: 24h
	 * options:
	 *   - 24h
	 *   - 48h
	 *   - 7d
	 *   - never
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp erdo-draft-links generate 123 --label="Client A" --expiry=7d
	 *
	 * @when after_wp_load
	 */
	public function generate( array $args, array $assoc_args ): void {
		$post_id = (int) $args[0];
		if ( ! get_post( $post_id ) ) {
			WP_CLI::error( "Post {$post_id} not found." );
		}

		$label      = isset( $assoc_args['label'] ) ? sanitize_text_field( mb_substr( (string) $assoc_args['label'], 0, 100 ) ) : '';
		$expiry     = $assoc_args['expiry'] ?? '24h';
		$expires_at = $this->token->compute_expires_at( $expiry );

		$token_data = $this->token->generate();
		$link_id    = $this->db->insert_link( $post_id, $label, $token_data['hash'], $token_data['raw'], $expires_at );

		if ( ! $link_id ) {
			WP_CLI::error( 'Could not save the link.' );
		}

		$url = add_query_arg( 'erdo_token', $token_data['raw'], get_permalink( $post_id ) );

		WP_CLI::success( "Link #{$link_id} generated." );
		WP_CLI::line( $url );
	}

	/**
	 * Revokes a draft link.
	 *
	 * ## OPTIONS
	 *
	 * <link_id>
	 * : The ID of the link to revoke.
	 *
	 * ## EXAMPLES
	 *
	 *     wp erdo-draft-links revoke 42
	 *
	 * @when after_wp_load
	 */
	public function revoke( array $args ): void {
		$link_id = (int) $args[0];
		if ( ! $this->db->get_link_by_id( $link_id ) ) {
			WP_CLI::error( "Link {$link_id} not found." );
		}

		$this->db->revoke_link_by_id( $link_id );
		WP_CLI::success( "Link #{$link_id} revoked." );
	}

	/**
	 * Lists draft links.
	 *
	 * ## OPTIONS
	 *
	 * [--post_id=<post_id>]
	 * : Only list links for this post.
	 *
	 * [--status=<status>]
	 * : Filter by status.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - active
	 *   - expired
	 *   - revoked
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp erdo-draft-links list
	 *     wp erdo-draft-links list --post_id=123 --format=json
	 *
	 * @subcommand list
	 * @when after_wp_load
	 */
	public function list_( array $args, array $assoc_args ): void {
		$status = $assoc_args['status'] ?? 'all';
		$rows   = $this->db->get_links_paginated( 1, 10000, $status );

		if ( isset( $assoc_args['post_id'] ) ) {
			$post_id = (int) $assoc_args['post_id'];
			$rows    = array_values( array_filter( $rows, static fn( $row ) => (int) $row->post_id === $post_id ) );
		}

		$items = array_map(
			function ( $row ) {
				$is_expired = $row->is_active && $this->token->is_expired( $row->expires_at );
				return array(
					'id'         => $row->id,
					'post_id'    => $row->post_id,
					'post_title' => get_the_title( (int) $row->post_id ),
					'label'      => '' !== $row->label ? $row->label : '—',
					'status'     => ! $row->is_active ? 'revoked' : ( $is_expired ? 'expired' : 'active' ),
					'views'      => $row->view_count,
					'expires_at' => $row->expires_at ?? '—',
					'created_at' => $row->created_at,
				);
			},
			$rows
		);

		WP_CLI\Utils\format_items(
			$assoc_args['format'] ?? 'table',
			$items,
			array( 'id', 'post_id', 'post_title', 'label', 'status', 'views', 'expires_at', 'created_at' )
		);
	}
}
