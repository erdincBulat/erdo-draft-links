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
	 * Every preview session in this browser whose link is still active and
	 * unexpired. A reviewer can hold several (one per link they opened), so
	 * callers pick the one that matches the post or nonce they act on.
	 *
	 * @return array<int, array{link_id: int, post_id: int, link: object}>
	 */
	public function all(): array {
		$sessions = array();
		foreach ( $_COOKIE as $name => $value ) {
			if ( strpos( (string) $name, self::COOKIE_PREFIX ) !== 0 ) {
				continue;
			}
			$link_id = (int) substr( (string) $name, strlen( self::COOKIE_PREFIX ) );
			if ( $link_id <= 0 || isset( $sessions[ $link_id ] ) || ! is_string( $value ) ) {
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
				$sessions[ $link_id ] = array(
					'link_id' => $link_id,
					'post_id' => (int) $link->post_id,
					'link'    => $link,
				);
			}
		}
		return array_values( $sessions );
	}

	/**
	 * The session for a visitor request that carries a nonce made for
	 * `erdo_draft_feedback_{link_id}`: the nonce says which link the page
	 * was rendered for, so a reviewer with several links acts on the right one.
	 *
	 * @return array{link_id: int, post_id: int, link: object}|null
	 */
	public function for_nonce( string $nonce ): ?array {
		if ( '' === $nonce ) {
			return null;
		}
		foreach ( $this->all() as $session ) {
			if ( wp_verify_nonce( $nonce, 'erdo_draft_feedback_' . $session['link_id'] ) ) {
				return $session;
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
