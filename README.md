# OpenYacht for WordPress

WordPress node for the [OpenYacht protocol](https://github.com/OpenYacht/protocol) — publish, share, and sync yacht listings directly between brokerages, with no aggregator in the middle.

> **Status: pre-release.** Built against protocol Draft v0.1 and federating live in development against the [PHP reference implementation](https://github.com/OpenYacht/openyacht-reference-app-php) and a production intranet node. Installable from the release zip; updates are self-hosted off this repo's GitHub releases (not wordpress.org).

## What it does

A full protocol node in one plugin — both roles:

- **Authority**: author listings in a custom editor (full wire media model, shared feature vocabulary, price history), validate every candidate against the vendored JSON Schema before it persists, and serve spec-exact signed endpoints at the site root (`/.well-known/openyacht`, `/openyacht/v1/…`).
- **Consumer**: verify partners by TOFU over TLS with key pinning, poll their feeds with keyset cursors, store synced listings as data, and let site staff selectively import the ones they want — media is cached locally only for imported listings.
- **Sharing**: per-listing audience control (everyone / selected / no one), node-defined partner groups, and per-partner field-group grants (pricing, exact location, documents, …) — every visibility change replays through an append-only event log, so unsharing delivers a tombstone indistinguishable from a withdrawal and re-sharing resurfaces the listing against any watermark.
- **Discovery**: generate signed node-directory listing requests, and browse the directory (canonical source only) to add partners.

Listings are data, not posts — display is a separate concern layered on top.

## Requirements

- WordPress 6.4+ with HTTPS (activation refuses without it — running non-conformantly is not an option), PHP 8.1+ (developed on 8.4), `ext-sodium`
- MySQL/MariaDB (custom tables via dbDelta, versioned migrations)
- A real cron (see below)

## Installation

Upload the `openyacht-<version>.zip` from the latest release via Plugins → Add New → Upload Plugin (or build one with `bin/build.sh`). After activating, WordPress checks this repo's releases for updates like any other plugin.

### Use a real cron job

The protocol obliges a node to keep synced copies no more than 24 hours stale, and the plugin schedules its hourly sync pass (plus queued media downloads) on wp-cron — which only fires when the site gets visits. On a quiet site that means syncs simply stop, and the plugin will show a persistent admin warning when a pass is overdue. Disable visit-triggered cron in `wp-config.php`:

```php
define('DISABLE_WP_CRON', true);
```

and run WordPress cron from the system instead, every minute, as the site's user:

```
* * * * * cd /path/to/site && wp cron event run --due-now --quiet
```

(Every minute is right: the sync itself stays hourly, but media-fetch events queued seconds after a sync get picked up promptly, and CLI execution frees image downloads from web-request time limits. Without WP-CLI, an every-minute HTTP hit on `wp-cron.php?doing_wp_cron` works too.)

## Development

```bash
composer install
composer test   # PHPUnit + Brain Monkey; conformance-ID groups (e.g. --group FP-10)
composer stan   # PHPStan level 6
composer fix    # PHP-CS-Fixer, PSR-12
bin/playground-check.sh   # boots a throwaway WP via @wp-playground/cli and asserts activation
npx @tailwindcss/cli -i assets/admin/editor.src.css -o assets/admin/editor.css --minify
```

Signing follows the protocol's published test vectors byte-for-byte (`tests/Unit/Federation/SigningVectorsTest.php`). The registries and schemas under `resources/` are vendored copies of the protocol repo's — refreshed deliberately, never fetched at request time.

## License

AGPL-3.0
