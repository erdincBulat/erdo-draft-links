<?php
defined( 'ABSPATH' ) || exit;

class Erdo_Draft_Links_Token {

	public function generate(): array {
		$raw  = wp_generate_password( 32, false, false );
		$hash = $this->hash( $raw );
		return array( 'raw' => $raw, 'hash' => $hash );
	}

	public function verify( string $raw, string $stored_hash ): bool {
		return hash_equals( $this->hash( $raw ), $stored_hash );
	}

	public function is_expired( ?string $expires_at ): bool {
		if ( null === $expires_at ) {
			return false;
		}
		return time() > strtotime( $expires_at );
	}

	public function cookie_value( string $token_hash ): string {
		return hash_hmac( 'sha256', $token_hash, SECURE_AUTH_KEY );
	}

	private function hash( string $raw ): string {
		return hash_hmac( 'sha256', $raw, AUTH_KEY );
	}
}
