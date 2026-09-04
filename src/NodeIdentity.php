<?php

declare(strict_types=1);

namespace OpenYacht;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The node UUID, minted once at installation and published in the
 * well-known document (FP-5). A changed UUID signals a different node to
 * every partner (FP-11), so it must never be regenerated casually.
 */
final class NodeIdentity
{
    public const OPTION = 'openyacht_node_uuid';

    public static function ensure(): string
    {
        $uuid = get_option(self::OPTION, '');

        if (! is_string($uuid) || $uuid === '') {
            $uuid = wp_generate_uuid4();
            update_option(self::OPTION, $uuid, false);
        }

        return $uuid;
    }

    public static function uuid(): ?string
    {
        $uuid = get_option(self::OPTION, '');

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }
}
