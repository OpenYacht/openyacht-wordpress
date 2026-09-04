<?php

declare(strict_types=1);

namespace OpenYacht\Cli;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Federation\FederationKey;
use OpenYacht\Services;
use WP_CLI;

/**
 * Federation key management: `wp openyacht key <list|rotate|retire-overlapped>`.
 */
final class KeyCommand
{
    /**
     * List published federation keys (active and retiring).
     *
     * @subcommand list
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     */
    public function list_(array $args, array $assocArgs): void
    {
        $rows = array_map(static fn (FederationKey $key): array => [
            'key_id' => $key->keyId,
            'status' => $key->status->value,
            'public_key' => $key->publicKey,
            'created_at' => $key->createdAt ?? '',
        ], Services::keyManager()->publishedKeys());

        \WP_CLI\Utils\format_items('table', $rows, ['key_id', 'status', 'public_key', 'created_at']);
    }

    /**
     * Rotate the node's signing key.
     *
     * Routine rotation keeps the old key published as retiring for the
     * ~48h overlap window; emergency rotation revokes everything
     * immediately (partners recover via the well-known refetch).
     *
     * ## OPTIONS
     *
     * [--emergency]
     * : Revoke all existing keys immediately instead of overlapping.
     *
     * ## EXAMPLES
     *
     *     wp openyacht key rotate
     *     wp openyacht key rotate --emergency
     *
     * @param array<int, string> $args
     * @param array<string, string|bool> $assocArgs
     */
    public function rotate(array $args, array $assocArgs): void
    {
        $emergency = (bool) ($assocArgs['emergency'] ?? false);
        $key = $emergency
            ? Services::keyManager()->rotateEmergency()
            : Services::keyManager()->rotate();

        Services::logger()->log('keys', ($emergency ? 'Emergency' : 'Routine') . " key rotation; new active key {$key->keyId}", 'rotated');

        WP_CLI::success(($emergency ? 'Emergency rotation complete' : 'Rotated') . "; new active key: {$key->keyId}");

        if (! $emergency) {
            WP_CLI::line('Retire the old key after the overlap window: wp openyacht key retire-overlapped');
        }
    }

    /**
     * Revoke retiring keys once the rotation overlap window has passed.
     *
     * @subcommand retire-overlapped
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     */
    public function retireOverlapped(array $args, array $assocArgs): void
    {
        $count = Services::keyManager()->retireOverlappedKeys();

        WP_CLI::success("Revoked {$count} retiring key(s).");
    }
}
