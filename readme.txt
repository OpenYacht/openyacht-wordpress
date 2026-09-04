=== OpenYacht ===
Contributors: robwent
Tags: yacht, listings, federation, brokerage, charter
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.5.1
License: AGPL-3.0-only
License URI: https://www.gnu.org/licenses/agpl-3.0.html

Turns this WordPress site into an OpenYacht node — federated yacht-listing sharing between brokerages.

== Description ==

OpenYacht is an open, decentralised protocol for sharing yacht listings directly between brokerages, with no aggregator in the middle. This plugin makes a WordPress site a full protocol node, in both roles:

* **Authority** — author listings in a custom editor (full wire media model, shared feature vocabulary, price history), validate every candidate against the vendored JSON Schema before it persists, and serve spec-exact signed endpoints at the site root (`/.well-known/openyacht`, `/openyacht/v1/…`).
* **Consumer** — verify partners by trust-on-first-use over TLS with key pinning, poll their feeds with keyset cursors, store synced listings as data, and let site staff selectively import the ones they want. Media is cached locally only for imported listings.
* **Sharing** — per-listing audience control (everyone, selected partners, or no one), node-defined partner groups, per-partner field-group grants (pricing, exact location, documents, …), and curated partners that receive only what is explicitly selected for them. Every visibility change replays through an append-only event log, so unsharing delivers a tombstone and re-sharing resurfaces the listing against any watermark.
* **Discovery** — generate signed node-directory listing requests, and browse the directory to add partners.

Listings are data, not posts. Display is a separate concern layered on top through the public `OpenYacht\Data` API and the companion display plugin.

The protocol specification, JSON Schemas, and registries live at [openyacht.org](https://openyacht.org). Source, issues, and releases are on [GitHub](https://github.com/OpenYacht/openyacht-wordpress).

== Installation ==

1. Download `openyacht-<version>.zip` from the latest GitHub release.
2. In wp-admin go to Plugins → Add New → Upload Plugin, choose the zip, and activate.
3. Activation requires HTTPS, PHP 8.1 or newer with the sodium extension, and MySQL or MariaDB. The plugin refuses to activate without them rather than run non-conformantly.
4. After activation, WordPress checks the GitHub releases for updates like any other plugin. The plugin is not distributed through wordpress.org.

**Run a real cron.** The protocol obliges a node to keep synced copies no more than 24 hours stale, and the hourly sync pass runs on wp-cron, which only fires when the site gets visits. Set `DISABLE_WP_CRON` to true in `wp-config.php` and run `wp cron event run --due-now` from a system cron every minute.

**Use a persistent object cache.** Strongly recommended for any node serving real federation traffic. Rate-limit counters are transients; without Redis or Memcached each verified inbound request writes two rows to the options table.

== Frequently Asked Questions ==

= Does this plugin display listings on my site? =

No. It stores and federates listings as data and exposes them through the `OpenYacht\Data` API. Rendering is left to a display layer such as the companion OpenYacht Display plugin or your own theme code.

= Why does activation fail without HTTPS? =

Every federation request is signed, and partners verify keys over TLS at first contact. A node without HTTPS cannot take part conformantly, so the plugin refuses to activate rather than run in a state partners would reject.

= Where do updates come from? =

From this plugin's public GitHub releases. The `Update URI` header opts the plugin out of wordpress.org update checks entirely.

= Does it support Multisite? =

Multisite is not a supported configuration. A node's identity is one domain with one signing key, which does not map onto a network of sites.

= Will my own listings be shared automatically? =

No. New listings are created as drafts, publishing is an explicit transition, and every listing carries its own audience setting. Partnering with a node carries no obligation to import anything from it either.

== Changelog ==

= 0.5.1 =

Maintenance release working through a third-party plugin audit of 0.5.0. No wire or database change; upgrading is a straight plugin update.

* Added this readme.txt, with the changelog back to 0.1.0.
* Direct-access guards in every source file.
* Accessibility: the list screens' search fields carry screen-reader labels, and each listings row checkbox is labelled with the listing name.
* Translators comments on the placeholder strings that lacked them.
* The release zip no longer ships composer.json or the dependencies' README, CHANGELOG and SECURITY files, and the build refuses to run when the readme Stable tag disagrees with the plugin version.
* The admin menu carries the OpenYacht code-hoist mark, and the Updates screen and "View details" modal show the plugin icon and banner.

= 0.5.0 =

Curated-partner sharing and federation security hardening.

* New per-partner sharing scope on the Partners screen: standard (receives everything shared with everyone, plus anything selected for it) or curated (receives only listings explicitly selected for it, directly or via a group). Built for yacht-show organisers, trials, and press feeds.
* Explicit selections are now additive under any audience except "no one", so an everyone-audience listing can also reach a curated partner without changing what other partners see.
* Changing a partner's scope replays through the visibility event log: narrowing tombstones what the partner only saw via "everyone", widening resurfaces it on the next poll.
* Curated partners get a per-type shared-listings picker on their sharing screen, with select-all and group-derived shares shown with their provenance.
* Schema v8 adds `sharing_scope` on partners, defaulting every existing partner to standard. No wire or protocol change.
* Trust-on-first-use key pinning is armed at first contact and established on approve for pre-existing partners, plus further verifier and partner-lifecycle hardenings from the federation security review.

= 0.4.5 =

* Data migrations that change what a listing serves on the wire now stamp `federation_updated_at` for the affected listings, so partners re-sync exactly the listings that changed.
* Applied retroactively to the 0.4.4 thumbnail change: every listing with gallery or layout media is stamped on update. Expect one burst of updates on partner nodes after upgrading.

= 0.4.4 =

* Adopts the protocol amendment widening the thumbnail exception to gallery and layout images (conformance rule LS-16): listings serve an authority-hosted `thumbnail_url` for every gallery and layout image, or null when no smaller rendition exists.
* Existing listings are backfilled automatically (schema v6).
* The Synced Listings preview shows a gallery grid from the partner's own thumbnails before import, and never hotlinks full-resolution originals into the grid.
* Vendored listing schema updated to match the amended protocol.

= 0.4.3 =

* New "Sync now" row action on the Partners screen pulls a partner's listings on demand, ignoring the failure backoff and reporting what changed.

= 0.4.2 =

* Fixed the invisible Approve link on the Partners screen. Core CSS hides row actions keyed `approve`; the action is now registered as `approve_partner`.

= 0.4.1 =

* `/.well-known/openyacht` and `/openyacht/v1/capabilities` now send `Cache-Control: public` (five minutes and one hour). Everything else stays uncacheable on purpose, because listing responses are filtered per partner.
* A persistent object cache is now a documented recommendation.

= 0.4.0 =

* Charter listings sync, browse, and preview like sale listings, with their own admin screens: Sale Listings, Charter Listings, Synced Sale, and Synced Charter.
* New listings choose their type, sale or charter, at creation. Charter listings serialise with a charter block and a null asking price.
* New hooks `openyacht_copy_media_cached` and `openyacht_copy_selection_changed` for display layers and bridges.
* The admin menu carries the OpenYacht mark instead of a stock icon.

= 0.3.0 =

* Extends the `OpenYacht\Data` read API with `ownListings()`, `ownListing($uuid)`, and `wire($listing)` for display layers and developer bridges. Purely additive.

= 0.2.0 =

* New Activity screen with the full federation log: inbound requests with their verification outcome, sync passes, and partner, key, and sharing lifecycle events. Request and sync entries are pruned daily per the new retention setting.

= 0.1.0 =

* Initial release.
