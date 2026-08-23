<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

use OpenYacht\Admin\Settings;
use OpenYacht\NodeIdentity;

/**
 * The node's identity and protocol constants, resolved from WordPress
 * (options, home URL) with a constant/filter override for the identity
 * domain (split-hosting cases).
 */
final class NodeConfig
{
    /** @var list<string> */
    public const PROTOCOL_VERSIONS = ['1.0'];

    public const PAGE_SIZE_MAX = 100;

    public const RATE_PER_HOUR = 500;

    /**
     * The node's identity: the well-known trust root, the API base host,
     * the host line of every request signature, and every canonical listing
     * URI. Defaults to the site host; OPENYACHT_IDENTITY_DOMAIN or the
     * filter override it for split-hosting setups.
     *
     * // federation-protocol.md §Choosing the identity domain
     */
    public static function identityDomain(): string
    {
        if (defined('OPENYACHT_IDENTITY_DOMAIN') && is_string(constant('OPENYACHT_IDENTITY_DOMAIN'))) {
            return strtolower(constant('OPENYACHT_IDENTITY_DOMAIN'));
        }

        $host = parse_url(home_url('/'), PHP_URL_HOST);

        return strtolower((string) apply_filters('openyacht_identity_domain', is_string($host) ? $host : ''));
    }

    public static function nodeUuid(): string
    {
        return (string) NodeIdentity::uuid();
    }

    public static function nodeName(): string
    {
        $name = Settings::get('node_name');

        return is_string($name) && $name !== '' ? $name : (string) get_bloginfo('name');
    }

    public static function software(): string
    {
        return 'openyacht-wordpress/' . OPENYACHT_VERSION;
    }

    public static function website(): string
    {
        return home_url('/');
    }
}
