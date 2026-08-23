<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * Builds the canonical signing string for federation requests.
 *
 * The signing string is the UTF-8 concatenation, joined by single `\n`
 * (0x0A) characters with no trailing newline, of:
 *
 *   1. uppercase HTTP method
 *   2. request path including query string
 *   3. lowercase host of the receiving node
 *   4. the X-OpenYacht-Timestamp value
 *   5. lowercase hex SHA-256 of the raw request body
 *      (the hash of the empty string for bodyless requests)
 *
 * // federation-protocol.md §Request Signing
 * // signing-test-vectors.md §Signing-string construction
 */
final class SigningString
{
    public static function build(
        string $method,
        string $pathWithQuery,
        string $receivingHost,
        string $timestamp,
        string $rawBody = '',
    ): string {
        return implode("\n", [
            strtoupper($method),
            $pathWithQuery,
            strtolower($receivingHost),
            $timestamp,
            hash('sha256', $rawBody),
        ]);
    }
}
