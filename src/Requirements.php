<?php

declare(strict_types=1);

namespace OpenYacht;

/**
 * Runtime-floor checks: without sodium or TLS the plugin cannot run as a
 * conformant node (FP-2, FP-3), so activation refuses rather than degrades.
 */
final class Requirements
{
    /**
     * @return list<string> Human-readable blockers; empty when activation may proceed.
     */
    public function errors(): array
    {
        $errors = [];

        if (! \function_exists('sodium_crypto_sign_keypair')) {
            $errors[] = __('OpenYacht requires the PHP sodium extension (bundled with PHP since 7.2, but disabled on this server). Federation signing cannot work without it.', 'openyacht');
        }

        if (! $this->siteIsHttps() && ! $this->insecureHttpAllowed()) {
            $errors[] = __('OpenYacht requires this site to be served over HTTPS. Federation over plain HTTP is non-conformant, so the plugin refuses to activate. (For local development only, define OPENYACHT_ALLOW_INSECURE_HTTP as true.)', 'openyacht');
        }

        return $errors;
    }

    private function siteIsHttps(): bool
    {
        $scheme = parse_url(home_url('/'), PHP_URL_SCHEME);

        return is_string($scheme) && strtolower($scheme) === 'https';
    }

    private function insecureHttpAllowed(): bool
    {
        return defined('OPENYACHT_ALLOW_INSECURE_HTTP') && constant('OPENYACHT_ALLOW_INSECURE_HTTP');
    }
}
