<?php

declare(strict_types=1);

namespace OpenYacht;

use OpenYacht\Admin\Settings;
use OpenYacht\Http\Router;

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

        (new Router())->register();

        add_action(SyncRunner::HOOK, static function (): void {
            SyncRunner::run();
        });

        if (! wp_next_scheduled(SyncRunner::HOOK)) {
            wp_schedule_event(time(), 'hourly', SyncRunner::HOOK);
        }

        if (defined('WP_CLI') && constant('WP_CLI')) {
            \WP_CLI::add_command('openyacht', Cli\RootCommand::class);
            \WP_CLI::add_command('openyacht partner', Cli\PartnerCommand::class);
            \WP_CLI::add_command('openyacht key', Cli\KeyCommand::class);
        }

        if (is_admin()) {
            (new Settings())->register();
            add_action('admin_notices', [$this, 'syncWatchdogNotice']);
        }
    }

    /**
     * Conformance watchdog: wp-cron is visit-triggered, so a quiet site can
     * sleep past the 24-hour copy-freshness obligation (ID-7). Surface it.
     */
    public function syncWatchdogNotice(): void
    {
        if (! current_user_can('manage_options') || ! SyncRunner::isOverdue()) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('OpenYacht: partner sync is overdue.', 'openyacht') . '</strong> ';
        echo esc_html__('WordPress cron only runs when the site gets visits, and no sync pass has completed recently — synced listings may fall behind the 24-hour freshness obligation. Run "wp openyacht sync" or point a system cron at wp-cron.php.', 'openyacht');
        echo '</p></div>';
    }
}
