<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Federation\Logger;

final class CollectingLogger implements Logger
{
    /** @var list<array<string, mixed>> */
    public array $entries = [];

    public function log(
        string $channel,
        string $message,
        ?string $outcome = null,
        ?int $partnerId = null,
        ?string $endpoint = null,
        array $context = [],
    ): void {
        $this->entries[] = compact('channel', 'message', 'outcome', 'partnerId', 'endpoint', 'context');
    }
}
