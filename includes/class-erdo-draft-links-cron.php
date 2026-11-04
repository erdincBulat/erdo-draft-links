<?php
defined( 'ABSPATH' ) || exit;

class Erdo_Draft_Links_Cron {

	const HOOK        = 'erdo_draft_link_daily_cleanup';
	const EXPIRY_HOOK = 'erdo_draft_link_expiry_check';

	private Erdo_Draft_Links_DB $db;
	private Erdo_Draft_Links_Notifications $notifications;

	public function __construct( Erdo_Draft_Links_DB $db, Erdo_Draft_Links_Notifications $notifications ) {
		$this->db            = $db;
		$this->notifications = $notifications;
	}

	public function register( Erdo_Draft_Links_Loader $loader ): void {
		$loader->add_action( self::HOOK, $this, 'run_cleanup' );
		$loader->add_action( self::EXPIRY_HOOK, $this, 'run_expiry_check' );
		// Plugin updates don't re-run the activation hook, so make sure events
		// added in later versions get scheduled too. Cheap: one cached lookup.
		$loader->add_action( 'init', self::class, 'schedule' );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
		if ( ! wp_next_scheduled( self::EXPIRY_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::EXPIRY_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::EXPIRY_HOOK );
	}

	public function run_cleanup(): void {
		// Delete expired links older than the configured days so recent ones stay visible in admin.
		$deleted = $this->db->delete_expired_links( (int) Erdo_Draft_Links_Settings::get( 'cleanup_days' ) );
		if ( $deleted > 0 ) {
			update_option( 'erdo_draft_link_last_cleanup', array(
				'time'    => current_time( 'mysql' ),
				'deleted' => $deleted,
			) );
		}
	}

	/**
	 * Emails the link's people once, about a day before it stops working, so
	 * they can extend it if the client hasn't finished. Links that were already
	 * approved are skipped.
	 */
	public function run_expiry_check(): void {
		if ( ! Erdo_Draft_Links_Settings::notify_enabled( 'expiring' ) ) {
			return;
		}
		foreach ( $this->db->get_links_expiring_soon() as $link ) {
			// Mark first: a mail failure must not turn into an hourly email loop.
			$this->db->mark_expiry_notified( $link );
			if ( get_post( (int) $link->post_id ) ) {
				$this->notifications->expiring( $link );
			}
		}
	}
}
