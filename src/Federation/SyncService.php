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
    public function __construct(
        private readonly FederationClient $client,
        private readonly PartnerRepository $partners,
        private readonly CopyRepository $copies,
    ) {
    }

    /**
     * @throws Throwable on sync failure, after recording the failed attempt
     */
    public function sync(Partner $partner): SyncResult
    {
        $this->partners->update($partner->id, ['last_attempted_at' => gmdate('Y-m-d H:i:s')]);

        try {
            return $this->pullAllPages($partner);
        } catch (Throwable $exception) {
            $this->partners->incrementFailures($partner->id);

            throw $exception;
        }
    }

    /**
     * Exponential backoff between failed attempts, capped at 24 hours.
     *
     * // federation-protocol.md §Health and Failure Handling
     */
    public function isDue(Partner $partner, ?int $nowTimestamp = null): bool
    {
        if ($partner->consecutiveFailures === 0 || $partner->lastAttemptedAt === null) {
            return true;
        }

        $lastAttempt = strtotime($partner->lastAttemptedAt . ' UTC');

        if ($lastAttempt === false) {
            return true;
        }

        $delaySeconds = min(3600 * (2 ** ($partner->consecutiveFailures - 1)), 86400);

        return $lastAttempt + $delaySeconds < ($nowTimestamp ?? time());
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
