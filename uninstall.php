<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}erdo_draft_links`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}erdo_draft_feedback`" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

delete_option( 'erdo_draft_link_db_version' );
delete_option( 'erdo_draft_link_settings' );
delete_option( 'erdo_draft_link_supported_post_types' );
delete_option( 'erdo_draft_link_last_cleanup' );
delete_option( 'erdo_draft_link_migrating' );

wp_clear_scheduled_hook( 'erdo_draft_link_daily_cleanup' );
