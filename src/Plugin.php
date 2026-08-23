<?php

declare(strict_types=1);

namespace OpenYacht;

use OpenYacht\Admin\Settings;

final class Plugin
{
    private static ?self $instance = null;

    public static function boot(): void
    {
        if (self::$instance !== null) {
            return;
        }

        self::$instance = new self();
        self::$instance->register();
    }

    private function register(): void
    {
        global $wpdb;

        // Runs the schema migration on version bumps; activation never
        // re-fires on plugin updates, so this is the real upgrade path.
        (new Schema($wpdb))->maybeUpgrade();

        add_action('init', static function (): void {
            load_plugin_textdomain('openyacht', false, dirname(plugin_basename(OPENYACHT_FILE)) . '/languages');
        });

        if (is_admin()) {
            (new Settings())->register();
        }
    }
}
