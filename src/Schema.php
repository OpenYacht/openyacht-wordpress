<?php

declare(strict_types=1);

namespace OpenYacht;

/**
 * Owns every plugin table definition and the versioned migration runner.
 *
 * All schema SQL lives here and nowhere else: activation and the
 * plugins_loaded upgrade path both call install(), so an updated plugin
 * migrates existing installs without the activation hook ever re-firing.
 */
final class Schema
{
    public const VERSION = 6;

    public const OPTION = 'openyacht_schema_version';

    /**
     * Table name suffixes, appended to "{$wpdb->prefix}openyacht_".
     *
     * @var list<string>
     */
    public const TABLE_SUFFIXES = [
        'keys',
        'partners',
        'listings',
        'listing_audience',
        'partner_groups',
        'partner_group_members',
        'listing_audience_groups',
        'listing_media',
        'price_history',
        'copies',
        'copy_media',
        'visibility_events',
        'log',
    ];

    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    public function maybeUpgrade(): void
    {
        $from = (int) get_option(self::OPTION, 0);

        if ($from === self::VERSION) {
            return;
        }

        $this->install();

        if ($from > 0 && $from < 6) {
            $this->backfillMediaThumbnails();
        }
    }

    /**
     * v6 data migration: the protocol widened the thumbnail exception to
     * gallery and layout items (nullable thumbnail_url, LS-16). Rows picked
     * before the change carry an attachment_id but no stored thumbnail —
     * compute one from the attachment's renditions, exactly as the listing
     * form now does at save time. Rows stay null when WP has no rendition
     * smaller than the original: that is the honest wire value.
     */
    private function backfillMediaThumbnails(): void
    {
        $table = $this->tableName('listing_media');
        $rows = $this->wpdb->get_results(
            "SELECT id, attachment_id, url FROM {$table} WHERE kind IN ('gallery', 'layout') AND thumbnail_url IS NULL AND attachment_id IS NOT NULL",
            'ARRAY_A',
        );

        foreach (is_array($rows) ? $rows : [] as $row) {
            $thumbnail = wp_get_attachment_image_url((int) $row['attachment_id'], 'medium_large');

            if (is_string($thumbnail) && $thumbnail !== $row['url']) {
                $this->wpdb->update($table, ['thumbnail_url' => $thumbnail], ['id' => (int) $row['id']]);
            }
        }
    }

    public function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ($this->tables() as $sql) {
            dbDelta($sql);
        }

        update_option(self::OPTION, self::VERSION, false);
    }

    public function tableName(string $suffix): string
    {
        return $this->wpdb->prefix . 'openyacht_' . $suffix;
    }

    /**
     * CREATE TABLE statements in dbDelta-compatible form, keyed by suffix.
     *
     * dbDelta formatting rules apply: one column/index per line, two spaces
     * after PRIMARY KEY, no backticks. JSON payloads are longtext (dbDelta
     * churns on the native json type), timestamps are app-managed UTC
     * datetimes (no DB defaults, for SQLite/Playground compatibility).
     *
     * @return array<string, string>
     */
    public function tables(): array
    {
        $collate = $this->wpdb->get_charset_collate();
        $tables = [];

        $tables['keys'] = "CREATE TABLE {$this->tableName('keys')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  key_id char(16) NOT NULL,
  private_key text NOT NULL,
  public_key varchar(64) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  retired_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY key_id (key_id),
  KEY status (status)
) {$collate};";

        $tables['partners'] = "CREATE TABLE {$this->tableName('partners')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  domain varchar(255) NOT NULL,
  node_uuid char(36) NULL,
  keys_json longtext NULL,
  keys_fetched_at datetime NULL,
  pinned_key_id char(16) NULL,
  trust_level varchar(16) NOT NULL DEFAULT 'provisional',
  field_groups longtext NULL,
  approved_by_user_id bigint(20) unsigned NULL,
  last_ok_at datetime NULL,
  consecutive_failures int(10) unsigned NOT NULL DEFAULT 0,
  last_synced_at datetime NULL,
  last_attempted_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY domain (domain(191)),
  KEY trust_level (trust_level)
) {$collate};";

        $tables['listings'] = "CREATE TABLE {$this->tableName('listings')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  type varchar(16) NOT NULL DEFAULT 'sale',
  status varchar(16) NOT NULL DEFAULT 'draft',
  name varchar(255) NULL,
  summary text NULL,
  yacht_condition varchar(8) NULL,
  hin varchar(64) NULL,
  imo varchar(16) NULL,
  mmsi varchar(16) NULL,
  official_number varchar(32) NULL,
  builder_name varchar(255) NULL,
  builder_slug varchar(191) NULL,
  model_name varchar(255) NULL,
  year_built smallint(5) unsigned NULL,
  refit_year smallint(5) unsigned NULL,
  loa_m decimal(6,2) NULL,
  previous_names longtext NULL,
  price_amount varchar(32) NULL,
  price_currency char(3) NULL,
  price_on_application tinyint(1) NOT NULL DEFAULT 0,
  starting_price tinyint(1) NOT NULL DEFAULT 0,
  location_display varchar(255) NULL,
  location_city varchar(255) NULL,
  location_state varchar(255) NULL,
  location_country char(2) NULL,
  location_marina varchar(255) NULL,
  location_lat decimal(10,7) NULL,
  location_lon decimal(10,7) NULL,
  specifications longtext NULL,
  descriptions longtext NULL,
  features longtext NULL,
  compliance longtext NULL,
  audience varchar(16) NOT NULL DEFAULT 'everyone',
  listed_at datetime NULL,
  federation_updated_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY status (status),
  KEY federation_updated_at (federation_updated_at)
) {$collate};";

        $tables['listing_audience'] = "CREATE TABLE {$this->tableName('listing_audience')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  listing_id bigint(20) unsigned NOT NULL,
  partner_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY listing_partner (listing_id,partner_id),
  KEY partner_id (partner_id)
) {$collate};";

        $tables['partner_groups'] = "CREATE TABLE {$this->tableName('partner_groups')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY name (name)
) {$collate};";

        $tables['partner_group_members'] = "CREATE TABLE {$this->tableName('partner_group_members')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  group_id bigint(20) unsigned NOT NULL,
  partner_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY group_partner (group_id,partner_id),
  KEY partner_id (partner_id)
) {$collate};";

        $tables['listing_audience_groups'] = "CREATE TABLE {$this->tableName('listing_audience_groups')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  listing_id bigint(20) unsigned NOT NULL,
  group_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY listing_group (listing_id,group_id),
  KEY group_id (group_id)
) {$collate};";

        $tables['listing_media'] = "CREATE TABLE {$this->tableName('listing_media')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  listing_id bigint(20) unsigned NOT NULL,
  kind varchar(16) NOT NULL,
  attachment_id bigint(20) unsigned NULL,
  url text NULL,
  thumbnail_url text NULL,
  sha256 char(64) NULL,
  width int(10) unsigned NULL,
  height int(10) unsigned NULL,
  caption varchar(255) NULL,
  category varchar(16) NULL,
  sort int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY listing_kind (listing_id,kind)
) {$collate};";

        $tables['price_history'] = "CREATE TABLE {$this->tableName('price_history')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  listing_id bigint(20) unsigned NOT NULL,
  amount varchar(32) NOT NULL,
  currency char(3) NOT NULL,
  changed_at datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY listing_id (listing_id)
) {$collate};";

        $tables['copies'] = "CREATE TABLE {$this->tableName('copies')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  partner_id bigint(20) unsigned NOT NULL,
  canonical_uri varchar(500) NOT NULL,
  authority_domain varchar(255) NOT NULL,
  type varchar(16) NOT NULL,
  status varchar(16) NOT NULL,
  name varchar(255) NULL,
  payload longtext NOT NULL,
  listing_updated_at datetime NULL,
  received_at datetime NOT NULL,
  signature_verified tinyint(1) NOT NULL DEFAULT 0,
  selected_at datetime NULL,
  tombstoned_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY canonical_uri (canonical_uri(191)),
  KEY partner_id (partner_id),
  KEY status (status)
) {$collate};";

        $tables['copy_media'] = "CREATE TABLE {$this->tableName('copy_media')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  copy_id bigint(20) unsigned NOT NULL,
  kind varchar(16) NOT NULL,
  source_url text NULL,
  source_sha256 char(64) NULL,
  caption varchar(255) NULL,
  sort int(10) unsigned NOT NULL DEFAULT 0,
  path varchar(500) NULL,
  renditions longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY copy_kind (copy_id,kind)
) {$collate};";

        $tables['visibility_events'] = "CREATE TABLE {$this->tableName('visibility_events')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  listing_id bigint(20) unsigned NOT NULL,
  partner_id bigint(20) unsigned NOT NULL,
  event varchar(16) NOT NULL,
  occurred_at datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY partner_time (partner_id,occurred_at),
  KEY listing_id (listing_id)
) {$collate};";

        $tables['log'] = "CREATE TABLE {$this->tableName('log')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  channel varchar(32) NOT NULL,
  partner_id bigint(20) unsigned NULL,
  endpoint varchar(255) NULL,
  outcome varchar(32) NULL,
  message text NULL,
  context longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY channel_time (channel,created_at),
  KEY partner_id (partner_id)
) {$collate};";

        return $tables;
    }
}
