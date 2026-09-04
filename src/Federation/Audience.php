<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Per-listing audience control: who a listing is shared with. Display and
 * sharing are orthogonal — a listing shared with no one is still
 * displayed locally; partners requesting its URI get NOT_FOUND.
 *
 * // wordpress-plugin-notes.md §Granular sharing
 */
enum Audience: string
{
    case Everyone = 'everyone';
    case Selected = 'selected';
    case None = 'none';
}
