<?php

declare(strict_types=1);

namespace OpenYacht\Http;

if (! defined('ABSPATH')) {
    exit;
}

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
     * Responses are uncacheable by default: the signed listing surface is
     * filtered per partner, so a shared cache entry would serve one
     * partner's view to another. Only the unsigned public documents pass a
     * $cacheSeconds, and they say so explicitly.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public static function send(array $payload, int $status = 200, array $headers = [], int $cacheSeconds = 0): never
    {
        if ($cacheSeconds > 0) {
            header('Cache-Control: public, max-age=' . $cacheSeconds);
        } else {
            nocache_headers();
        }

        status_header($status);
        header('Content-Type: application/json; charset=utf-8');

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo wp_json_encode($payload, JSON_UNESCAPED_SLASHES);

        exit;
    }

    /**
     * @param array<string, mixed> $details
     * @param array<string, string> $headers
     */
    public static function error(ErrorCode $code, string $message, array $details = [], array $headers = []): never
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
        ], $code->httpStatus(), $headers);
    }
}
