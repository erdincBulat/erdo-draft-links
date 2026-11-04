<?php
defined( 'ABSPATH' ) || exit;

/**
 * Small integrations with plugins that otherwise get in the way of a
 * review link:
 *
 * - Page caches that serve files before WordPress runs (LiteSpeed Cache,
 *   WP Rocket, WP Super Cache) are told to skip visitors who carry a review
 *   cookie, so a reviewer
 *   never gets the cached public copy of a page — or a 404 for a draft.
 *   Responses we send are already marked uncacheable (DONOTCACHEPAGE,
 *   no-store, X-LiteSpeed-Cache-Control), which covers saving.
 * - Yoast SEO's "remove unregistered URL parameters" would redirect our
 *   ?erdo_token= / ?erdo_preview= URLs away; both are allowed.
 */
class Erdo_Draft_Links_Compat {

	/** Bumped when the cookie rules change, so they are re-applied once. */
	const RULES_VERSION = 1;
	const RULES_OPTION  = 'erdo_draft_link_cache_rules';

	public function register( Erdo_Draft_Links_Loader $loader ): void {
		$loader->add_filter( 'rocket_cache_reject_cookies', $this, 'reject_cookie' );
		$loader->add_filter( 'Yoast\WP\SEO\allowlist_permalink_vars', $this, 'allow_query_vars' );
		$loader->add_action( 'admin_init', $this, 'maybe_apply_cache_rules' );
	}

	/**
	 * WP Rocket: entries are joined into a regex matched against cookie names.
	 *
	 * @param string[] $cookies
	 * @return string[]
	 */
	public function reject_cookie( $cookies ): array {
		$cookies   = (array) $cookies;
		$cookies[] = Erdo_Draft_Links_Session::COOKIE_PREFIX;
		return $cookies;
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function allow_query_vars( $vars ): array {
		$vars   = (array) $vars;
		$vars[] = 'erdo_token';
		$vars[] = Erdo_Draft_Links_Frontend::PREVIEW_ARG;
		return $vars;
	}

	/**
	 * WP Rocket and WP Super Cache keep their rules in generated config files,
	 * so the cookie only takes effect once those are rewritten. Do that once
	 * per rules version, and again whenever either plugin is (re)activated.
	 */
	public function maybe_apply_cache_rules(): void {
		$applied = (int) get_option( self::RULES_OPTION, 0 );
		$active  = $this->active_caches();
		if ( $applied >= self::RULES_VERSION && $active === (array) get_option( self::RULES_OPTION . '_for', array() ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( in_array( 'wp-rocket', $active, true ) ) {
			if ( function_exists( 'rocket_generate_config_file' ) ) {
				rocket_generate_config_file();
			}
			if ( function_exists( 'flush_rocket_htaccess' ) ) {
				flush_rocket_htaccess();
			}
		}
		// LiteSpeed's server cache answers before PHP runs, so a reviewer going
		// back to the page's normal address got the cached public copy. Its
		// "Do Not Cache Cookies" list becomes a rewrite rule; adding our prefix
		// there (visible on its Cache → Excludes screen) fixes that.
		if ( in_array( 'litespeed', $active, true ) ) {
			$conf     = \LiteSpeed\Conf::cls();
			$excludes = (array) $conf->conf( 'cache-exc_cookies' );
			if ( ! in_array( Erdo_Draft_Links_Session::COOKIE_PREFIX, $excludes, true ) ) {
				$excludes[] = Erdo_Draft_Links_Session::COOKIE_PREFIX;
				$conf->update_confs( array( 'cache-exc_cookies' => $excludes ) );
			}
		}
		if ( in_array( 'wp-super-cache', $active, true ) && function_exists( 'wpsc_add_cookie' ) ) {
			wpsc_add_cookie( Erdo_Draft_Links_Session::COOKIE_PREFIX );
		}

		update_option( self::RULES_OPTION, self::RULES_VERSION, false );
		update_option( self::RULES_OPTION . '_for', $active, false );
	}

	/**
	 * @return string[] Page caches we have rules for that are running.
	 */
	private function active_caches(): array {
		$active = array();
		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			$active[] = 'wp-rocket';
		}
		if ( function_exists( 'wpsc_add_cookie' ) ) {
			$active[] = 'wp-super-cache';
		}
		if ( defined( 'LSCWP_V' ) && class_exists( 'LiteSpeed\Conf' ) && method_exists( 'LiteSpeed\Conf', 'cls' ) ) {
			$active[] = 'litespeed';
		}
		return $active;
	}
}
