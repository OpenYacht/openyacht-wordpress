<?php

declare(strict_types=1);

/**
 * Minimal WP_Error stand-in for the no-WordPress unit lane.
 */
class WP_Error
{
    public function __construct(
        private readonly string $code = '',
        private readonly string $message = '',
        private readonly mixed $data = null,
    ) {
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_data(): mixed
    {
        return $this->data;
    }
}
