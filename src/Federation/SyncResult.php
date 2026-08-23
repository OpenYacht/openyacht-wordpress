<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

final class SyncResult
{
    public function __construct(
        public readonly int $created,
        public readonly int $updated,
        public readonly int $tombstoned,
    ) {
    }
}
