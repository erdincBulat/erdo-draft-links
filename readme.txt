=== Erdo Draft Links – Share Drafts for Client Review & Approval ===
Contributors:      erdincbulat
Tags:              client approval, share draft, draft preview, feedback, no login
Requires at least: 6.6
Tested up to:      7.1
Requires PHP:      8.0
Stable tag:        2.0.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Send clients a secure, expiring link to a draft. They comment, pin notes and approve or request changes — no login needed.

== Description ==

**Erdo Draft Links** turns "can you take a look at this draft?" into a proper review. Create a private link from the post editor and send it to your client. They open the draft without a WordPress account, leave comments or notes pinned to the exact part of the page, and click **Approve** or **Request changes**. You see everything in the editor, reply from there, and get an email at each step.

Built for freelancers and agencies who share drafts with clients, and for teams who need a sign-off before publishing.

= Why not a staging site or a temporary account? =

WordPress' own preview only works for logged-in users, so the usual workarounds are a staging site, a temporary user account for the client, or screenshots in an email. A staging site is a lot of setup for one page, a temporary account gives the client access to your dashboard (and someone has to delete it later), and screenshots can't be clicked or commented on. With Erdo Draft Links you send a WordPress draft preview without login: one secure link that opens only that post, expires on its own, and collects the client's feedback and approval in one place.

= How it works =

1. Open a draft (or any post or page) and click **Create share link** in the **Share for review** panel. The link is copied for you.
2. Send it to your client. They see the page as it will look, with a small review bar at the bottom.
3. They comment, pin notes to parts of the page, and approve or request changes.
4. Read and answer their feedback in the editor's **Client feedback** sidebar. They get your reply by email if they left their address.

= For your client =

* No account, no login, no plugin to install: the link just opens the draft.
* A review bar at the bottom of the page: **Comments**, **Note on page**, **Approve**, **Request changes**.
* **Notes pinned to the page**: click any part of the page to leave a note right there. Notes follow the element when the page is resized.
* Threaded conversations: they see your replies and can answer back.
* Optional email to hear about replies. Name and email are remembered in their browser.
* A "What's new since your last review" note you can leave for returning reviewers.
* Clear pages when a link has expired, was turned off or needs a password, with a button to **ask for a new link**.
* Works on phones, with keyboard and screen readers, and is isolated from your theme's styles.

= For you =

* **Share for review** panel in the block editor: one click creates and copies a link. **Link options** adds a label ("Client A"), expiry (24 hours to 30 days, never, or an exact date), a **password** and an **open limit**.
* **Several links per post**, each with its own label, expiry, review decision and feedback.
* **Client feedback** sidebar: every comment, note and decision, reply in place, mark as resolved.
* **Review status at a glance**: approved, changes requested, or "edited since approved" when you change the post after a sign-off.
* **Draft Links** menu with a searchable **Links** table (copy, extend, turn off) and a **Feedback** inbox with inline replies.
* A **Review** column in your Posts and Pages lists and a **Client feedback** dashboard widget.
* **Email notifications** to the link's creator and the post's author: first open, new feedback, approval or change request, a request for a new link, and a reminder a day before an unapproved link expires. Choose which ones on the Settings page and add extra recipients.
* Optionally turn links off automatically when the post is published.
* Classic Editor support with a meta box.
* **WP-CLI**: `wp erdo-draft-links generate|revoke|list`.

= Optional AI helpers (your own API key) =

On WordPress 7.0 and newer, connect your own AI provider (OpenAI, Anthropic, Google and others) under **Settings → Connectors** and the editor offers five helpers:

* **Summarise feedback into a to-do list**, with one click to resolve the comments behind each task.
* **Suggest a reply** to a client's comment, which you edit before sending.
* **Translate** a comment into your language.
* **Write the message** to send along with a link.
* **Write a "what changed" note** for reviewers from the difference to the version they reviewed.

They only run when you click them, on your own provider account. Draft Links never stores your API key and has no server of its own. Reviewer emails and IP addresses are never sent, and names are replaced with "Reviewer". You can switch the helpers off on the Settings page.

= Security and privacy =

* Links are 32 random characters. The database keeps only a hash to look them up, plus an encrypted copy so you can copy a link again later. A database dump alone doesn't reveal working links.
* The token in the URL is exchanged for a cookie right away, so it doesn't stay in the browser history.
* A link only opens **its own post**. It can't be used to see other drafts.
* Review pages are sent with `noindex` and `no-store` headers, so search engines and caches don't keep a copy.
* Link previews in Slack, WhatsApp, Teams, iMessage and email scanners don't count as opens.
* Authors and Contributors only see links and feedback for their own posts.
* Each reviewer only sees the conversation on their own link.
* Suggested privacy policy text, plus support for WordPress' **Export** and **Erase Personal Data** tools.
* No tracking, no external calls (except the AI helpers, if you use them).

= Works with =

* Page builders that check edit permissions to render, such as Elementor.
* Page caches: LiteSpeed Cache, WP Rocket and WP Super Cache are set up automatically to skip reviewers. Other caches respect the `DONOTCACHEPAGE` constant and the no-store headers.
* Yoast SEO's "remove unregistered URL parameters" option.
* AI agents and MCP through the WordPress **Abilities API** (WP 6.9+): create, list and turn off links, read feedback and the review status.

= Developer notes =

Filters:

* `erdo_draft_links_supported_post_types` — post types links can be created for (overrides the Settings page).
* `erdo_draft_links_notification_recipients` — who gets emails about a link.
* `erdo_draft_links_review_base` — the `/review/` part of share URLs.
* `erdo_draft_links_is_automated_request` — treat a request as a link preview bot.
* `erdo_draft_links_ai_enabled` — offer the AI helpers or not.
* `erdo_draft_links_ai_models` — preferred AI models, in order.

The full source, including the build setup for the editor scripts, is in `assets/js/src/` and on [GitHub](https://github.com/erdincBulat/erdo-draft-links).

== Installation ==

1. Install the plugin from **Plugins → Add New** and activate it.
2. Open a draft and find the **Share for review** panel in the editor's sidebar (Post tab).
3. Click **Create share link** and send the copied link to your client.

Settings are under **Draft Links → Settings**.

== Frequently Asked Questions ==

= Does my client need an account? =

No. Anyone with the link can open the draft and leave feedback, without logging in.

= How do I share a WordPress draft with a client without giving them a login? =

Open the draft, click **Create share link** in the **Share for review** panel and send the copied link to your client by email or chat. They can preview the draft without logging in, and the link only opens that one post.

= How is this different from the built-in WordPress preview? =

The built-in **Preview** button only works while you are logged in; anyone else gets a "page not found". A share link works for people without an account, can expire, be password-protected or turned off, and lets the reviewer comment and approve.

= Do I still need a staging site to show clients unpublished pages? =

Not for reviewing content. Send a share link to the draft page instead. A staging site is still the right tool for testing theme or plugin changes across the whole site.

= Can I share a post that is already published? =

Yes. The review bar works the same way on published posts and pages, so clients can approve or comment on live content too. (Sharing unpublished changes to a published post is planned for a later version.)

= I'm coming from Public Post Preview. What's different? =

Public Post Preview gives one link per post that shows the draft. Erdo Draft Links also lets the reviewer comment, pin notes to the page and approve or request changes, and you can have several labeled links per post, each with its own expiry, password and open limit. Links keep working after you edit the post, and you get an email when something happens. You can use both plugins side by side while you switch.

= What happens when a link expires? =

The reviewer sees a page that says the link has expired, with a button to ask you for a new one. The post itself is not changed. A day before an unapproved link expires, you get a reminder email so you can extend it.

= Can I protect a link with a password? =

Yes. Under **Link options**, set a password and send it to your client separately. You can also limit how many times a link can be opened.

= How do I use the AI helpers? =

On WordPress 7.0 or newer, go to **Settings → Connectors** and add an API key for an AI provider. The helpers then appear in the editor. They use your provider account, so you pay your provider directly for what you use. Without a connected provider, nothing is sent anywhere.

= Does it work with caching plugins? =

Yes. Review pages are never cached, and for LiteSpeed Cache, WP Rocket and WP Super Cache the plugin also makes sure a reviewer is never served a cached public copy. If you use another page cache or a CDN, exclude cookies that start with `erdo_draft_link_` from caching.

= Does it work with custom post types? =

Yes. Posts and pages are enabled by default. Turn on other public post types under **Draft Links → Settings**, or use the `erdo_draft_links_supported_post_types` filter.

= Can I manage links from the command line? =

Yes: `wp erdo-draft-links generate <post_id> [--label=<label>] [--expiry=<24h|48h|7d|never>]`, `wp erdo-draft-links revoke <link_id>` and `wp erdo-draft-links list [--post_id=<id>] [--status=<all|active|expired|revoked>] [--format=<table|csv|json|yaml|count>]`.

= What happens to the data when I delete the plugin? =

By default, deleting the plugin removes its tables, settings and scheduled events. Untick "Remove all links, feedback and settings" on the Settings page to keep them for a reinstall.

= What if I change my site's secret keys? =

Links are tied to the secret keys in `wp-config.php`. If you change them, existing links stop working and need to be created again.

== Screenshots ==

1. Share for review panel in the block editor: create, copy and manage links.
2. The review bar your client sees, with comments and notes pinned to the page.
3. Client feedback sidebar in the editor, with the AI to-do list.
4. The Feedback inbox under Draft Links.
5. The Links table under Draft Links.
6. The page a reviewer sees when a link has expired.

== Changelog ==

= 2.0.0 =
Erdo Draft Links is now a client review and approval tool.

**New**

* Review bar for visitors: comments, notes pinned to parts of the page, threaded replies, and Approve / Request changes.
* Review status per post and per link, marked "edited since approved" when the post changes after a decision.
* Share URLs are now `/review/<token>/` on sites with pretty permalinks. Old `?erdo_token=` links keep working.
* Optional password and open limit per link.
* Clear pages for expired, turned-off and used-up links, a password prompt, and an "ask for a new link" button.
* Block editor redesign: Share for review panel with one-click create & copy, Client feedback sidebar, "Copy share link" in the Preview menu (WP 6.7+), and an offer to turn links off after publishing.
* Draft Links admin menu with Links and Feedback screens and a Settings page.
* Review column in the Posts and Pages lists, and a Client feedback dashboard widget.
* Email notifications go to the link's creator and the post's author, with a choice of events and extra recipients. New emails: review decisions, requests for a new link, replies to reviewers, and a reminder a day before an unapproved link expires.
* Optional AI helpers using your own API key (WordPress 7.0+ Connectors): feedback to-do list, reply suggestions, translation, share message and "what changed" note.
* Abilities API support (WordPress 6.9+) for AI agents and MCP.
* Privacy: suggested policy text, personal data export and erase.
* Compatibility with LiteSpeed Cache, WP Rocket, WP Super Cache and Yoast SEO.
* Turkish translation updated and now loaded from the plugin when no language pack is installed.

**Security**

* Draft link tokens are no longer stored in plain text. Links are looked up by an HMAC hash, and the token is kept encrypted so it can still be copied. Existing links are migrated automatically and keep working.
* Authors and Contributors now only see and manage draft links and feedback for their own posts. Every row action checks permission for that specific post.
* Visitors now only see feedback left through their own link, not feedback from other people the same post was shared with.
* Revoking or deleting a link now takes effect immediately, even with a persistent object cache.
* Preview pages and token URLs are sent with `noindex`, `no-store` and `Referrer-Policy: no-referrer` headers.

**Fixes**

* Link previews in Slack, WhatsApp, Teams, iMessage and email link scanners no longer count as views or trigger the "first opened" email.
* Revoked links now show "turned off" instead of a generic "invalid" message.
* A reviewer who received links to several drafts can now open all of them in the same browser; comments and decisions go to the link the page was opened with.
* The preview cookie now lasts until the link expires (at most 30 days) instead of 1 hour.
* Dates are stored and compared in UTC everywhere; expiry times in the editor are shown in the site's timezone.
* Draft links can no longer be generated for unsaved (auto-draft) or trashed posts, or for post types that are not enabled.
* LiteSpeed's server cache no longer keeps serving a redirect for a revoked link.
* Uninstalling now removes the feedback table, all plugin options and the scheduled events.
* Wording: leftover "ghost link" texts renamed; the editor uses a link icon.
* Requirements corrected to WordPress 6.6+ and PHP 8.0+ (what the code already needed).

= 1.2.0 =
* New: Generate multiple labeled links per post (e.g. "Client A", "Designer") — each with its own expiry, view count, and independent revoke.
* New: Reply to visitor feedback directly from the Feedback tab; the visitor sees your reply the next time they open the preview on that page.
* New: Custom date & time expiry option, in addition to the 24 hour / 48 hour / 7 day / never presets.
* New: The site admin gets an email the first time a draft link is opened.
* New: A Settings tab (Tools → Erdo Draft Links → Settings) lets administrators enable other public post types without code.
* New: WP-CLI support — `wp erdo-draft-links generate|revoke|list`.
* Tested up to WordPress 7.1.

= 1.1.0 =
* New: Visitors can leave name + feedback on a shared draft preview; submissions appear under the new "Feedback" tab in the Erdo Draft Links admin page and are emailed to the site admin.
* New: The Links Manager table now has a "Link" column with a hidden-by-default reveal button and one-click copy for the generated draft link.
* Fix: Page builder content (Elementor and others) now renders correctly on shared draft preview links for non-logged-in visitors.
* Fix: The "View Link" row action was renamed to "View Page" (it opens the post, not the draft link) to avoid confusion with the new "Link" column.
* Fix: The Block Editor sidebar no longer shows a stale draft link after it has been revoked or regenerated elsewhere.
* Fix: Caching plugins (e.g. LiteSpeed Cache) no longer serve stale draft previews or feedback status to visitors after a link is revoked or feedback status changes.
* Fix: The feedback widget's appearance (colors, sizes, fonts, spacing) is now fully isolated from the active theme so it looks the same on every site.

= 1.0.1 =
* Fix: Replace deprecated `current_time('timestamp')` with `time()` for correct UTC handling.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.0.0 =
Big update: clients can now comment, pin notes and approve drafts, plus a new editor panel, admin screens, emails and optional AI helpers. Also a security update (encrypted tokens, per-author access). Existing links keep working. Requires WordPress 6.6+ and PHP 8.0+.

= 1.2.0 =
New: multiple labeled links per post, admin replies to feedback, custom expiry date/time, a first-view email notification, a Settings tab for post type support, and WP-CLI commands. Database tables upgrade automatically on activation — no manual action needed.

= 1.1.0 =
New: visitors can leave feedback on draft previews, and the Links Manager has a one-click copy for draft links. Fix: page builder content now renders correctly on preview links, the sidebar no longer shows stale links, and the feedback widget look is now consistent across themes.

= 1.0.1 =
Minor code quality fix; no functional changes.

= 1.0.0 =
Initial release.
