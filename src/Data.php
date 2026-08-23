<?php

declare(strict_types=1);

namespace OpenYacht;

use OpenYacht\Federation\CopyMedia;
use OpenYacht\Federation\ListingCopy;
use OpenYacht\Federation\Partner;

/**
 * The theme-facing read API: the one supported surface for rendering
 * synced listings. Themes and developer bridges call this — never the
 * plugin's tables directly — and the paid display addon consumes exactly
 * the same surface.
 */
final class Data
{
    /**
     * Live (non-tombstoned) synced copies, newest listing change first.
     *
     * @return list<ListingCopy>
     */
    public static function copies(): array
    {
        return Services::copies()->active();
    }

    /**
     * Dereference a canonical listing URI (opaque-string comparison, ID-2).
     */
    public static function copy(string $canonicalUri): ?ListingCopy
    {
        return Services::copies()->findByUri($canonicalUri);
    }

    /**
     * The partner a copy came from — attribution per its usage terms is
     * the consumer's obligation (ID-10).
     */
    public static function partner(ListingCopy $copy): ?Partner
    {
        return Services::partners()->find($copy->partnerId);
    }

    /**
     * A partner unreachable beyond the staleness threshold marks its
     * copies stale in any consuming UI (FP-15).
     */
    public static function isStale(ListingCopy $copy): bool
    {
        return self::partner($copy)?->isStale() ?? false;
    }

    /**
     * Cached media for a copy with rendition URLs, ordered profile first
     * then gallery by sort.
     *
     * @return list<array{kind: string, caption: ?string, sort: int, renditions: array<string, array{url: string, width: int, height: int}>}>
     */
    public static function media(ListingCopy $copy): array
    {
        $service = Services::mediaService();

        return array_map(
            static fn (CopyMedia $item): array => [
                'kind' => $item->kind,
                'caption' => $item->caption,
                'sort' => $item->sort,
                'renditions' => $service->urls($item),
            ],
            Services::copyMedia()->forCopy($copy->id),
        );
    }
}
