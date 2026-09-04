<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A node-defined grouping of partners (company sites, offices, third
 * parties, …). Groups are audience shorthand: a listing can select a
 * group instead of enumerating partners, and membership changes replay
 * through the visibility-event log like any other audience change.
 */
final class PartnerGroup
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {
    }
}
