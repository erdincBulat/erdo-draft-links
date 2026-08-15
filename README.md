# Erdo Draft Links

Share draft posts with anyone via a secure, temporary link — no WordPress login required.

[![WordPress Plugin](https://img.shields.io/badge/WordPress-plugin-21759b)](https://wordpress.org/plugins/erdo-draft-links/)
[![License: GPLv2+](https://img.shields.io/badge/license-GPLv2%2B-blue)](https://www.gnu.org/licenses/gpl-2.0.html)

**Erdo Draft Links** lets you generate a secure, token-based URL for any draft, private, or published post or page. Share it with clients, reviewers, or collaborators — they can read the content without needing a WordPress account.

Think of it like Google Docs' "Anyone with the link can view" — but for WordPress.

Also available on [WordPress.org](https://wordpress.org/plugins/erdo-draft-links/).

## How it works

1. Open any post or page in the editor (Block Editor or Classic Editor).
2. Click **"Generate Draft Link"** in the sidebar panel or meta box.
3. Choose an expiry: 24 hours, 48 hours, 7 days, or never.
4. Share the link. Recipients can view the content — no login needed.

## Features

- Works with both the **Block Editor (Gutenberg)** and the **Classic Editor**
- Supports **posts**, **pages**, and any custom post type via a filter
- Secure **32-character cryptographic tokens** — brute-force resistant
- Configurable expiry: 24 hours, 48 hours, 7 days, or no expiry
- **View count** tracking per link
- Visitors can leave **name + feedback** on the preview — collected in a "Feedback" tab in the admin and emailed to you
- **Revoke or regenerate** any link at any time
- Tokens are stored **hashed** in the database — raw tokens are never stored after the redirect
- Two-step flow: token URL → cookie → clean permalink (token never appears in browser history)
- No external API calls, no phone-home, no subscriptions
- Translation-ready (English default, Turkish included)

## Installation

1. Upload the `erdo-draft-links` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Open any post or page and find the **Erdo Draft Links** panel in the editor sidebar or meta box.

## Developer notes

Add support for custom post types using the `erdo_draft_links_supported_post_types` filter:

```php
add_filter( 'erdo_draft_links_supported_post_types', function( $types ) {
    $types[] = 'product';
    return $types;
} );
```

There is no build tooling committed to this repo (no `package.json`/lockfile). `assets/js/build/{classic,sidebar}.js` are pre-compiled output committed directly; the sources in `assets/js/src/` imply a `@wordpress/scripts` (`wp-scripts`) build. If you edit `assets/js/src/`, regenerate the corresponding `assets/js/build/*.js` and `*.asset.php` by hand or via an external `wp-scripts build` environment.

See [`readme.txt`](readme.txt) for the full WordPress.org-facing description, FAQ, and changelog.

## Security

- Raw token: 32-char alphanumeric via `wp_generate_password( 32, false, false )`.
- Stored hash: `HMAC-SHA256(raw, AUTH_KEY)` — the raw token is never stored.
- Cookie value: a second `HMAC-SHA256` derivation, distinct from both the raw token and its stored hash.
- Preview cookies only ever expand visibility to the single `post_id` they were issued for.

## License

GPLv2 or later — see [LICENSE](LICENSE).
