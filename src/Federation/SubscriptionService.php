<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use Closure;
use OpenYacht\Admin\Settings;

/**
 * The authority half of push subscriptions (API-10): verified partners
 * register one HTTPS callback each, and every change their feed would
 * report is delivered there as a signed POST instead of waiting for
 * their next poll.
 *
 * Changes are not tracked separately for push — they are derived from
 * the two things the polled feed already keys on: the
 * federation_updated_at write path (content and lifecycle changes, via
 * the listing actions) and the per-partner visibility events (shares,
 * unshares, grant refreshes). Each source queues one delivery per
 * subscribed partner; the delivery worker asks payloadFor() at send
 * time, which makes the same decision as the feed for that
 * (listing, partner) pair, so a retried delivery carries the current
 * state rather than a stale snapshot and the consumer can deduplicate
 * on (id, updated_at).
 *
 * Offering the feature is a setting, off by default: deliveries retry
 * for up to 24 hours from wp-cron, and a visit-triggered scheduler is
 * no delivery worker — a real system cron is the precondition for
 * advertising it, not a nicety.
 *
 * // api-design.md §Subscriptions
 */
final class SubscriptionService
{
    /**
     * A change is delivered a few seconds after it happens, so a save
     * that touches a listing several times sends once.
     */
    public const ENQUEUE_DELAY_SECONDS = 5;

    /**
     * @param bool|null $offered whether this node offers subscriptions;
     *                           read from the setting when null (tests
     *                           pass it explicitly)
     * @param Closure(int): void|null $scheduleDrain asks the delivery
     *                                               worker to run at the
     *                                               given timestamp
     */
    public function __construct(
        private readonly PartnerRepository $partners,
        private readonly PushDeliveryRepository $queue,
        private readonly SharingService $sharing,
        private readonly VisibilityEventRepository $events,
        private readonly ListingSerializer $serializer,
        private readonly Logger $logger,
        private readonly OutboundUrlGuard $guard = new OutboundUrlGuard(),
        private readonly ?bool $offered = null,
        private readonly ?Closure $scheduleDrain = null,
    ) {
    }

    public function offersSubscriptions(): bool
    {
        return $this->offered ?? (bool) Settings::get('push_subscriptions');
    }

    /**
     * Whether a delivery to this partner would go out right now: the
     * feature is offered and the partner holds a callback and is still
     * verified (a partner blocked or downgraded after subscribing stops
     * receiving at once).
     */
    public function deliversTo(Partner $partner): bool
    {
        return $this->offersSubscriptions() && $partner->receivesPushes();
    }

    /**
     * Register (or replace) the partner's callback. The URL is
     * partner-supplied and this node will POST to it from the server, so
     * it passes the outbound guard before it is stored (FP-14).
     *
     * @throws BlockedOutboundHost when the callback is not a plain HTTPS URL on a public host
     */
    public function register(Partner $partner, string $callbackUrl): void
    {
        $this->guard->assertPublicHttpsUrl($callbackUrl);

        $this->partners->update($partner->id, [
            'push_callback_url' => $callbackUrl,
            'push_callback_registered_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->logger->log(
            'partner',
            "{$partner->domain} subscribed to pushes at {$callbackUrl}",
            'subscription_registered',
            $partner->id,
            '/openyacht/v1/subscriptions',
            ['callback' => $callbackUrl, 'replaced' => $partner->pushCallbackUrl],
        );
    }

    /**
     * Remove the partner's callback and anything still queued for it.
     */
    public function unregister(Partner $partner): void
    {
        if ($partner->pushCallbackUrl === null) {
            return;
        }

        $this->partners->update($partner->id, [
            'push_callback_url' => null,
            'push_callback_registered_at' => null,
        ]);
        $this->queue->deleteForPartner($partner->id);

        $this->logger->log('partner', "{$partner->domain} unsubscribed from pushes", 'subscription_removed', $partner->id, '/openyacht/v1/subscriptions');
    }

    /**
     * A listing's federation_updated_at moved (content edit, lifecycle
     * transition, first publication): queue a delivery to every
     * subscribed partner. Which partners actually receive anything is
     * decided per pair at send time — a partner the listing is not
     * visible to gets nothing, the same as its feed.
     *
     * @return int deliveries queued
     */
    public function listingChanged(Listing $listing): int
    {
        // Drafts are never distributed (LS-7); nothing to deliver.
        if ($listing->status === ListingStatus::Draft || ! $this->offersSubscriptions()) {
            return 0;
        }

        $now = gmdate('Y-m-d H:i:s');
        $queued = 0;

        foreach ($this->partners->all() as $partner) {
            if ($partner->receivesPushes()) {
                $this->queue->enqueue($partner->id, $listing->id, $now);
                $queued++;
            }
        }

        if ($queued > 0) {
            $this->requestDrain();
        }

        return $queued;
    }

    /**
     * A visibility transition was recorded for one partner: queue a
     * delivery to that partner alone — hidden becomes a tombstone,
     * visible and refreshed resend the listing.
     */
    public function visibilityChanged(int $listingId, int $partnerId): void
    {
        $partner = $this->partners->find($partnerId);

        if ($partner === null || ! $this->deliversTo($partner)) {
            return;
        }

        $this->queue->enqueue($partnerId, $listingId, gmdate('Y-m-d H:i:s'));
        $this->requestDrain();
    }

    /**
     * What to deliver to one partner for one listing right now — the
     * feed's per-row decision computed for a single pair: nothing for a
     * draft or a listing the partner never saw; a tombstone stamped at
     * the transition for one that became invisible; the listing (or its
     * ended-status tombstone) otherwise. The effective timestamp is the
     * later of federation_updated_at and the latest visibility event, as
     * in the feed.
     *
     * @return array<string, mixed>|null
     */
    public function payloadFor(Listing $listing, Partner $partner): ?array
    {
        if ($listing->status === ListingStatus::Draft || $listing->federationUpdatedAt === null) {
            return null;
        }

        $latest = $this->events->latestFor($listing->id, $partner->id);
        $visible = $this->sharing->isVisibleTo($listing, $partner);

        // Invisible and never hidden: the partner never saw it, and its
        // feed would not list it either.
        if (! $visible && ($latest['event'] ?? null) !== VisibilityEventRepository::HIDDEN) {
            return null;
        }

        $effectiveUpdatedAt = $latest !== null && $latest['occurred_at'] > $listing->federationUpdatedAt
            ? $latest['occurred_at']
            : $listing->federationUpdatedAt;

        return $this->serializer->feedItem(
            new FeedItem($listing, $visible, $effectiveUpdatedAt, $latest['event'] ?? null),
            $partner,
        );
    }

    private function requestDrain(): void
    {
        if ($this->scheduleDrain !== null) {
            ($this->scheduleDrain)(time() + self::ENQUEUE_DELAY_SECONDS);
        }
    }
}
