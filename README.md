# OpenYacht for WordPress

WordPress node for the [OpenYacht protocol](https://github.com/OpenYacht/protocol) — publish, share, and sync yacht listings directly between brokerages, with no aggregator in the middle.

> **Status: pre-release.** Built against protocol Draft v0.1 and federating live in development against the [PHP reference implementation](https://github.com/OpenYacht/reference-app) and a production intranet node. Not yet packaged for general installation.

## What it does

A full protocol node in one plugin — both roles:

- **Authority**: author listings in a custom editor (full wire media model, shared feature vocabulary, price history), validate every candidate against the vendored JSON Schema before it persists, and serve spec-exact signed endpoints at the site root (`/.well-known/openyacht`, `/openyacht/v1/…`).
- **Consumer**: verify partners by TOFU over TLS with key pinning, poll their feeds with keyset cursors, store synced listings as data, and let site staff selectively import the ones they want — media is cached locally only for imported listings.
- **Sharing**: per-listing audience control (everyone / selected / no one), node-defined partner groups, and per-partner field-group grants (pricing, exact location, documents, …) — every visibility change replays through an append-only event log, so unsharing delivers a tombstone indistinguishable from a withdrawal and re-sharing resurfaces the listing against any watermark.
- **Discovery**: generate signed node-directory listing requests, and browse the directory (canonical source only) to add partners.

Listings are data, not posts — display is a separate concern layered on top.

## Requirements

- WordPress with HTTPS, PHP 8.1+ (developed on 8.4), `ext-sodium`
- MySQL/MariaDB (custom tables via dbDelta, versioned migrations)

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
