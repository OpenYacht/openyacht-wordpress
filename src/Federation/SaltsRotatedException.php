<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

use RuntimeException;

/**
 * The wp-config.php salts the key-encryption secret derives from have
 * changed, so stored private keys can no longer be decrypted. Recoverable
 * by design: node identity is the domain, so an emergency key rotation
 * (fresh keypair, partners recover via the well-known refetch) restores
 * federation without partner coordination.
 *
 * // federation-protocol.md §Key Rotation — emergency rotation
 */
final class SaltsRotatedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'The WordPress salts used to encrypt the federation keys have changed; '
            . 'stored private keys are unreadable. Run an emergency key rotation to recover.',
        );
    }
}
