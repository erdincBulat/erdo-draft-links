<?php
defined( 'ABSPATH' ) || exit;

class Erdo_Draft_Links_Post_Types {

	public static function get_supported(): array {
		$defaults = array( 'post', 'page' );
		return (array) apply_filters( 'erdo_draft_links_supported_post_types', $defaults );
	}
}
