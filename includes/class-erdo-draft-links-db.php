<?php
defined( 'ABSPATH' ) || exit;

class Erdo_Draft_Links_DB {

	const FEEDBACK_STATUS_OPEN = 'in_progress';
	const FEEDBACK_STATUS_DONE = 'completed';

	const REVIEW_PENDING   = 'pending';
	const REVIEW_APPROVED  = 'approved';
	const REVIEW_CHANGES   = 'changes_requested';

	const FEEDBACK_TYPE_COMMENT    = 'comment';
	const FEEDBACK_TYPE_ANNOTATION = 'annotation';
	const FEEDBACK_TYPE_APPROVAL   = 'approval';
	const FEEDBACK_TYPE_CHANGES    = 'changes_requested';

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
		// instead of silently skipping them.
		$db = self::get_instance();
		( new Erdo_Draft_Links_Migrator( $db, new Erdo_Draft_Links_Token() ) )->maybe_upgrade();
	}

	public static function deactivate(): void {
		// Table is kept on deactivation; removed only on uninstall.
	}

	/**
	 * Current schema. dbDelta() adds missing columns/indexes; anything it
	 * can't do lives in Erdo_Draft_Links_Migrator. All datetimes are GMT.
	 */
	public function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = $this->table;

		$sql_links = "CREATE TABLE $table (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id bigint(20) UNSIGNED NOT NULL,
  label varchar(100) NOT NULL DEFAULT '',
  token_hash varchar(255) NOT NULL,
  token_enc varchar(255) NOT NULL DEFAULT '',
  created_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  expires_at datetime DEFAULT NULL,
  last_viewed_at datetime DEFAULT NULL,
  view_count bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  max_views int(10) UNSIGNED DEFAULT NULL,
  password_hash varchar(255) NOT NULL DEFAULT '',
  is_active tinyint(1) NOT NULL DEFAULT 1,
  review_status varchar(20) NOT NULL DEFAULT 'pending',
  reviewed_at datetime DEFAULT NULL,
  reviewer_name varchar(100) NOT NULL DEFAULT '',
  reviewed_modified datetime DEFAULT NULL,
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
  parent_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  type varchar(20) NOT NULL DEFAULT 'comment',
  author_name varchar(100) NOT NULL DEFAULT '',
  author_email varchar(100) NOT NULL DEFAULT '',
  message text NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'in_progress',
  reply text DEFAULT NULL,
  replied_at datetime DEFAULT NULL,
  selector text DEFAULT NULL,
  x_percent decimal(7,4) DEFAULT NULL,
  y_percent decimal(7,4) DEFAULT NULL,
  viewport varchar(20) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY post_id (post_id),
  KEY link_id (link_id),
  KEY parent_id (parent_id)
) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_links );
		dbDelta( $sql_feedback );
	}

	// -------------------------------------------------------------------------
	// Links
	// -------------------------------------------------------------------------

	/**
	 * @param array{post_id: int, token_hash: string, token_enc: string, label?: string, expires_at?: ?string, created_by?: int, password_hash?: string, max_views?: ?int} $data
	 */
	public function insert_link( array $data ): int|false {
		global $wpdb;

		$post_id = (int) $data['post_id'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert(
			$this->table,
			array(
				'post_id'       => $post_id,
				'label'         => (string) ( $data['label'] ?? '' ),
				'token_hash'    => (string) $data['token_hash'],
				'token_enc'     => (string) $data['token_enc'],
				'created_by'    => (int) ( $data['created_by'] ?? 0 ),
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
				'expires_at'    => $data['expires_at'] ?? null,
				'view_count'    => 0,
				'max_views'     => isset( $data['max_views'] ) && $data['max_views'] > 0 ? (int) $data['max_views'] : null,
				'password_hash' => (string) ( $data['password_hash'] ?? '' ),
				'is_active'     => 1,
				'review_status' => self::REVIEW_PENDING,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s' )
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

	public function record_view( int $id ): void {
		global $wpdb;

		$link  = $this->get_link_by_id( $id );
		$table = esc_sql( $this->table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare( "UPDATE `{$table}` SET view_count = view_count + 1, last_viewed_at = %s WHERE id = %d", gmdate( 'Y-m-d H:i:s' ), $id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $link ) {
			$this->flush_link_cache( $link );
		}
	}

	/**
	 * Updates editable fields of a link. Pass only the keys to change.
	 *
	 * @param array{label?: string, expires_at?: ?string} $fields
	 */
	public function update_link( int $link_id, array $fields ): bool {
		$fields = array_intersect_key( $fields, array_flip( array( 'label', 'expires_at' ) ) );
		if ( ! $fields ) {
			return false;
		}

		global $wpdb;

		$link = $this->get_link_by_id( $link_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $this->table, $fields, array( 'id' => $link_id ), array_fill( 0, count( $fields ), '%s' ), array( '%d' ) );

		if ( $link ) {
			$this->flush_link_cache( $link );
		}

		return false !== $result;
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

	/**
	 * Stores a reviewer's decision on the link, together with the post's
	 * modification time at that moment so later edits can mark it as stale.
	 */
	public function record_review( int $link_id, string $status, string $reviewer_name, string $post_modified_gmt ): bool {
		if ( ! in_array( $status, array( self::REVIEW_APPROVED, self::REVIEW_CHANGES, self::REVIEW_PENDING ), true ) ) {
			return false;
		}

		global $wpdb;

		$link = $this->get_link_by_id( $link_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->table,
			array(
				'review_status'     => $status,
				'reviewed_at'       => gmdate( 'Y-m-d H:i:s' ),
				'reviewer_name'     => $reviewer_name,
				'reviewed_modified' => $post_modified_gmt,
			),
			array( 'id' => $link_id ),
			array( '%s', '%s', '%s', '%s' ),
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

	public function get_feedback_table_name(): string {
		return $this->feedback_table;
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

		// $where is built only from fixed SQL and placeholders (links_where/feedback_where); its values are in $args.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $rows ?: array();
	}

	public function get_links_count( string $filter = 'all', int $author_id = 0 ): int {
		global $wpdb;

		$table = esc_sql( $this->table );
		list( $where, $args ) = $this->links_where( $filter, $author_id );
		$sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$count = $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

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

	/**
	 * @param array{type?: string, author_email?: string, parent_id?: int, user_id?: int, selector?: string, x_percent?: float, y_percent?: float, viewport?: string} $extra
	 */
	public function add_feedback( int $post_id, int $link_id, string $name, string $message, array $extra = array() ): int {
		global $wpdb;

		$row = array(
			'post_id'      => $post_id,
			'link_id'      => $link_id,
			'parent_id'    => (int) ( $extra['parent_id'] ?? 0 ),
			'user_id'      => (int) ( $extra['user_id'] ?? 0 ),
			'type'         => (string) ( $extra['type'] ?? self::FEEDBACK_TYPE_COMMENT ),
			'author_name'  => $name,
			'author_email' => (string) ( $extra['author_email'] ?? '' ),
			'message'      => $message,
			'status'       => self::FEEDBACK_STATUS_OPEN,
			'viewport'     => (string) ( $extra['viewport'] ?? '' ),
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
		);
		$formats = array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		// Annotation position — left NULL (not 0) for plain comments.
		foreach ( array( 'selector' => '%s', 'x_percent' => '%f', 'y_percent' => '%f' ) as $key => $format ) {
			if ( isset( $extra[ $key ] ) ) {
				$row[ $key ] = $extra[ $key ];
				$formats[]   = $format;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $this->feedback_table, $row, $formats );

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
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE link_id = %d AND parent_id = 0 ORDER BY id DESC LIMIT %d", $link_id, $limit ) );
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

	/**
	 * Adds a reply (a child row) to a top-level feedback item. Staff replies
	 * carry the WordPress user id; visitor replies have user_id 0.
	 */
	public function add_reply( object $parent, int $user_id, string $name, string $message ): int {
		return $this->add_feedback(
			(int) $parent->post_id,
			(int) $parent->link_id,
			$name,
			$message,
			array(
				'parent_id' => (int) $parent->id,
				'user_id'   => $user_id,
			)
		);
	}

	/**
	 * Replies for the given top-level items, oldest first, keyed by parent id.
	 *
	 * @param int[] $parent_ids
	 * @return array<int, object[]>
	 */
	public function get_replies( array $parent_ids ): array {
		$parent_ids = array_values( array_filter( array_map( 'intval', $parent_ids ) ) );
		if ( ! $parent_ids ) {
			return array();
		}

		global $wpdb;

		$table        = esc_sql( $this->feedback_table );
		$placeholders = implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE parent_id IN ( {$placeholders} ) ORDER BY id ASC", $parent_ids ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$grouped = array();
		foreach ( (array) $rows as $row ) {
			$grouped[ (int) $row->parent_id ][] = $row;
		}
		return $grouped;
	}

	/**
	 * Top-level feedback items on a post across all its links (editor view).
	 */
	public function get_feedback_for_post( int $post_id, int $limit = 200 ): array {
		global $wpdb;

		$table = esc_sql( $this->feedback_table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE post_id = %d AND parent_id = 0 ORDER BY id DESC LIMIT %d", $post_id, $limit ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $rows ?: array();
	}

	public function count_open_feedback( int $post_id ): int {
		global $wpdb;

		$table = esc_sql( $this->feedback_table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE post_id = %d AND parent_id = 0 AND type IN ( %s, %s, %s ) AND status = %s",
				$post_id,
				self::FEEDBACK_TYPE_COMMENT,
				self::FEEDBACK_TYPE_ANNOTATION,
				self::FEEDBACK_TYPE_CHANGES,
				self::FEEDBACK_STATUS_OPEN
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
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

		// $where is built only from fixed SQL and placeholders (links_where/feedback_where); its values are in $args.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $rows ?: array();
	}

	public function get_feedback_count( int $post_id = 0, int $author_id = 0 ): int {
		global $wpdb;

		$table = esc_sql( $this->feedback_table );
		list( $where, $args ) = $this->feedback_where( $post_id, $author_id );
		$sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$count = $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return (int) $count;
	}

	/**
	 * @return array{0: string, 1: array} WHERE clause with placeholders, and its arguments.
	 */
	private function feedback_where( int $post_id, int $author_id ): array {
		global $wpdb;

		$where = array( 'parent_id = 0' );
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
		$wpdb->delete( $this->feedback_table, array( 'parent_id' => $id ), array( '%d' ) );
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
