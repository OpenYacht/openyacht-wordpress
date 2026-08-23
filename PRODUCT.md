# PRODUCT.md — OpenYacht for WordPress

## What this is

The free core plugin that turns a WordPress site into an OpenYacht node: a member of a federated, open-protocol network where yacht brokerages exchange listings directly, peer to peer, with cryptographic identity and per-partner sharing rules. It is the protocol's third independent implementation and the long-tail adoption path — brokerages that run WordPress and nothing else.

## Who uses it

Brokerage staff (brokers, listing managers, office admins) inside wp-admin. They are not developers. They author sale listings, decide which partners receive what, and browse/import partner inventory. A developer persona exists too (ingest API, hooks, read API) but never sees these screens.

## Product truth

- Everything protocol-visible lives in plugin-owned tables; posts are downstream projections owned by a separate display addon. The free core is a complete, conformant node — money never gates joining the network.
- Listings are validated against the vendored protocol schema before anything is stored or served; drafts are never distributed; canonical UUIDs never change; price history only appends.
- Sharing is per listing and per partner: audience control, field-group grants, and unshare-as-tombstone with no information leak.
- Partner listings sync as data automatically; importing (and image caching) is a deliberate per-listing choice.

## Surfaces & modes

All custom admin surfaces are **Operate** mode. Familiar chrome (lists, settings) uses native WP admin styling; owned experiences (the listing editor, future yacht browsing) use the plugin's own visual world.

## Brand commitments

- Visual world "quiet nautical" (user-pinned, 2026-08-23): calm fog ground, white sheet surfaces, deep navy ink, slate-tinted secondaries, a single brass accent spent sparingly; hairline navy-tinted rules; system font stack inside wp-admin (deliberate — no webfonts in admin).
- Tailwind v4 utilities purged against the PHP templates; component classes carry the design tokens. No preflight inside wp-admin.
- Copy states protocol consequences plainly ("partners receive a tombstone on their next poll") — the interface teaches the federation model as it is used.

## Constraints

- Runs inside wp-admin on PHP 8.1+ / WP 6.4+; form field names are a stable contract with the server-side parser.
- Distribution is self-hosted (not wordpress.org); AGPL-3.0.
