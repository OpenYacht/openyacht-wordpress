<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Outcome of authenticating an inbound federation request.
 */
final class InboundResult
{
    private function __construct(
        public readonly ?Partner $partner,
        public readonly ?ErrorCode $error,
        public readonly string $message,
    ) {
    }

    public static function ok(Partner $partner): self
    {
        return new self($partner, null, '');
    }

    public static function rejected(ErrorCode $error, string $message): self
    {
        return new self(null, $error, $message);
    }

    public function verified(): bool
    {
        return $this->error === null;
    }
}
