<?php

/**
 * Uninstall handler. Keeps all data by default: signing keys and listing
 * provenance are unrecoverable, so destruction requires the explicit
 * "delete all data on uninstall" setting to have been enabled beforehand.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$openyacht_autoload = __DIR__ . '/vendor/autoload.php';

if (! file_exists($openyacht_autoload)) {
    return; // Without the table registry we delete nothing — the safe default.
}

require_once $openyacht_autoload;

$openyacht_settings = get_option(\OpenYacht\Admin\Settings::OPTION, []);

if (! is_array($openyacht_settings) || empty($openyacht_settings['delete_data_on_uninstall'])) {
    return;
}

global $wpdb;

foreach (\OpenYacht\Schema::TABLE_SUFFIXES as $openyacht_suffix) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'openyacht_' . $openyacht_suffix); // phpcs:ignore WordPress.DB -- identifiers cannot be prepared; built from a fixed allowlist.
}

delete_option(\OpenYacht\Admin\Settings::OPTION);
delete_option(\OpenYacht\Schema::OPTION);
delete_option(\OpenYacht\NodeIdentity::OPTION);
