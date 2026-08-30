<?php

declare(strict_types=1);

namespace OpenYacht\Http;

use OpenYacht\Federation\ErrorCode;
use OpenYacht\Federation\FeedItem;
use OpenYacht\Federation\ListingCursor;
use OpenYacht\Federation\ListingRepository;
use OpenYacht\Federation\ListingSerializer;
use OpenYacht\Federation\ListingStatus;
use OpenYacht\Federation\NodeConfig;
use OpenYacht\Federation\Partner;
use OpenYacht\Federation\SharingService;

/**
 * Serves this node's own listings to verified partners. Copies of other
 * nodes' listings live in their own table and are never served from here
 * (ID-4); drafts are never distributed (LS-7); updated_since results
 * include tombstones for every listing that became invisible (API-3).
 *
 * Pure request-to-payload logic: the Router authenticates, rate-limits,
 * and emits. Errors return as {error, message}; success as
 * {payload, status}.
 *
 * // api-design.md §Listings
 */
final class ListingsEndpoint
{
    public const TERMINAL_RETENTION_MONTHS = 12;

    public function __construct(
        private readonly ListingRepository $listings,
        private readonly ListingSerializer $serializer,
        private readonly SharingService $sharing,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{payload?: array<string, mixed>, status?: int, error?: ErrorCode, message?: string}
     */
    public function index(Partner $partner, array $query): array
    {
        $pageSize = min(max((int) ($query['page_size'] ?? 50), 1), NodeConfig::PAGE_SIZE_MAX);
        $updatedSinceStored = null;

        if (isset($query['updated_since']) && $query['updated_since'] !== '') {
            $timestamp = strtotime((string) $query['updated_since']);

            if ($timestamp === false) {
                return ['error' => ErrorCode::ValidationError, 'message' => 'updated_since must be an RFC 3339 timestamp.'];
            }

            $updatedSinceStored = gmdate('Y-m-d H:i:s', $timestamp);
        }

        $cursor = ListingCursor::decode(isset($query['cursor']) ? (string) $query['cursor'] : null);
        $rows = $this->listings->feedPage($partner->id, $partner->sharingScope, $updatedSinceStored, $cursor, $pageSize);
        $hasMore = count($rows) > $pageSize;
        $page = array_slice($rows, 0, $pageSize);

        $meta = [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'protocol_version' => '1.0',
        ];

        if ($hasMore && $page !== []) {
            $last = end($page);
            $meta['next_cursor'] = ListingCursor::encode([
                'updated_at' => $this->rfc3339($last->effectiveUpdatedAt),
                'id' => $last->listing->id,
            ]);
        }

        return [
            'payload' => [
                'data' => array_map(
                    function (FeedItem $item) use ($partner): array {
                        if (! $item->visible) {
                            // Unshared: a tombstone indistinguishable from a
                            // real withdrawal, timestamped at the transition.
                            return $this->serializer->tombstone(
                                $item->listing,
                                $item->listing->isTerminal() ? null : ListingStatus::Withdrawn->value,
                                $item->effectiveUpdatedAt,
                            );
                        }

                        return $item->listing->isTerminal()
                            ? $this->serializer->tombstone($item->listing)
                            : $this->serializer->serialize($item->listing, $partner);
                    },
                    $page,
                ),
                'meta' => $meta,
            ],
            'status' => 200,
        ];
    }

    /**
     * The dereference target for canonical URIs. Terminal listings stay
     * served (with status) for the retention window, then 410 Gone.
     *
     * @return array{payload?: array<string, mixed>, status?: int, error?: ErrorCode, message?: string}
     */
    public function show(Partner $partner, string $uuid): array
    {
        $listing = $this->listings->findByUuid($uuid);

        // Drafts are never distributed — and never revealed (LS-7). A
        // listing not shared with this partner is equally NOT_FOUND: the
        // same response as for a listing that does not exist (no leak).
        if ($listing === null
            || $listing->status === ListingStatus::Draft
            || ! $this->sharing->isVisibleTo($listing, $partner)) {
            return ['error' => ErrorCode::NotFound, 'message' => 'No such listing.'];
        }

        if ($listing->isTerminal() && $listing->federationUpdatedAt !== null) {
            $retentionEnds = strtotime($listing->federationUpdatedAt . ' UTC +' . self::TERMINAL_RETENTION_MONTHS . ' months');

            if ($retentionEnds !== false && $retentionEnds < time()) {
                return ['error' => ErrorCode::Gone, 'message' => 'This listing has ended and its retention window has passed.'];
            }
        }

        return ['payload' => $this->serializer->serialize($listing, $partner), 'status' => 200];
    }

    private function rfc3339(string $stored): string
    {
        $timestamp = strtotime($stored . ' UTC');

        return gmdate('Y-m-d\TH:i:s\Z', $timestamp === false ? time() : $timestamp);
    }
}
