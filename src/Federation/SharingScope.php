<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Per-partner sharing scope: how a partner's feed composes with listing
 * audiences. A standard partner receives every everyone-audience listing
 * plus anything explicitly selected for it. A curated partner (a yacht
 * show organiser, a trial, a press feed) receives ONLY listings
 * explicitly selected for it — directly or via a group — regardless of
 * audience. Explicit pivots are additive, so curating one partner never
 * changes what anyone else sees.
 *
 * // wordpress-plugin-notes.md §Granular sharing
 */
enum SharingScope: string
{
    case Standard = 'standard';
    case Curated = 'curated';
}
