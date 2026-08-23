<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * Partner trust: first contact is provisional until a human approves;
 * blocked partners are rejected outright (FP-9, FP-13).
 *
 * // federation-protocol.md §Identity and Trust Model
 */
enum TrustLevel: string
{
    case Verified = 'verified';
    case Provisional = 'provisional';
    case Blocked = 'blocked';
}
