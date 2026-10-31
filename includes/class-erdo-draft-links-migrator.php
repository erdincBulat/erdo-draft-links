<?php
defined( 'ABSPATH' ) || exit;

/**
 * Schema upgrades. dbDelta() (in DB::create_tables()) adds columns and
 * indexes; everything it can't do — dropping/changing indexes, moving data,
 * dropping columns — happens here as explicit, re-runnable steps.
 */
class Erdo_Draft_Links_Migrator {

	const VERSION_OPTION = 'erdo_draft_link_db_version';
	const LOCK_OPTION    = 'erdo_draft_link_migrating';

	/** Set after any upgrade; Frontend flushes rewrite rules once on the next init. */
	const FLUSH_RULES_OPTION = 'erdo_draft_link_flush_rules';

	private Erdo_Draft_Links_DB $db;
	private Erdo_Draft_Links_Token $token;

	public function __construct( Erdo_Draft_Links_DB $db, Erdo_Draft_Links_Token $token ) {
		$this->db    = $db;
		$this->token = $token;
	}

	public function maybe_upgrade(): void {
		$installed = get_option( self::VERSION_OPTION );
		if ( ERDO_DRAFT_LINKS_DB_VERSION === $installed ) {
			return;
		}

		// Two overlapping requests could otherwise both run the one-shot data
		// migrations below. A stale lock (crashed request) is taken over after
		// ten minutes.
		$lock = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $lock && $lock > time() - 10 * MINUTE_IN_SECONDS ) {
			return;
		}
		update_option( self::LOCK_OPTION, time(), false );

		$this->drop_unique_post_id_index();
		$this->db->create_tables();

		if ( $installed && version_compare( $installed, '2.0.0', '<' ) ) {
			$this->convert_local_dates_to_gmt();
		}
		$this->encrypt_plaintext_tokens();
		$this->move_legacy_replies();

		update_option( self::VERSION_OPTION, ERDO_DRAFT_LINKS_DB_VERSION );
		update_option( self::FLUSH_RULES_OPTION, 1 );
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * dbDelta() cannot change an existing index's definition in place — it only
	 * adds indexes it doesn't already find by name, so a `UNIQUE KEY post_id`
	 * left over from < 1.2.0 would silently keep enforcing one link per post
	 * forever. Drop it explicitly before dbDelta() re-adds it as a plain KEY.
	 */
	private function drop_unique_post_id_index(): void {
		global $wpdb;
		$table = esc_sql( $this->db->get_table_name() );

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

		$links    = esc_sql( $this->db->get_table_name() );
		$feedback = esc_sql( $this->db->get_feedback_table_name() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE `{$links}` SET created_at = DATE_SUB( created_at, INTERVAL %d SECOND ) WHERE created_at > '1000-01-01'", $offset ) );
		$wpdb->query( $wpdb->prepare( "UPDATE `{$feedback}` SET created_at = DATE_SUB( created_at, INTERVAL %d SECOND ) WHERE created_at > '1000-01-01'", $offset ) );
		$wpdb->query( $wpdb->prepare( "UPDATE `{$feedback}` SET replied_at = DATE_SUB( replied_at, INTERVAL %d SECOND ) WHERE replied_at IS NOT NULL", $offset ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * < 2.0.0 stored one admin reply per feedback item in its `reply` column.
	 * 2.0 threads replies as child rows; move each legacy reply into one
	 * (attributed to the post's author) and clear the column. Safe to re-run.
	 */
	private function move_legacy_replies(): void {
		global $wpdb;
		$table = esc_sql( $this->db->get_feedback_table_name() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` WHERE reply IS NOT NULL AND reply <> ''" );

		foreach ( (array) $rows as $row ) {
			$author_id = (int) get_post_field( 'post_author', (int) $row->post_id );
			$author    = $author_id ? get_userdata( $author_id ) : false;
			$reply_id  = $this->db->add_reply( $row, $author_id, $author ? $author->display_name : get_bloginfo( 'name' ), (string) $row->reply );

			if ( $reply_id && $row->replied_at ) {
				$wpdb->update( $this->db->get_feedback_table_name(), array( 'created_at' => $row->replied_at ), array( 'id' => $reply_id ), array( '%s' ), array( '%d' ) );
			}
			$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET reply = NULL, replied_at = NULL WHERE id = %d", (int) $row->id ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * < 2.0.0 kept the raw token in a plain-text `token_raw` column. Encrypt
	 * each one into `token_enc` (so existing shared URLs keep working and can
	 * still be copied), then drop the plain-text column. Safe to re-run.
	 */
	private function encrypt_plaintext_tokens(): void {
		global $wpdb;
		$table = esc_sql( $this->db->get_table_name() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'token_raw'" ) ) {
			return;
		}

		$rows = $wpdb->get_results( "SELECT id, token_raw FROM `{$table}` WHERE token_enc = '' AND token_raw <> ''" );

		foreach ( (array) $rows as $row ) {
			$wpdb->update(
				$this->db->get_table_name(),
				array( 'token_enc' => $this->token->encrypt( (string) $row->token_raw ) ),
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
}
