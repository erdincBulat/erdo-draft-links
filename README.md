<p align="center">
  <img src="https://ps.w.org/erdo-draft-links/assets/banner-1544x500.png" alt="Erdo Draft Links" width="100%">
</p>

<h1 align="center">Erdo Draft Links</h1>

<p align="center">
  Share draft posts with anyone via a secure, temporary link — no WordPress login required.
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/erdo-draft-links/"><img src="https://img.shields.io/wordpress/plugin/v/erdo-draft-links.svg" alt="WordPress Plugin Version"></a>
  <a href="https://wordpress.org/plugins/erdo-draft-links/"><img src="https://img.shields.io/wordpress/plugin/dt/erdo-draft-links.svg" alt="WordPress Plugin Downloads"></a>
  <a href="https://wordpress.org/plugins/erdo-draft-links/"><img src="https://img.shields.io/wordpress/plugin/rating/erdo-draft-links.svg" alt="WordPress Plugin Rating"></a>
  <img src="https://img.shields.io/wordpress/v/erdo-draft-links.svg" alt="Tested up to WordPress version">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg" alt="Requires PHP 7.4+">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-GPLv2%2B-blue.svg" alt="License: GPLv2+"></a>
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/erdo-draft-links/">WordPress.org Listing</a> ·
  <a href="https://wordpress.org/plugins/erdo-draft-links/#developers">Changelog</a> ·
  <a href="#installation">Installation</a> ·
  <a href="https://github.com/erdincBulat/erdo-draft-links/issues">Report a Bug</a>
</p>

---

**Erdo Draft Links** lets you generate a secure, token-based URL for any draft, private, or published post or page. Share it with clients, reviewers, or collaborators — they can read the content without needing a WordPress account.

Think of it like Google Docs' "Anyone with the link can view" — but for WordPress.

## Screenshots

| Block Editor sidebar | Classic Editor meta box |
|---|---|
| ![Block Editor sidebar](https://ps.w.org/erdo-draft-links/assets/screenshot-1.png) | ![Classic Editor meta box](https://ps.w.org/erdo-draft-links/assets/screenshot-2.png) |

<p align="center"><img src="https://ps.w.org/erdo-draft-links/assets/screenshot-3.png" alt="Draft post viewed via Erdo Draft Links by a non-logged-in visitor" width="70%"></p>

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

## Who is this for?

- **Freelancers and agencies** — send a client a draft post to review before it's published, without handing out a WordPress login
- **Editorial teams** — let outside reviewers or stakeholders read a draft without granting WordPress access
- **Anyone sharing private or scheduled content** — a scoped, revocable link instead of changing post status or creating a temporary account

## Installation

1. Upload the `erdo-draft-links` folder to `/wp-content/plugins/`, or install directly from **Plugins → Add New** by searching "Erdo Draft Links"
2. Activate through the **Plugins** menu
3. Open any post or page and find the **Erdo Draft Links** panel in the editor sidebar or meta box

Full FAQ and changelog are on the [WordPress.org plugin page](https://wordpress.org/plugins/erdo-draft-links/).

## Try it instantly (no install)

[![Try it in WordPress Playground](https://img.shields.io/badge/WordPress%20Playground-Try%20it%20live-3858e9.svg)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/erdincBulat/erdo-draft-links/master/.wordpress-org/blueprint.json)

Runs entirely in your browser, no server or account needed.

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

## Contributing

Issues and pull requests are welcome — see [CONTRIBUTING.md](.github/CONTRIBUTING.md). This repository mirrors the plugin shipped on WordPress.org; releases are cut from the `Stable tag` in `readme.txt`.

## Security

- Raw token: 32-char alphanumeric via `wp_generate_password( 32, false, false )`.
- Stored hash: `HMAC-SHA256(raw, AUTH_KEY)` — the raw token is never stored.
- Cookie value: a second `HMAC-SHA256` derivation, distinct from both the raw token and its stored hash.
- Preview cookies only ever expand visibility to the single `post_id` they were issued for.

If you find a security issue, please open an issue or contact the author directly rather than disclosing it publicly.

## License

GPLv2 or later — see [LICENSE](LICENSE).
