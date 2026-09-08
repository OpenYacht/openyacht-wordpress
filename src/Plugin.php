<?php

declare(strict_types=1);

namespace OpenYacht;

if (! defined('ABSPATH')) {
    exit;
}

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
        (new Updates())->register();
        (new Notifications())->register();

        add_action(SyncRunner::HOOK, static function (): void {
            SyncRunner::run();
        });

        // Media fetching is queued in small single events so a sync pass is
        // never blocked on image downloads; a tombstone purges immediately
        // (cached media inherits the usage terms, ID-10). Only copies the
        // media policy covers are cached — everything else previews via
        // the authority's own thumbnails.
        $queueMedia = static function (Federation\ListingCopy $copy): void {
            if (Media\MediaPolicy::shouldCache($copy) && ! wp_next_scheduled('openyacht_fetch_copy_media', [$copy->id])) {
                wp_schedule_single_event(time() + 10, 'openyacht_fetch_copy_media', [$copy->id]);
            }
        };
        add_action('openyacht_copy_created', $queueMedia);
        add_action('openyacht_copy_updated', $queueMedia);
        add_action('openyacht_copy_tombstoned', static function (Federation\ListingCopy $copy): void {
            Services::mediaService()->expire($copy);
        });
        add_action('openyacht_fetch_copy_media', static function (int $copyId): void {
            $copy = Services::copies()->find($copyId);

            if ($copy !== null && $copy->tombstonedAt === null && Media\MediaPolicy::shouldCache($copy)) {
                Services::mediaService()->sync($copy);

                // Public surface: display layers re-project here — copy
                // events fire before the background fetch, so this is the
                // first moment cached renditions exist to render.
                do_action('openyacht_copy_media_cached', $copy);
            }
        });

        if (! wp_next_scheduled(SyncRunner::HOOK)) {
            wp_schedule_event(time(), 'hourly', SyncRunner::HOOK);
        }

        // Push subscriptions (API-10): deliveries are derived from the two
        // sources the polled feed keys on — the federation_updated_at write
        // path (the listing actions) and the visibility event log — queued
        // for the delivery worker, which derives each payload at send time.
        $listingChanged = static function (Federation\Listing $listing): void {
            Services::subscriptionService()->listingChanged($listing);
        };
        add_action('openyacht_listing_created', $listingChanged);
        add_action('openyacht_listing_updated', $listingChanged);
        add_action('openyacht_listing_transitioned', $listingChanged);
        add_action('openyacht_visibility_changed', static function (int $listingId, int $partnerId): void {
            Services::subscriptionService()->visibilityChanged($listingId, $partnerId);
        }, 10, 2);
        add_action(PushSchedule::HOOK, static function (): void {
            Services::pushDeliveryWorker()->drain();
        });

        // Retention: the request/sync log channels grow with every inbound
        // request, so a daily prune keeps the table bounded. Audit channels
        // (partner, keys, sharing) are never pruned.
        add_action('openyacht_log_prune', static function (): void {
            $days = Admin\Settings::get('log_retention_days');
            Services::logReader()->prune(is_numeric($days) ? (int) $days : 90);
        });

        if (! wp_next_scheduled('openyacht_log_prune')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'openyacht_log_prune');
        }

        if (defined('WP_CLI') && constant('WP_CLI')) {
            \WP_CLI::add_command('openyacht', Cli\RootCommand::class);
            \WP_CLI::add_command('openyacht partner', Cli\PartnerCommand::class);
            \WP_CLI::add_command('openyacht key', Cli\KeyCommand::class);
            \WP_CLI::add_command('openyacht media', Cli\MediaCommand::class);
            \WP_CLI::add_command('openyacht push', Cli\PushCommand::class);
            \WP_CLI::add_command('openyacht seed', Cli\SeedCommand::class);
        }

        if (is_admin()) {
            (new Settings())->register();
            (new Admin\PartnersPage())->register();
            (new Admin\ListingsPage('sale'))->register();
            (new Admin\ListingsPage('charter'))->register();
            (new Admin\SyncedListingsPage('sale'))->register();
            (new Admin\SyncedListingsPage('charter'))->register();
            (new Admin\ActivityPage())->register();
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

        if (Services::subscriptionService()->offersSubscriptions()) {
            echo ' ' . esc_html__('Push deliveries to subscribed partners run from the same cron, and retry for at most 24 hours before they are abandoned.', 'openyacht');
        }

        echo '</p></div>';
    }
}
