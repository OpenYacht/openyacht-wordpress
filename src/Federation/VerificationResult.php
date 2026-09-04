<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Outcome of verifying an inbound federation request signature.
 */
final class VerificationResult
{
    private function __construct(
        public readonly bool $verified,
        public readonly ?ErrorCode $error,
    ) {
    }

    public static function passed(): self
    {
        return new self(verified: true, error: null);
    }

    public static function failed(ErrorCode $error): self
    {
        return new self(verified: false, error: $error);
    }
}
