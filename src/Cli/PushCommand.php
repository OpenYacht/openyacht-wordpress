<?php

declare(strict_types=1);

namespace OpenYacht\Cli;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Federation\PushDelivery;
use OpenYacht\Services;
use WP_CLI;

/**
 * The push delivery queue: `wp openyacht push <deliver|list>`.
 */
final class PushCommand
{
    /**
     * Run the push delivery worker now: send every due delivery to its
     * partner's callback, retrying failures on the usual backoff.
     *
     * ## EXAMPLES
     *
     *     wp openyacht push deliver
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     */
    public function deliver(array $args, array $assocArgs): void
    {
        $counts = Services::pushDeliveryWorker()->drain();

        WP_CLI::success(sprintf(
            '%d delivered, %d failed (will retry), %d abandoned after 24 hours, %d dropped (nothing to deliver any more).',
            $counts['delivered'],
            $counts['failed'],
            $counts['abandoned'],
            $counts['dropped'],
        ));
    }

    /**
     * List pending push deliveries.
     *
     * @subcommand list
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     */
    public function list_(array $args, array $assocArgs): void
    {
        $partners = [];

        foreach (Services::partners()->all() as $partner) {
            $partners[$partner->id] = $partner->domain;
        }

        $rows = array_map(static function (PushDelivery $delivery) use ($partners): array {
            $listing = Services::listings()->find($delivery->listingId);

            return [
                'partner' => $partners[$delivery->partnerId] ?? "#{$delivery->partnerId}",
                'listing' => $listing->uuid ?? "#{$delivery->listingId}",
                'queued_at' => $delivery->queuedAt,
                'next_attempt_at' => $delivery->nextAttemptAt,
                'attempts' => $delivery->attempts,
                'last_error' => $delivery->lastError ?? '',
            ];
        }, Services::pushDeliveries()->all());

        if ($rows === []) {
            WP_CLI::line('No pending push deliveries.');

            return;
        }

        \WP_CLI\Utils\format_items('table', $rows, ['partner', 'listing', 'queued_at', 'next_attempt_at', 'attempts', 'last_error']);
    }
}
