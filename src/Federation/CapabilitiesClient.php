<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Fetches a partner's unsigned capabilities document — the check before
 * using an optional protocol feature (API-7). A partner that cannot be
 * read counts as not advertising anything.
 *
 * // api-design.md §Capabilities
 */
class CapabilitiesClient
{
    public function __construct(private readonly OutboundUrlGuard $guard = new OutboundUrlGuard())
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetch(string $domain): ?array
    {
        try {
            $this->guard->assertPublicHost($domain);
        } catch (BlockedOutboundHost) {
            return null;
        }

        $response = wp_remote_get("https://{$domain}/openyacht/v1/capabilities", [
            'timeout' => 15,
            'sslverify' => true,
            'redirection' => 0,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status < 200 || $status >= 300) {
            return null;
        }

        $document = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($document) ? $document : null;
    }

    public function advertises(string $domain, string $feature): bool
    {
        return ($this->fetch($domain)['features'][$feature] ?? null) === true;
    }
}
