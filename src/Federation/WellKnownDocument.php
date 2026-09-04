<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds the node's discovery document served at /.well-known/openyacht.
 *
 * The endpoints map only advertises endpoints this node actually serves;
 * `subscriptions` is added when the optional subscriptions feature is
 * implemented and advertised in capabilities.
 *
 * // federation-protocol.md §Discovery: the well-known endpoint
 */
final class WellKnownDocument
{
    public function __construct(private readonly KeyManager $keys)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'openyacht' => '1.0',
            'protocol_versions' => NodeConfig::PROTOCOL_VERSIONS,
            'node' => [
                'uuid' => NodeConfig::nodeUuid(),
                'name' => NodeConfig::nodeName(),
                'software' => NodeConfig::software(),
                'website' => NodeConfig::website(),
            ],
            'keys' => array_map(
                static fn (FederationKey $key): array => [
                    'key_id' => $key->keyId,
                    'algorithm' => 'ed25519',
                    'public_key' => $key->publicKey,
                    'created_at' => self::rfc3339($key->createdAt),
                ],
                $this->keys->publishedKeys(),
            ),
            'endpoints' => [
                'listings' => '/openyacht/v1/listings',
                'partners' => '/openyacht/v1/partners',
                'health' => '/openyacht/v1/health',
                'capabilities' => '/openyacht/v1/capabilities',
            ],
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Storage keeps app-managed 'Y-m-d H:i:s' UTC datetimes; the wire wants
     * RFC 3339 Z form.
     */
    private static function rfc3339(?string $storedDatetime): ?string
    {
        if ($storedDatetime === null) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $storedDatetime,
            new DateTimeZone('UTC'),
        );

        return $parsed === false ? null : $parsed->format('Y-m-d\TH:i:s\Z');
    }
}
