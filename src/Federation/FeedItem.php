<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * One row of a partner's updated_since feed: the listing plus its
 * visibility to that partner and the effective change time (the later of
 * the listing's own federation_updated_at and the partner's latest
 * visibility transition — so unshare/re-share surfaces at the moment it
 * happened, whatever the content's timestamp).
 */
final class FeedItem
{
    public function __construct(
        public readonly Listing $listing,
        public readonly bool $visible,
        public readonly string $effectiveUpdatedAt,
        public readonly ?string $lastEvent,
    ) {
    }
}
