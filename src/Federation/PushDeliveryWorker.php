<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use Closure;
use RuntimeException;

/**
 * Drains the push delivery queue: for each due row, derives the
 * listing's current state for that partner and POSTs it, signed, to the
 * partner's callback (API-10).
 *
 * A failed attempt is retried on an exponential backoff (one minute
 * doubling, capped at four hours) until the 24-hour window the spec
 * sets closes; only then is the delivery abandoned, logged, and left to
 * the partner's reconciliation poll. Runs from a wp-cron single event a
 * few seconds after a change, from the hourly sync pass as a safety
 * net, and from `wp openyacht push deliver`.
 *
 * // api-design.md §Subscriptions
 */
final class PushDeliveryWorker
{
    public const RETRY_WINDOW_SECONDS = 24 * 3600;

    public const INITIAL_BACKOFF_SECONDS = 60;

    public const MAX_BACKOFF_SECONDS = 4 * 3600;

    public const BATCH_SIZE = 50;

    /**
     * @param Closure(int): void|null $scheduleDrain asks for another run at
     *                                               the given timestamp
     */
    public function __construct(
        private readonly PushDeliveryRepository $queue,
        private readonly PartnerRepository $partners,
        private readonly ListingRepository $listings,
        private readonly SubscriptionService $subscriptions,
        private readonly FederationClient $client,
        private readonly Logger $logger,
        private readonly ?Closure $scheduleDrain = null,
    ) {
    }

    /**
     * @return array{delivered: int, failed: int, abandoned: int, dropped: int}
     */
    public function drain(?int $now = null): array
    {
        $now ??= time();
        $nowStored = gmdate('Y-m-d H:i:s', $now);
        $counts = ['delivered' => 0, 'failed' => 0, 'abandoned' => 0, 'dropped' => 0];

        foreach ($this->queue->due($nowStored, self::BATCH_SIZE) as $delivery) {
            // Re-read: the partner may have unsubscribed, been blocked or
            // downgraded, or the feature switched off, while this waited.
            $partner = $this->partners->find($delivery->partnerId);
            $listing = $this->listings->find($delivery->listingId);
            $payload = $partner !== null && $listing !== null && $this->subscriptions->deliversTo($partner)
                ? $this->subscriptions->payloadFor($listing, $partner)
                : null;

            if ($partner === null || $listing === null || $payload === null) {
                $this->queue->delete($delivery->id);
                $counts['dropped']++;

                continue;
            }

            try {
                $response = $this->client->postCallback((string) $partner->pushCallbackUrl, $payload);
                $error = $response->ok() ? null : "HTTP {$response->status}";
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }

            if ($error === null) {
                $this->partners->update($partner->id, ['push_last_delivered_at' => $nowStored]);

                // A newer change folded in while this one was in flight:
                // the row stays, and goes again right away.
                if (! $this->queue->complete($delivery->id, $delivery->changedAt)) {
                    $this->queue->reschedule($delivery->id, $nowStored);
                }

                $counts['delivered']++;

                continue;
            }

            $this->partners->update($partner->id, ['push_last_failed_at' => $nowStored]);
            $queuedAt = strtotime($delivery->queuedAt . ' UTC');

            if ($queuedAt !== false && $queuedAt + self::RETRY_WINDOW_SECONDS <= $now) {
                // The window closed: the change reaches this partner on its
                // reconciliation poll instead. Logged where the operator looks.
                $this->queue->delete($delivery->id);
                $this->logger->log(
                    'sync',
                    "Push delivery to {$partner->domain} for {$listing->uuid} abandoned after 24 hours: {$error}",
                    'push_delivery_abandoned',
                    $partner->id,
                    null,
                    ['listing_uuid' => $listing->uuid, 'attempts' => $delivery->attempts + 1, 'reason' => $error],
                );
                $counts['abandoned']++;

                continue;
            }

            $attempts = $delivery->attempts + 1;
            $backoff = min(self::INITIAL_BACKOFF_SECONDS * (2 ** ($attempts - 1)), self::MAX_BACKOFF_SECONDS);
            $this->queue->recordFailure($delivery->id, $attempts, gmdate('Y-m-d H:i:s', $now + $backoff), $error);
            $counts['failed']++;
        }

        $next = $this->queue->earliestNextAttempt();

        if ($next !== null && $this->scheduleDrain !== null) {
            $at = strtotime($next . ' UTC');
            ($this->scheduleDrain)(max($at === false ? $now : $at, $now + SubscriptionService::ENQUEUE_DELAY_SECONDS));
        }

        return $counts;
    }
}
