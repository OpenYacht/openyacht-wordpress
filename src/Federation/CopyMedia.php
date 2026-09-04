<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One cached media item of a synced copy: its wire identity (source URL +
 * published sha256) and the locally generated renditions.
 */
final class CopyMedia
{
    /**
     * @param array<string, array{path: string, width: int, height: int}> $renditions
     */
    public function __construct(
        public readonly int $id,
        public readonly int $copyId,
        public readonly string $kind,
        public readonly string $sourceUrl,
        public readonly ?string $sourceSha256,
        public readonly ?string $caption,
        public readonly int $sort,
        public readonly array $renditions,
    ) {
    }
}
