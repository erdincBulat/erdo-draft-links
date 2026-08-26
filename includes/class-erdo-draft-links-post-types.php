<?php
defined( 'ABSPATH' ) || exit;

class Erdo_Draft_Links_Post_Types {

	const OPTION_KEY = 'erdo_draft_link_supported_post_types';

	public static function get_supported(): array {
		$saved    = get_option( self::OPTION_KEY );
		$defaults = is_array( $saved ) ? $saved : array( 'post', 'page' );
		return (array) apply_filters( 'erdo_draft_links_supported_post_types', $defaults );
	}

	/**
	 * Public, non-attachment post types the Settings tab lets an admin toggle.
	 *
	 * @return WP_Post_Type[]
	 */
	public static function get_selectable(): array {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );
		return $post_types;
	}
}
