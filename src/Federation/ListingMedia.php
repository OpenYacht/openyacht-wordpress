<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * One media item of an own listing — the wire truth (URL, hash,
 * dimensions, caption, category) lives here regardless of whether the
 * file is a media-library attachment or an ingest-supplied URL the site
 * already hosts.
 */
final class ListingMedia
{
    public function __construct(
        public readonly int $id,
        public readonly int $listingId,
        public readonly string $kind,
        public readonly ?int $attachmentId,
        public readonly ?string $url,
        public readonly ?string $thumbnailUrl,
        public readonly ?string $sha256,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?string $caption,
        public readonly ?string $category,
        public readonly int $sort,
    ) {
    }
}
