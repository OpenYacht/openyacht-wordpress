<?php

declare(strict_types=1);

namespace OpenYacht\Media;

/**
 * Downloads a partner-referenced image under the FP-14 rules: HTTPS only,
 * the response must be an image, size-capped, and verified against the
 * published sha256 whenever the authority provided one. Inbound listing
 * content is untrusted — these checks are the trust boundary.
 *
 * // conformance: FP-14
 */
class ImageFetcher
{
    public const MAX_BYTES = 30 * 1024 * 1024;

    /**
     * @return string the image bytes
     *
     * @throws MediaFetchException
     */
    public function fetch(string $url, ?string $expectedSha256 = null): string
    {
        if (! str_starts_with(strtolower($url), 'https://')) {
            throw new MediaFetchException("Refusing non-HTTPS media URL [{$url}].");
        }

        $response = wp_remote_get($url, [
            'timeout' => 60,
            'sslverify' => true,
            'limit_response_size' => self::MAX_BYTES + 1,
        ]);

        if (is_wp_error($response)) {
            throw new MediaFetchException("Could not fetch media [{$url}]: " . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status !== 200) {
            throw new MediaFetchException("Could not fetch media [{$url}]: HTTP {$status}.");
        }

        $contentType = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));

        if (! str_starts_with($contentType, 'image/')) {
            throw new MediaFetchException("Media [{$url}] is not an image (Content-Type: {$contentType}).");
        }

        $bytes = (string) wp_remote_retrieve_body($response);

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new MediaFetchException("Media [{$url}] exceeds the size cap.");
        }

        if ($expectedSha256 !== null && ! hash_equals(strtolower($expectedSha256), hash('sha256', $bytes))) {
            throw new MediaFetchException("Media [{$url}] failed sha256 verification.");
        }

        return $bytes;
    }
}
