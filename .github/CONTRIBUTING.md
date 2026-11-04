# Contributing to Erdo Draft Links

Thanks for taking the time to contribute! This repository mirrors the plugin distributed on [WordPress.org](https://wordpress.org/plugins/erdo-draft-links/).

## Reporting bugs

Open an [issue](https://github.com/erdincBulat/erdo-draft-links/issues/new/choose) with:

- WordPress version, PHP version, and the plugin version (`Version:` header in `erdo-draft-links.php`)
- Steps to reproduce
- What you expected vs. what happened
- Any relevant errors from the browser console or `debug.log`

## Suggesting features

Open a feature request issue describing the problem you're trying to solve (not just the solution) — it helps evaluate whether it fits the plugin's scope.

## Submitting a pull request

1. Fork the repo and create a branch from `master`
2. Keep the change focused — one bug fix or one feature per PR
3. Match the existing code style (see below) and the patterns already used in the file you're editing
4. Update `readme.txt` (WordPress.org changelog) if the change is user-facing
5. Open the PR against `master` with a clear description of what changed and why

## Code conventions

This is a dependency-free PHP codebase (no Composer) with a small `@wordpress/scripts` build for the Block Editor sidebar and Classic Editor JS.

- **PHP**: follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/). New classes go in `includes/` as `Erdo_Draft_Links_Foo_Bar` → `includes/class-erdo-draft-links-foo-bar.php` (autoloaded by naming convention).
- **Security**: any new endpoint must follow the patterns already in use — `hash_equals()` for token comparisons, `check_admin_referer()`/capability checks (`current_user_can( 'edit_post', $post_id )`), `$wpdb->prepare()` for all raw queries, and `sanitize_text_field( wp_unslash( ... ) )` (or a more specific sanitizer) on all superglobal access.
- **Hooks**: register actions/filters inside a class's `register()` method against the shared `Erdo_Draft_Links_Loader`, not with a top-level `add_action()` call.
- **JS/CSS**: `assets/js/src/` (Block Editor sidebar + Classic Editor) is built via `npm install && npm run build`, which regenerates `assets/js/build/*.js` and the matching `*.asset.php` — editing `src/` alone does not change plugin behavior, always run the build before committing. `assets/js/admin-list.js` and `assets/js/review-bar.js` are plain, unbuilt JS edited directly.

## Security issues

Please do not open a public issue for a security vulnerability. Instead, contact the author directly through [erdincbulat.com](https://erdincbulat.com).
