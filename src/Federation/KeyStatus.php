<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * Lifecycle of a federation signing key.
 *
 * // federation-protocol.md §Key Rotation
 */
enum KeyStatus: string
{
    case Active = 'active';
    case Retiring = 'retiring';
    case Revoked = 'revoked';
}
