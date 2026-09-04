<?php

declare(strict_types=1);

namespace OpenYacht\Media;

if (! defined('ABSPATH')) {
    exit;
}

use RuntimeException;

final class MediaFetchException extends RuntimeException
{
}
