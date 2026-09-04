<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use RuntimeException;

final class InvalidWellKnownDocument extends RuntimeException
{
}
