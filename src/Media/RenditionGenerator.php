<?php

declare(strict_types=1);

namespace OpenYacht\Media;

/**
 * The wire carries one large image per media item; receiving nodes
 * generate their own derived sizes (listing-schema.md §Media). Bytes in,
 * manifest of temp files out — implementations never touch storage or the
 * database; the caller uploads the manifest and cleans up.
 */
interface RenditionGenerator
{
    /**
     * @return array<string, array{path: string, width: int, height: int}>
     *         rendition key (w480, crop_960, …) => local temp file manifest
     */
    public function generate(string $bytes, bool $profileCrops = false): array;
}
