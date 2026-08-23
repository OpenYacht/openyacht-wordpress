<?php

declare(strict_types=1);

namespace OpenYacht\Media;

use OpenYacht\Admin\Settings;
use OpenYacht\Federation\ListingCopy;

/**
 * Which copies get their media cached locally. Everything syncs as
 * lightweight JSON (the 24-hour freshness obligation applies to data);
 * caching thousands of partner images is an import decision — the
 * default caches only copies selected for import, previews use the
 * authority-hosted thumbnail from the wire.
 */
final class MediaPolicy
{
    public const SELECTED = 'selected';

    public const ALL = 'all';

    public static function shouldCache(ListingCopy $copy): bool
    {
        return self::current() === self::ALL || $copy->isSelected();
    }

    public static function current(): string
    {
        $policy = Settings::get('media_policy');

        return $policy === self::ALL ? self::ALL : self::SELECTED;
    }
}
