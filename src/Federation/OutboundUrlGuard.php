<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * SSRF guard for every server-side federation fetch (well-known discovery,
 * signed sync, media import). A federation host arrives from an
 * unauthenticated header, a partner's discovery document, or a partner's
 * listing payload, so it is untrusted and must never be able to steer a
 * request at internal infrastructure.
 *
 * Two layers:
 *   1. Shape — a bare DNS hostname only. No scheme, userinfo, port, or path
 *      (which blocks `127.0.0.1:6379`, `host:8080/x?`, `a@b`), and no IP
 *      literal (which blocks `169.254.169.254`, `[::1]`).
 *   2. Resolution — the name must not resolve into a private, loopback,
 *      link-local (cloud metadata lives at 169.254.169.254), or reserved
 *      range. A name that does not resolve is left to fail naturally at
 *      connect time rather than blocked here, so faked/offline hosts are
 *      not special-cased.
 *
 * Residual: DNS rebinding (a name public at check, private at connect) is
 * not closed here; pinning the resolved IP into the socket would be the
 * next step. Redirects are disabled at each call site so a public host
 * cannot bounce the request to a private one.
 *
 * // federation-protocol.md §Untrusted Input (FP-14)
 */
final class OutboundUrlGuard
{
    private const HOSTNAME = '/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i';

    /**
     * Assert a full URL is a plain HTTPS request to a public host.
     *
     * @throws BlockedOutboundHost
     */
    public function assertPublicHttpsUrl(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || ! isset($parts['host'])) {
            throw new BlockedOutboundHost("[{$url}] is not a plain https URL.");
        }

        $this->assertPublicHost($parts['host']);
    }

    /**
     * Assert a bare hostname is well-formed and resolves only to public
     * addresses.
     *
     * @throws BlockedOutboundHost
     */
    public function assertPublicHost(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new BlockedOutboundHost("[{$host}] is an IP literal; a hostname is required.");
        }

        if (preg_match(self::HOSTNAME, $host) !== 1) {
            throw new BlockedOutboundHost("[{$host}] is not a valid hostname.");
        }

        foreach ($this->resolve($host) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new BlockedOutboundHost("[{$host}] resolves to a non-public address [{$ip}].");
            }
        }
    }

    /**
     * Resolve a hostname to its A and AAAA records. An unresolvable name
     * returns an empty list — the connection then fails on its own.
     *
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        $ipv4 = gethostbynamel($host) ?: [];

        $ipv6 = [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ipv6[] = $record['ipv6'];
            }
        }

        return array_values(array_unique([...$ipv4, ...$ipv6]));
    }
}
