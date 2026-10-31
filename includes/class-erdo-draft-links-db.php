<?php
defined( 'ABSPATH' ) || exit;

class Erdo_Draft_Links_DB {

	const FEEDBACK_STATUS_OPEN = 'in_progress';
	const FEEDBACK_STATUS_DONE = 'completed';

	const MIGRATION_LOCK = 'erdo_draft_link_migrating';

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
		// Routes through the same version-gated path as the normal plugins_loaded
		// check, so a reactivation after a manual file update (not a WP-managed
		// auto-update, which never re-fires this hook) still runs the migrations
		// below instead of silently skipping them.
		self::get_instance()->maybe_upgrade();
	}

	public static function deactivate(): void {
		// Table is kept on deactivation; removed only on uninstall.
	}

	public function maybe_upgrade(): void {
		$installed = get_option( 'erdo_draft_link_db_version' );
		if ( ERDO_DRAFT_LINKS_DB_VERSION === $installed ) {
			return;
		}

		// Two overlapping requests could otherwise both run the one-shot data
		// migrations below. A stale lock (crashed request) is taken over after
		// ten minutes.
		$lock = (int) get_option( self::MIGRATION_LOCK, 0 );
		if ( $lock && $lock > time() - 10 * MINUTE_IN_SECONDS ) {
			return;
		}
		update_option( self::MIGRATION_LOCK, time(), false );

		$this->drop_unique_post_id_index();
		$this->create_table();

		if ( $installed && version_compare( $installed, '2.0.0', '<' ) ) {
			$this->convert_local_dates_to_gmt();
		}
		$this->encrypt_plaintext_tokens();

		update_option( 'erdo_draft_link_db_version', ERDO_DRAFT_LINKS_DB_VERSION );
		delete_option( self::MIGRATION_LOCK );
	}

	/**
	 * dbDelta() cannot change an existing index's definition in place — it only
	 * adds indexes it doesn't already find by name, so a `UNIQUE KEY post_id`
	 * left over from < 1.2.0 would silently keep enforcing one link per post
	 * forever. Drop it explicitly before dbDelta() re-adds it as a plain KEY.
	 */
	private function drop_unique_post_id_index(): void {
		global $wpdb;
		$table = esc_sql( $this->table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$is_unique = $wpdb->get_var( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'post_id' AND Non_unique = 0" );
		if ( $is_unique ) {
			$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX post_id" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * < 2.0.0 wrote created_at/replied_at in site-local time while expires_at
	 * was already GMT. Shift the local ones by the current site offset so every
	 * datetime column is GMT from now on (rows written across a DST change may
	 * be off by an hour — display-only, never used for access decisions).
	 */
	private function convert_local_dates_to_gmt(): void {
		global $wpdb;

		$offset = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
		if ( 0 === $offset ) {
			return;
		}

		$links    = esc_sql( $this->table );
		$feedback = esc_sql( $this->feedback_table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE `{$links}` SET created_at = DATE_SUB( created_at, INTERVAL %d SECOND ) WHERE created_at > '1000-01-01'", $offset ) );
		$wpdb->query( $wpdb->prepare( "UPDATE `{$feedback}` SET created_at = DATE_SUB( created_at, INTERVAL %d SECOND ) WHERE created_at > '1000-01-01'", $offset ) );
		$wpdb->query( $wpdb->prepare( "UPDATE `{$feedback}` SET replied_at = DATE_SUB( replied_at, INTERVAL %d SECOND ) WHERE replied_at IS NOT NULL", $offset ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * < 2.0.0 kept the raw token in a plain-text `token_raw` column. Encrypt
	 * each one into `token_enc` (so existing shared URLs keep working and can
	 * still be copied), then drop the plain-text column. Safe to re-run.
	 */
	private function encrypt_plaintext_tokens(): void {
		global $wpdb;
		$table = esc_sql( $this->table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'token_raw'" ) ) {
			return;
		}

		$token = new Erdo_Draft_Links_Token();
		$rows  = $wpdb->get_results( "SELECT id, token_raw FROM `{$table}` WHERE token_enc = '' AND token_raw <> ''" );

		foreach ( (array) $rows as $row ) {
			$wpdb->update(
				$this->table,
				array( 'token_enc' => $token->encrypt( (string) $row->token_raw ) ),
				array( 'id' => (int) $row->id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN token_raw" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Cached rows from before the migration still carry token_raw and no
		// token_enc. Without group flushing they simply age out (≤ 5 minutes).
		if ( wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'erdo_draft_link' );
		}
	}

	private function create_table(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = $this->table;

		$sql_links = "CREATE TABLE $table (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id bigint(20) UNSIGNED NOT NULL,
  label varchar(100) NOT NULL DEFAULT '',
  token_hash varchar(255) NOT NULL,
  token_enc varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  expires_at datetime DEFAULT NULL,
  view_count bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY post_id (post_id),
  KEY token_hash (token_hash(64)),
  KEY is_active (is_active)
) $charset;";

		$feedback_table = $this->feedback_table;

		$sql_feedback = "CREATE TABLE $feedback_table (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id bigint(20) UNSIGNED NOT NULL,
  link_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  author_name varchar(100) NOT NULL DEFAULT '',
  message text NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'in_progress',
  reply text DEFAULT NULL,
  replied_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY post_id (post_id),
  KEY link_id (link_id)
) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_links );
		dbDelta( $sql_feedback );
	}

	// -------------------------------------------------------------------------
	// Links
	// -------------------------------------------------------------------------

	public function insert_link( int $post_id, string $label, string $token_hash, string $token_enc, ?string $expires_at ): int|false {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert(
			$this->table,
			array(
				'post_id'    => $post_id,
				'label'      => $label,
				'token_hash' => $token_hash,
				'token_enc'  => $token_enc,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => $expires_at,
				'view_count' => 0,
				'is_active'  => 1,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		wp_cache_delete( "erdo_draft_link_post_{$post_id}", 'erdo_draft_link' );

		return $result ? (int) $wpdb->insert_id : false;
	}

	public function get_active_links_by_post( int $post_id ): array {
		global $wpdb;

		$cache_key = "erdo_draft_link_post_{$post_id}";
		$cached    = wp_cache_get( $cache_key, 'erdo_draft_link' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$table = esc_sql( $this->table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE post_id = %d AND is_active = 1 AND ( expires_at IS NULL OR expires_at > %s ) ORDER BY created_at DESC, id DESC",
				$post_id,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $rows ?: array();

		// Short TTL: the expiry filter above is evaluated at query time.
		wp_cache_set( $cache_key, $rows, 'erdo_draft_link', MINUTE_IN_SECONDS );

		return $rows;
	}

	/**
	 * Looks a link up by the HMAC of its raw token, regardless of status, so the
	 * caller can tell "revoked"/"expired" apart from "unknown".
	 */
	public function get_link_by_token_hash( string $token_hash ): ?object {
		global $wpdb;

		$cache_key = "erdo_draft_link_hash_{$token_hash}";
		$cached    = wp_cache_get( $cache_key, 'erdo_draft_link' );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		$table = esc_sql( $this->table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE token_hash = %s LIMIT 1", $token_hash )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_cache_set( $cache_key, $row ?: '', 'erdo_draft_link', 300 );

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

		$link  = $this->get_link_by_id( $id );
		$table = esc_sql( $this->table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare( "UPDATE `{$table}` SET view_count = view_count + 1 WHERE id = %d", $id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $link ) {
			$this->flush_link_cache( $link );
		}
	}

	public function revoke_link_by_id( int $link_id ): bool {
		global $wpdb;

		$link = $this->get_link_by_id( $link_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->table,
			array( 'is_active' => 0 ),
			array( 'id' => $link_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( $link ) {
			$this->flush_link_cache( $link );
		}

		return false !== $result;
	}

	public function delete_link_by_id( int $id ): void {
		global $wpdb;

		$link = $this->get_link_by_id( $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );

		if ( $link ) {
			$this->flush_link_cache( $link );
		}
	}

	public function delete_by_post( int $post_id ): void {
		global $wpdb;

		$table = esc_sql( $this->table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$links = $wpdb->get_results( $wpdb->prepare( "SELECT id, post_id, token_hash FROM `{$table}` WHERE post_id = %d", $post_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$this->table,
			array( 'post_id' => $post_id ),
			array( '%d' )
		);

		foreach ( (array) $links as $link ) {
			$this->flush_link_cache( $link );
		}
		wp_cache_delete( "erdo_draft_link_post_{$post_id}", 'erdo_draft_link' );
	}

	/**
	 * Every cache key a link row can be reached by. Any write to a link must
	 * call this, or a revoked/deleted link stays usable until the TTL runs out.
	 */
	private function flush_link_cache( object $link ): void {
		wp_cache_delete( "erdo_draft_link_id_{$link->id}", 'erdo_draft_link' );
		wp_cache_delete( "erdo_draft_link_post_{$link->post_id}", 'erdo_draft_link' );
		wp_cache_delete( "erdo_draft_link_hash_{$link->token_hash}", 'erdo_draft_link' );
	}

	public function get_table_name(): string {
		return $this->table;
	}

	// -------------------------------------------------------------------------
	// Admin list methods
	//
	// $author_id > 0 limits results to posts written by that user — used for
	// users who can't edit others' posts, so they only see their own links.
	// -------------------------------------------------------------------------

	public function get_links_paginated( int $page, int $per_page, string $filter = 'all', int $author_id = 0 ): array {
		global $wpdb;

		$table = esc_sql( $this->table );
		list( $where, $args ) = $this->links_where( $filter, $author_id );

		$args[] = $per_page;
		$args[] = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return $rows ?: array();
	}

	public function get_links_count( string $filter = 'all', int $author_id = 0 ): int {
		global $wpdb;

		$table = esc_sql( $this->table );
		list( $where, $args ) = $this->links_where( $filter, $author_id );
		$sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return (int) $count;
	}

	/**
	 * @return array{0: string, 1: array} WHERE clause with placeholders, and its arguments.
	 */
	private function links_where( string $filter, int $author_id ): array {
		global $wpdb;

		$where = array( '1=1' );
		$args  = array();

		switch ( $filter ) {
			case 'active':
				$where[] = 'is_active = 1 AND ( expires_at IS NULL OR expires_at > %s )';
				$args[]  = gmdate( 'Y-m-d H:i:s' );
				break;
			case 'expired':
				$where[] = 'expires_at IS NOT NULL AND expires_at <= %s';
				$args[]  = gmdate( 'Y-m-d H:i:s' );
				break;
			case 'revoked':
				$where[] = 'is_active = 0';
				break;
		}

		if ( $author_id > 0 ) {
			$where[] = "post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_author = %d )";
			$args[]  = $author_id;
		}

		return array( implode( ' AND ', $where ), $args );
	}

	// -------------------------------------------------------------------------
	// Preview feedback (own table — not stored as WordPress comments)
	// -------------------------------------------------------------------------

	public function add_feedback( int $post_id, int $link_id, string $name, string $message ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert(
			$this->feedback_table,
			array(
				'post_id'     => $post_id,
				'link_id'     => $link_id,
				'author_name' => $name,
				'message'     => $message,
				'status'      => self::FEEDBACK_STATUS_OPEN,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return $result ? (int) $wpdb->insert_id : 0;
	}

	public function get_feedback_by_id( int $id ): ?object {
		global $wpdb;

		$table = esc_sql( $this->feedback_table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ?: null;
	}

	/**
	 * Feedback left through one specific link — what a preview visitor may see.
	 * Feedback from other links on the same post (other clients) stays private.
	 */
	public function get_feedback_by_link( int $link_id, int $limit = 50 ): array {
		global $wpdb;

		$table = esc_sql( $this->feedback_table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE link_id = %d ORDER BY id DESC LIMIT %d", $link_id, $limit ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $rows ?: array();
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

	public function add_feedback_reply( int $feedback_id, string $reply ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->feedback_table,
			array(
				'reply'      => $reply,
				'replied_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $feedback_id ),
			array( '%s', '%s' ),
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

	public function get_feedback_paginated( int $page, int $per_page, int $post_id = 0, int $author_id = 0 ): array {
		global $wpdb;

		$table = esc_sql( $this->feedback_table );
		list( $where, $args ) = $this->feedback_where( $post_id, $author_id );

		$args[] = $per_page;
		$args[] = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return $rows ?: array();
	}

	public function get_feedback_count( int $post_id = 0, int $author_id = 0 ): int {
		global $wpdb;

		$table = esc_sql( $this->feedback_table );
		list( $where, $args ) = $this->feedback_where( $post_id, $author_id );
		$sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return (int) $count;
	}

	/**
	 * @return array{0: string, 1: array} WHERE clause with placeholders, and its arguments.
	 */
	private function feedback_where( int $post_id, int $author_id ): array {
		global $wpdb;

		$where = array( '1=1' );
		$args  = array();

		if ( $post_id > 0 ) {
			$where[] = 'post_id = %d';
			$args[]  = $post_id;
		}

		if ( $author_id > 0 ) {
			$where[] = "post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_author = %d )";
			$args[]  = $author_id;
		}

		return array( implode( ' AND ', $where ), $args );
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
