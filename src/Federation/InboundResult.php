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
    /**
     * @param bool $firstContact the sender was unknown and this request
     *                           registered it (trust on first use); a
     *                           caller that deferred the first-contact
     *                           action owes it now
     */
    private function __construct(
        public readonly ?Partner $partner,
        public readonly ?ErrorCode $error,
        public readonly string $message,
        public readonly bool $firstContact = false,
    ) {
    }

    public static function ok(Partner $partner, bool $firstContact = false): self
    {
        return new self($partner, null, '', $firstContact);
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
