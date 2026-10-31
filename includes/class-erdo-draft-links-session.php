<?php
defined( 'ABSPATH' ) || exit;

/**
 * The visitor's preview session: the HttpOnly `erdo_draft_link_{id}` cookie
 * set after a valid token was opened. Shared by everything that acts on
 * behalf of a preview visitor (page rendering, feedback, review decisions).
 */
class Erdo_Draft_Links_Session {

	const COOKIE_PREFIX = 'erdo_draft_link_';

	private Erdo_Draft_Links_DB $db;
	private Erdo_Draft_Links_Token $token;

	public function __construct( Erdo_Draft_Links_DB $db, Erdo_Draft_Links_Token $token ) {
		$this->db    = $db;
		$this->token = $token;
	}

	/**
	 * The first cookie that maps to a link which is still active and unexpired.
	 *
	 * @return array{link_id: int, post_id: int, link: object}|null
	 */
	public function current(): ?array {
		foreach ( $_COOKIE as $name => $value ) {
			if ( strpos( (string) $name, self::COOKIE_PREFIX ) !== 0 ) {
				continue;
			}
			$link_id = (int) substr( (string) $name, strlen( self::COOKIE_PREFIX ) );
			if ( $link_id <= 0 ) {
				continue;
			}
			$link = $this->db->get_link_by_id( $link_id );
			if ( ! $link || ! $link->is_active ) {
				continue;
			}
			if ( $this->token->is_expired( $link->expires_at ) ) {
				continue;
			}
			$expected = $this->token->cookie_value( $link->token_hash );
			if ( hash_equals( $expected, sanitize_text_field( wp_unslash( $value ) ) ) ) {
				return array(
					'link_id' => $link_id,
					'post_id' => (int) $link->post_id,
					'link'    => $link,
				);
			}
		}
		return null;
	}

	public function start( object $link ): void {
		setcookie(
			self::COOKIE_PREFIX . (int) $link->id,
			$this->token->cookie_value( $link->token_hash ),
			array(
				'expires'  => $this->token->cookie_expires( $link->expires_at ),
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}
