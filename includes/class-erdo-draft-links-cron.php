<?php
defined( 'ABSPATH' ) || exit;

class Erdo_Draft_Links_Cron {

	const HOOK = 'erdo_draft_link_daily_cleanup';

	private Erdo_Draft_Links_DB $db;

	public function __construct( Erdo_Draft_Links_DB $db ) {
		$this->db = $db;
	}

	public function register( Erdo_Draft_Links_Loader $loader ): void {
		$loader->add_action( self::HOOK, $this, 'run_cleanup' );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	public function run_cleanup(): void {
		// Delete expired links older than 7 days so recent ones stay visible in admin.
		$deleted = $this->db->delete_expired_links( 7 );
		if ( $deleted > 0 ) {
			update_option( 'erdo_draft_link_last_cleanup', array(
				'time'    => current_time( 'mysql' ),
				'deleted' => $deleted,
			) );
		}
	}
}
