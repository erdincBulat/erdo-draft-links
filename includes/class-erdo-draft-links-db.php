<?php
defined( 'ABSPATH' ) || exit;

class Erdo_Draft_Links_DB {

	const FEEDBACK_STATUS_OPEN = 'in_progress';
	const FEEDBACK_STATUS_DONE = 'completed';

	private static ?Erdo_Draft_Links_DB $instance = null;
	private string $table;
	private string $feedback_table;

	private function __construct() {
		global $wpdb;
		$this->table          = $wpdb->prefix . 'erdo_draft_links';
		$this->feedback_table = $wpdb->prefix . 'erdo_draft_feedback';
	}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activate(): void {
		self::get_instance()->create_table();
	}

	public static function deactivate(): void {
		// Table is kept on deactivation; removed only on uninstall.
	}

	public function maybe_upgrade(): void {
		if ( get_option( 'erdo_draft_link_db_version' ) !== ERDO_DRAFT_LINKS_DB_VERSION ) {
			$this->create_table();
		}
	}

	private function create_table(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = $this->table;

		$sql_links = "CREATE TABLE $table (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id bigint(20) UNSIGNED NOT NULL,
  token_hash varchar(255) NOT NULL,
  token_raw varchar(64) NOT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  expires_at datetime DEFAULT NULL,
  view_count bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  UNIQUE KEY post_id (post_id),
  KEY token_raw (token_raw(20)),
  KEY is_active (is_active)
) $charset;";

		$feedback_table = $this->feedback_table;

		$sql_feedback = "CREATE TABLE $feedback_table (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id bigint(20) UNSIGNED NOT NULL,
  author_name varchar(100) NOT NULL DEFAULT '',
  message text NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'in_progress',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY post_id (post_id)
) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_links );
		dbDelta( $sql_feedback );

		update_option( 'erdo_draft_link_db_version', ERDO_DRAFT_LINKS_DB_VERSION );
	}

	public function upsert_link( int $post_id, string $token_hash, string $token_raw, ?string $expires_at ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->replace(
			$this->table,
			array(
				'post_id'    => $post_id,
				'token_hash' => $token_hash,
				'token_raw'  => $token_raw,
				'created_at' => current_time( 'mysql' ),
				'expires_at' => $expires_at,
				'view_count' => 0,
				'is_active'  => 1,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		wp_cache_delete( "erdo_draft_link_post_{$post_id}", 'erdo_draft_link' );

		return false !== $result;
	}

	public function get_link_by_post( int $post_id ): ?object {
		global $wpdb;

		$cache_key = "erdo_draft_link_post_{$post_id}";
		$cached    = wp_cache_get( $cache_key, 'erdo_draft_link' );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		$table = esc_sql( $this->table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE post_id = %d LIMIT 1", $post_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_cache_set( $cache_key, $row ?: '', 'erdo_draft_link', 300 );

		return $row ?: null;
	}

	public function get_active_link_by_token_prefix( string $prefix ): ?object {
		global $wpdb;

		$cache_key = "erdo_draft_link_token_{$prefix}";
		$cached    = wp_cache_get( $cache_key, 'erdo_draft_link' );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		$table = esc_sql( $this->table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE token_raw LIKE %s AND is_active = 1 LIMIT 1",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_cache_set( $cache_key, $row ?: '', 'erdo_draft_link', 60 );

		return $row ?: null;
	}

	public function get_link_by_id( int $id ): ?object {
		global $wpdb;

		$cache_key = "erdo_draft_link_id_{$id}";
		$cached    = wp_cache_get( $cache_key, 'erdo_draft_link' );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		$table = esc_sql( $this->table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_cache_set( $cache_key, $row ?: '', 'erdo_draft_link', 300 );

		return $row ?: null;
	}

	public function increment_view_count( int $id ): void {
		global $wpdb;

		$table = esc_sql( $this->table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare( "UPDATE `{$table}` SET view_count = view_count + 1 WHERE id = %d", $id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_cache_delete( "erdo_draft_link_id_{$id}", 'erdo_draft_link' );
	}

	public function revoke_link( int $post_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->table,
			array( 'is_active' => 0 ),
			array( 'post_id' => $post_id ),
			array( '%d' ),
			array( '%d' )
		);

		wp_cache_delete( "erdo_draft_link_post_{$post_id}", 'erdo_draft_link' );

		return false !== $result;
	}

	public function delete_by_post( int $post_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$this->table,
			array( 'post_id' => $post_id ),
			array( '%d' )
		);

		wp_cache_delete( "erdo_draft_link_post_{$post_id}", 'erdo_draft_link' );
	}

	public function get_table_name(): string {
		return $this->table;
	}

	// -------------------------------------------------------------------------
	// Admin list methods
	// -------------------------------------------------------------------------

	public function get_links_paginated( int $page, int $per_page, string $filter = 'all' ): array {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;
		$table  = esc_sql( $this->table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		switch ( $filter ) {
			case 'active':
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE is_active = 1 AND ( expires_at IS NULL OR expires_at > NOW() ) ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
				break;
			case 'expired':
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE expires_at IS NOT NULL AND expires_at <= NOW() ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
				break;
			case 'revoked':
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE is_active = 0 ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
				break;
			default:
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
				break;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $rows ?: array();
	}

	public function get_links_count( string $filter = 'all' ): int {
		global $wpdb;

		$table = esc_sql( $this->table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		switch ( $filter ) {
			case 'active':
				$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE is_active = 1 AND ( expires_at IS NULL OR expires_at > NOW() )" );
				break;
			case 'expired':
				$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE expires_at IS NOT NULL AND expires_at <= NOW()" );
				break;
			case 'revoked':
				$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE is_active = 0" );
				break;
			default:
				$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
				break;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	public function delete_link_by_id( int $id ): void {
		global $wpdb;

		$link = $this->get_link_by_id( $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
		wp_cache_delete( "erdo_draft_link_id_{$id}", 'erdo_draft_link' );

		if ( $link ) {
			wp_cache_delete( "erdo_draft_link_post_{$link->post_id}", 'erdo_draft_link' );
			wp_cache_delete( 'erdo_draft_link_token_' . substr( $link->token_raw, 0, 20 ), 'erdo_draft_link' );
		}
	}

	// -------------------------------------------------------------------------
	// Preview feedback (own table — not stored as WordPress comments)
	// -------------------------------------------------------------------------

	public function add_feedback( int $post_id, string $name, string $message ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert(
			$this->feedback_table,
			array(
				'post_id'     => $post_id,
				'author_name' => $name,
				'message'     => $message,
				'status'      => self::FEEDBACK_STATUS_OPEN,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		return $result ? (int) $wpdb->insert_id : 0;
	}

	public function update_feedback_status( int $id, string $status ): bool {
		if ( ! in_array( $status, array( self::FEEDBACK_STATUS_OPEN, self::FEEDBACK_STATUS_DONE ), true ) ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->feedback_table,
			array( 'status' => $status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	public static function get_feedback_status_label( string $status ): string {
		if ( self::FEEDBACK_STATUS_DONE === $status ) {
			return __( 'Completed', 'erdo-draft-links' );
		}

		return __( 'In Progress', 'erdo-draft-links' );
	}

	public function get_feedback_paginated( int $page, int $per_page, int $post_id = 0 ): array {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;
		$table  = esc_sql( $this->feedback_table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $post_id > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE post_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $post_id, $per_page, $offset ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $rows ?: array();
	}

	public function get_feedback_count( int $post_id = 0 ): int {
		global $wpdb;

		$table = esc_sql( $this->feedback_table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $post_id > 0 ) {
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE post_id = %d", $post_id ) );
		} else {
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	public function delete_feedback( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( $this->feedback_table, array( 'id' => $id ), array( '%d' ) );
	}

	public function delete_feedback_by_post( int $post_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->feedback_table, array( 'post_id' => $post_id ), array( '%d' ) );
	}

	public function delete_expired_links( int $older_than_days = 7 ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );
		$table  = esc_sql( $this->table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE expires_at IS NOT NULL AND expires_at < %s",
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $deleted;
	}

}
