<?php
/**
 * Plugin Name:       Erdo Draft Links
 * Plugin URI:        https://wordpress.org/plugins/erdo-draft-links/
 * Description:       Share draft posts with non-logged-in users via a secure, temporary link — no login required.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            Erdinc Bulat
 * Author URI:        https://erdincbulat.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       erdo-draft-links
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'ERDO_DRAFT_LINKS_VERSION',     '1.1.0' );
define( 'ERDO_DRAFT_LINKS_DB_VERSION',  '1.1.0' );
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
} );

register_deactivation_hook( __FILE__, static function (): void {
	Erdo_Draft_Links_DB::deactivate();
	Erdo_Draft_Links_Cron::unschedule();
} );

add_action( 'plugins_loaded', function (): void {
	$loader   = new Erdo_Draft_Links_Loader();
	$db       = Erdo_Draft_Links_DB::get_instance();
	$token    = new Erdo_Draft_Links_Token();
	$admin    = new Erdo_Draft_Links_Admin( $db, $token );
	$frontend = new Erdo_Draft_Links_Frontend( $db, $token );
	$cron     = new Erdo_Draft_Links_Cron( $db );

	$db->maybe_upgrade();
	$admin->register( $loader );
	$frontend->register( $loader );
	$cron->register( $loader );
	$loader->run();
} );
