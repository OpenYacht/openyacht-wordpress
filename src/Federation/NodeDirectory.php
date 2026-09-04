<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * The node-directory consent surface: signed listing requests for the
 * project's advisory node phonebook. The same Ed25519 key that signs
 * federation requests proves listing consent, so nobody can list — or
 * delist — a domain they do not control. The directory grants nothing;
 * this only produces the two lines the operator pastes into the listing
 * issue form.
 *
 * // federation-protocol.md §Finding partners: the node directory
 */
final class NodeDirectory
{
    public const ACTIONS = ['list', 'delist', 'amend'];

    public function __construct(private readonly KeyManager $keys)
    {
    }

    /**
     * @return array{token: string, signature: string}
     */
    public function listingRequest(string $action, ?DateTimeImmutable $date = null): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException("Unknown node-directory action [{$action}].");
        }

        $key = $this->keys->activeKey();

        if ($key === null) {
            throw new RuntimeException('No active federation key. Re-activate the OpenYacht plugin to generate one.');
        }

        $token = sprintf(
            'openyacht-node-listing:v1:%s:%s:%s',
            NodeConfig::identityDomain(),
            $action,
            ($date ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'),
        );

        return [
            'token' => $token,
            'signature' => base64_encode(sodium_crypto_sign_detached($token, $key->rawSecretKey())),
        ];
    }
}
