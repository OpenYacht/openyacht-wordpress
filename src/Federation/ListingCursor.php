<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Opaque cursor pagination for /listings (API-2). Keyset-based on
 * (federation_updated_at, id) so concurrent writes never skip or
 * duplicate rows — the reason offset pagination is not used.
 *
 * // api-design.md §Listings
 */
final class ListingCursor
{
    /**
     * @param array{updated_at: string, id: int} $position RFC 3339 updated_at
     */
    public static function encode(array $position): string
    {
        return base64_encode((string) json_encode($position));
    }

    /**
     * @return array{updated_at: string, id: int}|null stored-format ('Y-m-d H:i:s' UTC) position
     */
    public static function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode($cursor, true);

        if ($decoded === false) {
            return null;
        }

        $position = json_decode($decoded, true);

        if (! is_array($position) || ! is_string($position['updated_at'] ?? null) || ! is_int($position['id'] ?? null)) {
            return null;
        }

        $timestamp = strtotime($position['updated_at']);

        if ($timestamp === false) {
            return null;
        }

        return [
            'updated_at' => gmdate('Y-m-d H:i:s', $timestamp),
            'id' => $position['id'],
        ];
    }
}
