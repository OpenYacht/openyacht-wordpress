<?php

declare(strict_types=1);

namespace OpenYacht\Http;

use OpenYacht\Federation\ErrorCode;

/**
 * Emits federation JSON responses directly (the dispatcher bypasses
 * WP_REST_Request on purpose — see Router), including the error envelope
 * with its defined codes and HTTP mappings (API-9).
 *
 * // api-design.md §Errors
 */
final class JsonResponder
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function send(array $payload, int $status = 200): never
    {
        nocache_headers();
        status_header($status);
        header('Content-Type: application/json; charset=utf-8');

        echo wp_json_encode($payload, JSON_UNESCAPED_SLASHES);

        exit;
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(ErrorCode $code, string $message, array $details = []): never
    {
        self::send([
            'error' => [
                'code' => $code->value,
                'message' => $message,
                'details' => $details === [] ? ['well_known' => '/.well-known/openyacht'] : $details,
            ],
            'meta' => [
                'request_id' => wp_generate_uuid4(),
                'time' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ], $code->httpStatus());
    }
}
