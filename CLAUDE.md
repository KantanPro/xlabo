# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**xLabo** — a WordPress plugin that automatically shares a post to X (formerly Twitter) when it's published. GitHub: `KantanPro/xlabo`. Standalone product — no relation to KantanPro/KantanProEX/KantanBiz beyond being maintained by the same author.

## Architecture

Small, cleanly separated codebase (`declare(strict_types=1)` throughout) — `xLabo.php` just defines constants and `require_once`s each class, then boots the singleton via `xlabo()` / `XLabo_Plugin::instance()`.

- **`includes/class-xlabo-oauth.php`** — X auth: OAuth 2.0 with PKCE (primary, connect-account flow) and OAuth 1.0a (API key/access token) as an alternative.
- **`includes/class-xlabo-api-client.php`** — X API HTTP client (posting tweets, uploading media for the featured image attachment).
- **`includes/class-xlabo-auto-poster.php`** — hooks post-publish to auto-share; also backs the manual "share" action from the post edit screen. Tweet body is built from a template with `{title}`, `{url}`, `{excerpt}` placeholders, plus post tags converted to hashtags.
- **`includes/class-xlabo-twitter-cards.php`** — outputs `summary_large_image` Twitter Card meta tags on post pages.
- **`includes/class-xlabo-settings.php`** — admin settings screen (API credentials, OAuth connect button, template, auto-share toggle).
- **`includes/class-xlabo.php`** — the `XLabo_Plugin` singleton tying it together, plus `activate`/`deactivate` hooks.

## Conventions

- New code should keep `declare(strict_types=1)` and the one-class-per-file `includes/class-xlabo-*.php` naming already in place.
- Never log or display raw API keys/access tokens/secrets — these are exactly the kind of "confidential in `.env`-equivalent" (WP options) data that must stay out of debug output and error messages.
- The featured image attachment path uploads media via the X API before attaching to the tweet — don't assume a URL-only attachment; large-image display card semantics rely on the actual upload flow in `class-xlabo-api-client.php`.

## Commands

No automated test suite. Verify manually against a WordPress install with a configured X Developer Portal app (both OAuth 2.0/PKCE connect flow and OAuth 1.0a credential entry, since both are supported paths) — publish a post and confirm the share, template placeholder substitution, hashtag conversion, and image attachment.

## Commit messages

Always write commit messages in Japanese, concise form like `〇〇を追加` / `〇〇を修正` / `〇〇のバグを修正` — never English one-liners.
