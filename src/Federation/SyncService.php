<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use RuntimeException;
use Throwable;

/**
 * Pull-based listing sync: cold full sync on first run, incremental
 * updated_since polling afterwards (API-2). Tombstones mark copies
 * withdrawn/sold and remove them from public display (ID-7); the
 * updated_since watermark is the authority's meta.generated_at so clock
 * skew between nodes cannot open a gap.
 *
 * Every change fires an action (openyacht_copy_created / _updated /
 * _tombstoned) — the public surface the display addon and developer
 * bridges build on.
 *
 * // api-design.md §Listings
 * // yacht-identity.md §What everyone else holds
 */
final class SyncService
{
    /**
     * A push-subscribed partner is still polled to reconcile, once a day
     * (API-11 RECOMMENDED), because missed webhooks are a fact of life.
     */
    public const RECONCILIATION_INTERVAL_SECONDS = 24 * 3600;

    public const RECONCILIATION_TOLERANCE_SECONDS = 5 * 60;

    public function __construct(
        private readonly FederationClient $client,
        private readonly PartnerRepository $partners,
        private readonly CopyRepository $copies,
    ) {
    }

    /**
     * @throws PartnerAwaitingApproval when the partner has not approved this
     *                                 node yet — not recorded as a failure
     * @throws Throwable on sync failure, after recording the failed attempt
     */
    public function sync(Partner $partner): SyncResult
    {
        $this->partners->update($partner->id, ['last_attempted_at' => gmdate('Y-m-d H:i:s')]);

        try {
            return $this->pullAllPages($partner);
        } catch (PartnerAwaitingApproval $awaiting) {
            // "Authenticated but not yet approved" is the handshake working
            // as designed on both sides. Counting it would compound the
            // backoff while a human is simply yet to click approve, and
            // leave this node idle for hours after they do.
            throw $awaiting;
        } catch (Throwable $exception) {
            $this->partners->incrementFailures($partner->id);

            throw $exception;
        }
    }

    /**
     * Exponential backoff between failed attempts, capped at 24 hours;
     * then, for a partner whose changes arrive by push, the daily
     * reconciliation interval measured from the last successful poll.
     *
     * // federation-protocol.md §Health and Failure Handling
     * // api-design.md §Subscriptions
     */
    public function isDue(Partner $partner, ?int $nowTimestamp = null): bool
    {
        $now = $nowTimestamp ?? time();

        if ($partner->consecutiveFailures > 0 && $partner->lastAttemptedAt !== null) {
            $lastAttempt = strtotime($partner->lastAttemptedAt . ' UTC');
            $delaySeconds = min(3600 * (2 ** ($partner->consecutiveFailures - 1)), 86400);

            if ($lastAttempt !== false && $lastAttempt + $delaySeconds >= $now) {
                return false;
            }
        }

        if ($partner->isPushSubscribed() && $partner->lastOkAt !== null) {
            $lastOk = strtotime($partner->lastOkAt . ' UTC');

            return $lastOk === false
                || $lastOk + self::RECONCILIATION_INTERVAL_SECONDS - self::RECONCILIATION_TOLERANCE_SECONDS < $now;
        }

        return true;
    }

    /**
     * Apply one pushed listing or tombstone (API-11). Deliveries are
     * at-least-once, so a repeat is acknowledged and ignored — but a
     * repeat is decided against the state the copy already holds, not a
     * receipt log: the re-share case delivers the listing again with its
     * own unchanged updated_at after a tombstone carried the transition
     * time, and that MUST apply. Everything else goes through the same
     * path as a polled item.
     *
     * @param array<string, mixed> $item
     * @return string created|updated|tombstoned|duplicate|skipped
     */
    public function applyDelivery(Partner $partner, array $item): string
    {
        return $this->alreadyApplied($partner, $item) ? 'duplicate' : $this->apply($partner, $item);
    }

    /**
     * Whether the stored copy already reflects this delivery: same
     * updated_at, and tombstoned iff the delivery is a tombstone.
     *
     * @param array<string, mixed> $item
     */
    private function alreadyApplied(Partner $partner, array $item): bool
    {
        $canonicalUri = $item['id'] ?? null;
        $updatedAt = $item['updated_at'] ?? null;

        if (! is_string($canonicalUri) || ! is_string($updatedAt)) {
            return false;
        }

        $copy = $this->copies->findForPartner($partner->id, $canonicalUri);

        if ($copy === null || $copy->listingUpdatedAt === null) {
            return false;
        }

        $delivered = strtotime($updatedAt);
        $held = self::timestamp($copy->listingUpdatedAt);

        return $delivered !== false
            && $held !== false
            && $delivered === $held
            && ($copy->tombstonedAt !== null) === (($item['tombstone'] ?? false) === true);
    }

    /**
     * Stored copies hold app-managed 'Y-m-d H:i:s' UTC datetimes; the
     * wire form (and the in-memory test double) carries RFC 3339.
     */
    private static function timestamp(string $value): int|false
    {
        return strtotime(preg_match('/[TZ]/', $value) === 1 ? $value : $value . ' UTC');
    }

    private function pullAllPages(Partner $partner): SyncResult
    {
        $created = $updated = $tombstoned = 0;
        $watermark = null;
        $cursor = null;

        do {
            $query = array_filter([
                'page_size' => 100,
                'updated_since' => $this->watermarkParam($partner),
                'cursor' => $cursor,
            ]);

            $response = $this->client->get($partner, '/openyacht/v1/listings?' . http_build_query($query));

            if (! $response->ok()) {
                if ($this->isAwaitingApproval($response)) {
                    throw new PartnerAwaitingApproval("{$partner->domain} has not approved this node yet; nothing to sync until it does.");
                }

                throw new RuntimeException(
                    "Sync with {$partner->domain} failed: HTTP {$response->status} " . substr($response->body, 0, 500),
                );
            }

            $page = $response->json();

            if ($page === null) {
                throw new RuntimeException("Sync with {$partner->domain} failed: response is not valid JSON.");
            }

            foreach (is_array($page['data'] ?? null) ? $page['data'] : [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                match ($this->apply($partner, $item)) {
                    'created' => $created++,
                    'updated' => $updated++,
                    'tombstoned' => $tombstoned++,
                    default => null,
                };
            }

            $watermark ??= is_string($page['meta']['generated_at'] ?? null) ? $page['meta']['generated_at'] : null;
            $cursor = is_string($page['meta']['next_cursor'] ?? null) ? $page['meta']['next_cursor'] : null;
        } while ($cursor !== null);

        $watermarkTimestamp = $watermark !== null ? strtotime($watermark) : false;

        $this->partners->update($partner->id, [
            'last_ok_at' => gmdate('Y-m-d H:i:s'),
            'consecutive_failures' => 0,
            'last_synced_at' => gmdate('Y-m-d H:i:s', $watermarkTimestamp === false ? time() : $watermarkTimestamp),
        ]);

        return new SyncResult($created, $updated, $tombstoned);
    }

    /**
     * Deliberately narrow: a 403 carrying PARTNER_PROVISIONAL and nothing
     * else. Any other refusal (PARTNER_BLOCKED above all) is a failure
     * and backs off as before.
     *
     * // api-design.md §Errors
     */
    private function isAwaitingApproval(HttpResponse $response): bool
    {
        if ($response->status !== ErrorCode::PartnerProvisional->httpStatus()) {
            return false;
        }

        return ($response->json()['error']['code'] ?? null) === ErrorCode::PartnerProvisional->value;
    }

    private function watermarkParam(Partner $partner): ?string
    {
        if ($partner->lastSyncedAt === null) {
            return null;
        }

        $timestamp = strtotime($partner->lastSyncedAt . ' UTC');

        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * Apply one listing or tombstone to the copies table. The canonical
     * URI is compared as an opaque string (ID-2); copies carry the
     * mandatory provenance fields (ID-3) and are stored verbatim, never
     * substantively modified (ID-5).
     *
     * @param array<string, mixed> $item
     */
    private function apply(Partner $partner, array $item): string
    {
        $canonicalUri = $item['id'] ?? null;

        if (! is_string($canonicalUri)) {
            return 'skipped';
        }

        if (($item['tombstone'] ?? false) === true) {
            $status = ListingStatus::tryFrom((string) ($item['status'] ?? '')) ?? ListingStatus::Withdrawn;
            $copy = $this->copies->tombstone(
                $partner->id,
                $canonicalUri,
                $status->value,
                is_string($item['updated_at'] ?? null) ? $item['updated_at'] : null,
            );

            if ($copy !== null) {
                // The listing ended: projections and cached media must go
                // with it, per the usage terms (ID-7, ID-10).
                do_action('openyacht_copy_tombstoned', $copy);
            }

            return 'tombstoned';
        }

        ['copy' => $copy, 'created' => $wasCreated] = $this->copies->upsert($partner, $canonicalUri, $item);

        do_action($wasCreated ? 'openyacht_copy_created' : 'openyacht_copy_updated', $copy);

        return $wasCreated ? 'created' : 'updated';
    }
}
