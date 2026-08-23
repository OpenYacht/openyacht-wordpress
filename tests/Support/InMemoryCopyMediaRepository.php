<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Federation\CopyMedia;
use OpenYacht\Federation\CopyMediaRepository;

final class InMemoryCopyMediaRepository implements CopyMediaRepository
{
    /** @var list<CopyMedia> */
    public array $items = [];

    private int $nextId = 1;

    public function forCopy(int $copyId): array
    {
        $items = array_values(array_filter(
            $this->items,
            static fn (CopyMedia $item): bool => $item->copyId === $copyId,
        ));

        usort($items, static fn (CopyMedia $a, CopyMedia $b): int => [$a->kind !== 'profile', $a->sort] <=> [$b->kind !== 'profile', $b->sort]);

        return $items;
    }

    public function insert(
        int $copyId,
        string $kind,
        string $sourceUrl,
        ?string $sourceSha256,
        ?string $caption,
        int $sort,
        array $renditions,
    ): void {
        $this->items[] = new CopyMedia($this->nextId++, $copyId, $kind, $sourceUrl, $sourceSha256, $caption, $sort, $renditions);
    }

    public function deleteForCopy(int $copyId): void
    {
        $this->items = array_values(array_filter(
            $this->items,
            static fn (CopyMedia $item): bool => $item->copyId !== $copyId,
        ));
    }
}
