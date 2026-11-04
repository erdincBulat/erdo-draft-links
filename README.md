<p align="center">
  <img src="https://ps.w.org/erdo-draft-links/assets/banner-1544x500.png" alt="Erdo Draft Links" width="100%">
</p>

<h1 align="center">Erdo Draft Links</h1>

<p align="center">
  Send clients a secure, expiring link to a draft. They comment, pin notes and approve or request changes — no login needed.
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/erdo-draft-links/"><img src="https://img.shields.io/wordpress/plugin/v/erdo-draft-links.svg" alt="WordPress Plugin Version"></a>
  <a href="https://wordpress.org/plugins/erdo-draft-links/"><img src="https://img.shields.io/wordpress/plugin/dt/erdo-draft-links.svg" alt="WordPress Plugin Downloads"></a>
  <a href="https://wordpress.org/plugins/erdo-draft-links/"><img src="https://img.shields.io/wordpress/plugin/rating/erdo-draft-links.svg" alt="WordPress Plugin Rating"></a>
  <img src="https://img.shields.io/wordpress/v/erdo-draft-links.svg" alt="Tested up to WordPress version">
  <img src="https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg" alt="Requires PHP 8.0+">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-GPLv2%2B-blue.svg" alt="License: GPLv2+"></a>
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/erdo-draft-links/">WordPress.org Listing</a> ·
  <a href="https://wordpress.org/plugins/erdo-draft-links/#developers">Changelog</a> ·
  <a href="#installation">Installation</a> ·
  <a href="https://github.com/erdincBulat/erdo-draft-links/issues">Report a Bug</a>
</p>

---

**Erdo Draft Links** turns "can you take a look at this draft?" into a proper review. Create a private link from the post editor and send it to your client. They open the draft without a WordPress account, leave comments or notes pinned to the exact part of the page, and click **Approve** or **Request changes**. You see everything in the editor, reply from there, and get an email at each step.

Built for freelancers and agencies who share drafts with clients, and for teams who need a sign-off before publishing.

## Screenshots

| Share for review panel | What your client sees |
|---|---|
| ![Share for review panel in the block editor](https://ps.w.org/erdo-draft-links/assets/screenshot-1.png) | ![Review bar with comments and a note pinned to the page](https://ps.w.org/erdo-draft-links/assets/screenshot-2.png) |

| Client feedback sidebar with AI to-do list | Feedback inbox |
|---|---|
| ![Client feedback sidebar in the editor](https://ps.w.org/erdo-draft-links/assets/screenshot-3.png) | ![Feedback inbox under Draft Links](https://ps.w.org/erdo-draft-links/assets/screenshot-4.png) |

## How it works

1. Open a draft (or any post or page) and click **Create share link** in the **Share for review** panel. The link is copied for you.
2. Send it to your client. They see the page as it will look, with a small review bar at the bottom.
3. They comment, pin notes to parts of the page, and approve or request changes.
4. Read and answer their feedback in the editor's **Client feedback** sidebar. They get your reply by email if they left their address.

## Features

**For your client**

- No account, no login, no plugin to install
- Review bar with **Comments**, **Note on page**, **Approve** and **Request changes**
- Notes pinned to the exact element they clicked, threaded replies, optional email when you answer
- Clear pages for expired, turned-off or password-protected links, with an **Ask for a new link** button
- Works on phones, with keyboard and screen readers; isolated from your theme's styles (Shadow DOM)

**For you**

- One-click **Create share link** in the block editor; **Link options** for label, expiry, password and open limit
- **Several links per post**, each with its own label, expiry, review decision and feedback
- **Client feedback** sidebar: reply in place, mark as resolved, see "edited since approved"
- **Draft Links** menu with a searchable **Links** table and a **Feedback** inbox
- **Review** column in Posts/Pages lists and a **Client feedback** dashboard widget
- Email notifications: first open, new feedback, approval or change request, request for a new link, and a reminder a day before an unapproved link expires
- Optionally turn links off when the post is published
- Classic Editor meta box and **WP-CLI**

**Optional AI helpers (bring your own key)**

On WordPress 7.0+, connect your own provider under **Settings → Connectors** to get: feedback → to-do list, reply suggestions, comment translation, a share message, and a "what changed" note for returning reviewers. Nothing runs until you click, the plugin stores no keys, and reviewer emails/IPs are never sent.

**Works with** Elementor and other page builders, LiteSpeed Cache, WP Rocket, WP Super Cache, Yoast SEO, and the WordPress **Abilities API** (WP 6.9+) for AI agents and MCP.

## Installation

1. Install from **Plugins → Add New** by searching "Erdo Draft Links", or upload the `erdo-draft-links` folder to `/wp-content/plugins/`
2. Activate it
3. Open a draft, find **Share for review** in the editor sidebar, and click **Create share link**

Settings are under **Draft Links → Settings**. Full FAQ and changelog are on the [WordPress.org plugin page](https://wordpress.org/plugins/erdo-draft-links/).

## Try it instantly (no install)

[![Try it in WordPress Playground](https://img.shields.io/badge/WordPress%20Playground-Try%20it%20live-3858e9.svg)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/erdincBulat/erdo-draft-links/master/.wordpress-org/blueprint.json)

Runs entirely in your browser, no server or account needed.

## Developer notes

Filters:

| Filter | Purpose |
|---|---|
| `erdo_draft_links_supported_post_types` | Post types links can be created for (overrides the Settings page) |
| `erdo_draft_links_notification_recipients` | Who gets emails about a link |
| `erdo_draft_links_review_base` | The `/review/` part of share URLs |
| `erdo_draft_links_is_automated_request` | Treat a request as a link-preview bot |
| `erdo_draft_links_ai_enabled` | Offer the AI helpers or not |
| `erdo_draft_links_ai_models` | Preferred AI models, in order |

```php
add_filter( 'erdo_draft_links_supported_post_types', function( $types ) {
    $types[] = 'product';
    return $types;
} );
```

### WP-CLI

```
wp erdo-draft-links generate <post_id> [--label=<label>] [--expiry=<24h|48h|7d|never>]
wp erdo-draft-links revoke <link_id>
wp erdo-draft-links list [--post_id=<id>] [--status=<all|active|expired|revoked>] [--format=<table|csv|json|yaml|count>]
```

### Building the editor scripts

`assets/js/build/{classic,sidebar}.js` are compiled output. If you edit `assets/js/src/`, run `npm install && npm run build` (uses `@wordpress/scripts`) to regenerate them and their `*.asset.php` manifests before committing.

See [`readme.txt`](readme.txt) for the full WordPress.org description, FAQ and changelog.

## Contributing

Issues and pull requests are welcome — see [CONTRIBUTING.md](.github/CONTRIBUTING.md). This repository mirrors the plugin shipped on WordPress.org; releases are cut from the `Stable tag` in `readme.txt`.

## Security

- Tokens are 32 random alphanumeric characters (`wp_generate_password( 32, false, false )`).
- The database keeps `HMAC-SHA256(token, AUTH_KEY)` for lookup and a libsodium-encrypted copy so editors can copy a link again; a database dump alone doesn't reveal working links.
- The token in the URL is swapped for an HttpOnly cookie straight away, so it doesn't stay in browser history.
- A preview cookie only ever opens the single post it was issued for.
- Review pages are sent with `noindex`, `no-store` and `Referrer-Policy: no-referrer`.

If you find a security issue, please contact the author directly rather than opening a public issue.

## License

GPLv2 or later — see [LICENSE](LICENSE).
