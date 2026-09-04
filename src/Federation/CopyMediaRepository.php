<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

interface CopyMediaRepository
{
    /** @return list<CopyMedia> ordered by kind then sort */
    public function forCopy(int $copyId): array;

    /**
     * @param array<string, array{path: string, width: int, height: int}> $renditions
     */
    public function insert(
        int $copyId,
        string $kind,
        string $sourceUrl,
        ?string $sourceSha256,
        ?string $caption,
        int $sort,
        array $renditions,
    ): void;

    public function deleteForCopy(int $copyId): void;
}
