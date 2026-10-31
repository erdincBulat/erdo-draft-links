<?php
/**
 * Plugin Name:       Erdo Draft Links
 * Plugin URI:        https://wordpress.org/plugins/erdo-draft-links/
 * Description:       Share draft posts with non-logged-in users via a secure, temporary link — no login required.
 * Version:           2.0.0
 * Requires at least: 6.6
 * Tested up to:      7.1
 * Requires PHP:      8.0
 * Author:            Erdinc Bulat
 * Author URI:        https://erdincbulat.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       erdo-draft-links
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'ERDO_DRAFT_LINKS_VERSION',     '2.0.0' );
define( 'ERDO_DRAFT_LINKS_DB_VERSION',  '2.0.0' );
define( 'ERDO_DRAFT_LINKS_PLUGIN_FILE', __FILE__ );
define( 'ERDO_DRAFT_LINKS_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );

spl_autoload_register( function ( string $class ): void {
	if ( strpos( $class, 'Erdo_Draft_Links_' ) !== 0 ) {
		return;
	}
	$file = ERDO_DRAFT_LINKS_PLUGIN_DIR . 'includes/class-' .
	        strtolower( str_replace( '_', '-', $class ) ) . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

register_activation_hook( __FILE__, static function (): void {
	Erdo_Draft_Links_DB::activate();
	Erdo_Draft_Links_Cron::schedule();
	// The /review/ rewrite rule is registered on init, after this hook.
	update_option( Erdo_Draft_Links_Migrator::FLUSH_RULES_OPTION, 1 );
} );

register_deactivation_hook( __FILE__, static function (): void {
	Erdo_Draft_Links_DB::deactivate();
	Erdo_Draft_Links_Cron::unschedule();
	// Our rule is still registered during this request, so a flush would keep
	// it; dropping the cache makes WP rebuild the rules without the plugin.
	delete_option( 'rewrite_rules' );
} );

add_action( 'plugins_loaded', function (): void {
	$loader        = new Erdo_Draft_Links_Loader();
	$db            = Erdo_Draft_Links_DB::get_instance();
	$token         = new Erdo_Draft_Links_Token();
	$session       = new Erdo_Draft_Links_Session( $db, $token );
	$notifications = new Erdo_Draft_Links_Notifications();
	$review        = new Erdo_Draft_Links_Review( $db, $session, $notifications );
	$admin         = new Erdo_Draft_Links_Admin( $db, $token, $review );
	$feedback_api  = new Erdo_Draft_Links_Feedback_Api( $db, $review );
	$visitor_api   = new Erdo_Draft_Links_Visitor_Api( $db, $token, $session, $review, $notifications );
	$frontend      = new Erdo_Draft_Links_Frontend( $db, $token, $session, new Erdo_Draft_Links_Status_Page(), $notifications );
	$cron          = new Erdo_Draft_Links_Cron( $db );

	( new Erdo_Draft_Links_Migrator( $db, $token ) )->maybe_upgrade();
	$review->register( $loader );
	$admin->register( $loader );
	$feedback_api->register( $loader );
	$visitor_api->register( $loader );
	$frontend->register( $loader );
	$cron->register( $loader );
	$loader->run();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command( 'erdo-draft-links', new Erdo_Draft_Links_CLI( $db, $token ) );
	}
} );
