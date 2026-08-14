<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}erdo_draft_links`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

delete_option( 'erdo_draft_link_db_version' );
delete_option( 'erdo_draft_link_settings' );
