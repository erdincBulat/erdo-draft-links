<?php
defined( 'ABSPATH' ) || exit;

class Erdo_Draft_Links_Token {

	/**
	 * Upper bound for the preview cookie lifetime, also used for links that
	 * never expire — the visitor just reopens the link after this.
	 */
	const MAX_COOKIE_LIFETIME = 30 * DAY_IN_SECONDS;

	/**
	 * @return array{raw: string, hash: string, enc: string}
	 */
	public function generate(): array {
		$raw = wp_generate_password( 32, false, false );
		return array(
			'raw'  => $raw,
			'hash' => $this->hash( $raw ),
			'enc'  => $this->encrypt( $raw ),
		);
	}

	/**
	 * Deterministic HMAC of the raw token — the value links are looked up by.
	 */
	public function hash( string $raw ): string {
		return hash_hmac( 'sha256', $raw, AUTH_KEY );
	}

	public function verify( string $raw, string $stored_hash ): bool {
		return hash_equals( $this->hash( $raw ), $stored_hash );
	}

	/**
	 * The raw token is kept only so editors can copy the link again later.
	 * It is stored encrypted (XSalsa20-Poly1305 via libsodium or WP's bundled
	 * sodium_compat), never in plain text, with a key derived from the site's
	 * secret salts — a database dump alone does not reveal working links.
	 */
	public function encrypt( string $raw ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $raw, $nonce, $this->encryption_key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext storage, not obfuscation.
	}

	/**
	 * Returns null when the value is malformed or the salts have changed since
	 * the link was created (in which case its hash no longer matches either).
	 */
	public function decrypt( string $enc ): ?string {
		$decoded = base64_decode( $enc, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext storage, not obfuscation.
		if ( false === $decoded || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}

		$nonce  = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		try {
			$raw = sodium_crypto_secretbox_open( $cipher, $nonce, $this->encryption_key() );
		} catch ( SodiumException $e ) {
			return null;
		}

		return false === $raw ? null : $raw;
	}

	/**
	 * Shareable URL for a link row, or '' if its token can't be recovered.
	 */
	public function link_url( object $link ): string {
		$raw = '' !== (string) $link->token_enc ? $this->decrypt( (string) $link->token_enc ) : null;
		if ( null === $raw ) {
			return '';
		}
		return $this->url_for_token( $raw, (int) $link->post_id );
	}

	/**
	 * `/review/<token>/` with pretty permalinks — independent of the post's own
	 * permalink (which changes when a draft gets its slug) and immune to SEO
	 * plugins that strip unknown query parameters. Plain-permalink sites fall
	 * back to `?erdo_token=` on the post URL, which keeps working everywhere.
	 */
	public function url_for_token( string $raw, int $post_id ): string {
		if ( '' !== (string) get_option( 'permalink_structure' ) ) {
			return home_url( user_trailingslashit( self::review_base() . '/' . $raw ) );
		}
		$permalink = get_permalink( $post_id );
		return $permalink ? add_query_arg( 'erdo_token', $raw, $permalink ) : '';
	}

	public static function review_base(): string {
		/**
		 * Filters the URL segment for pretty draft links (default "review").
		 * Rewrite rules are flushed automatically only on activation/upgrade,
		 * so visit Settings → Permalinks after changing it.
		 *
		 * @param string $base URL segment without slashes.
		 */
		$base = trim( (string) apply_filters( 'erdo_draft_links_review_base', 'review' ), '/' );
		return '' !== $base ? $base : 'review';
	}

	/**
	 * Expiry check against a GMT MySQL datetime.
	 */
	public function is_expired( ?string $expires_at ): bool {
		if ( null === $expires_at ) {
			return false;
		}
		return time() > strtotime( $expires_at . ' UTC' );
	}

	public function compute_expires_at( string $expiry ): ?string {
		$map = array(
			'24h' => DAY_IN_SECONDS,
			'48h' => 2 * DAY_IN_SECONDS,
			'7d'  => WEEK_IN_SECONDS,
			'30d' => MONTH_IN_SECONDS,
		);
		if ( ! isset( $map[ $expiry ] ) ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', time() + $map[ $expiry ] );
	}

	/**
	 * Cookie expiry timestamp: the link's own expiry, capped at
	 * MAX_COOKIE_LIFETIME (which also applies to links without an expiry).
	 */
	public function cookie_expires( ?string $expires_at ): int {
		$cap = time() + self::MAX_COOKIE_LIFETIME;
		if ( null === $expires_at ) {
			return $cap;
		}
		return min( $cap, (int) strtotime( $expires_at . ' UTC' ) );
	}

	public function cookie_value( string $token_hash ): string {
		return hash_hmac( 'sha256', $token_hash, SECURE_AUTH_KEY );
	}

	private function encryption_key(): string {
		return hash_hmac( 'sha256', 'erdo-draft-links-enc', wp_salt( 'secure_auth' ), true );
	}
}
