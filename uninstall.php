<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Take our cookie back out of LiteSpeed Cache's "Do Not Cache Cookies" list
// (added by Erdo_Draft_Links_Compat). Done even when data is kept: a
// reinstall adds it again because the marker options below are removed.
if ( defined( 'LSCWP_V' ) && class_exists( 'LiteSpeed\Conf' ) && method_exists( 'LiteSpeed\Conf', 'cls' ) ) {
	$erdo_draft_links_ls       = \LiteSpeed\Conf::cls();
	$erdo_draft_links_excludes = (array) $erdo_draft_links_ls->conf( 'cache-exc_cookies' );
	if ( in_array( 'erdo_draft_link_', $erdo_draft_links_excludes, true ) ) {
		$erdo_draft_links_ls->update_confs( array( 'cache-exc_cookies' => array_values( array_diff( $erdo_draft_links_excludes, array( 'erdo_draft_link_' ) ) ) ) );
	}
}
delete_option( 'erdo_draft_link_cache_rules' );
delete_option( 'erdo_draft_link_cache_rules_for' );

// "Remove all links, feedback and settings" (on by default) can be switched
// off on Draft Links → Settings to keep the data for a reinstall.
$erdo_draft_links_settings = get_option( 'erdo_draft_link_settings' );
if ( is_array( $erdo_draft_links_settings ) && array_key_exists( 'delete_data', $erdo_draft_links_settings ) && ! $erdo_draft_links_settings['delete_data'] ) {
	wp_clear_scheduled_hook( 'erdo_draft_link_daily_cleanup' );
	wp_clear_scheduled_hook( 'erdo_draft_link_expiry_check' );
	return;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}erdo_draft_links`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}erdo_draft_feedback`" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

delete_option( 'erdo_draft_link_db_version' );
delete_option( 'erdo_draft_link_settings' );
delete_option( 'erdo_draft_link_supported_post_types' );
delete_option( 'erdo_draft_link_last_cleanup' );
delete_option( 'erdo_draft_link_migrating' );
delete_option( 'erdo_draft_link_flush_rules' );
delete_post_meta_by_key( '_erdo_dl_review' );
delete_post_meta_by_key( '_erdo_dl_changes_note' );
delete_transient( 'erdo_draft_link_ai_status' );

wp_clear_scheduled_hook( 'erdo_draft_link_daily_cleanup' );
wp_clear_scheduled_hook( 'erdo_draft_link_expiry_check' );
