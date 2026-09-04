<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * Partner lifecycle: first contact (TOFU), key refresh with reinstall
 * detection, out-of-band registration, and the human approve/block
 * decisions.
 *
 * // federation-protocol.md §Identity and Trust Model, §Partner Lifecycle
 */
final class PartnerService
{
    public function __construct(
        private readonly PartnerRepository $partners,
        private readonly WellKnownClient $wellKnown,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Add a partner by domain. Trust on first use: the keys and node UUID
     * the domain serves now become the stored identity; the partner starts
     * as provisional until a human approves (FP-13).
     */
    public function add(string $domain): Partner
    {
        $domain = strtolower(trim($domain));
        $this->assertNotRegistered($domain);
        $document = $this->wellKnown->fetch($domain);
        $now = gmdate('Y-m-d H:i:s');

        // Arm the trust-on-first-use pin to the key the partner signs with
        // now (FP-12). Until an administrator confirms a rotation, this is
        // the only key the verifier will accept — so a later silent key
        // swap by whoever controls the partner's well-known document is
        // rejected, not trusted. Ingest has bound the key_id to its
        // material, so the first-listed id is a commitment to a real key.
        $firstKeyId = is_string($document['keys'][0]['key_id'] ?? null) ? $document['keys'][0]['key_id'] : null;

        $partner = $this->partners->insert([
            'domain' => $domain,
            'node_uuid' => $document['node']['uuid'] ?? null,
            'keys_json' => $document['keys'],
            'keys_fetched_at' => $now,
            'pinned_key_id' => $firstKeyId,
            'trust_level' => TrustLevel::Provisional,
            'last_ok_at' => $now,
        ]);

        $this->logger->log('partner', "Partner {$domain} added (provisional)", 'partner_added', $partner->id);
        do_action('openyacht_partner_added', $partner);

        return $partner;
    }

    /**
     * Out-of-band registration: store a partner's key without fetching its
     * well-known document — for acceptance checkers and nodes on domains
     * unresolvable from here (the pinning model). The key is pinned so
     * other keys need administrator confirmation (FP-12).
     */
    public function addWithKey(string $domain, string $publicKey, ?string $nodeUuid = null): Partner
    {
        $domain = strtolower(trim($domain));
        $this->assertNotRegistered($domain);
        $rawPublicKey = base64_decode($publicKey, true);

        if ($rawPublicKey === false || strlen($rawPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('Public key must be base64 of a raw 32-byte Ed25519 key.');
        }

        $keyId = KeyManager::deriveKeyId($rawPublicKey);
        $now = gmdate('Y-m-d H:i:s');

        $partner = $this->partners->insert([
            'domain' => $domain,
            'node_uuid' => $nodeUuid,
            'keys_json' => [[
                'key_id' => $keyId,
                'algorithm' => 'ed25519',
                'public_key' => $publicKey,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]],
            'keys_fetched_at' => $now,
            'pinned_key_id' => $keyId,
            'trust_level' => TrustLevel::Provisional,
        ]);

        $this->logger->log('partner', "Partner {$domain} registered out-of-band with pinned key {$keyId}", 'partner_added', $partner->id);
        do_action('openyacht_partner_added', $partner);

        return $partner;
    }

    /**
     * Refetch the partner's well-known document and update the cached
     * keys. A changed node UUID means the domain now hosts a different
     * installation: downgrade to provisional and notify administrators
     * (FP-11).
     */
    public function refreshKeys(Partner $partner, bool $confirmPin = false): Partner
    {
        $document = $this->wellKnown->fetch($partner->domain);
        $freshUuid = is_string($document['node']['uuid'] ?? null) ? $document['node']['uuid'] : null;
        $now = gmdate('Y-m-d H:i:s');

        if ($partner->nodeUuid !== null && $freshUuid !== $partner->nodeUuid) {
            $this->partners->update($partner->id, [
                'node_uuid' => $freshUuid,
                'keys_json' => $document['keys'],
                'keys_fetched_at' => $now,
                'trust_level' => TrustLevel::Provisional,
                'approved_by_user_id' => null,
            ]);

            $this->logger->log(
                'partner',
                "Node UUID changed for {$partner->domain} — downgraded to provisional pending re-approval",
                'partner_uuid_changed',
                $partner->id,
                null,
                ['previous_uuid' => $partner->nodeUuid, 'new_uuid' => $freshUuid],
            );
            do_action('openyacht_partner_uuid_changed', $this->fresh($partner));

            return $this->fresh($partner);
        }

        $columns = [
            'keys_json' => $document['keys'],
            'keys_fetched_at' => $now,
        ];

        // A pinned partner's rotated key is only accepted after
        // administrator confirmation (FP-12) — and this IS the
        // confirmation surface: the admin clicked refresh, so the pin
        // moves to the partner's current signing key (listed first in the
        // well-known document by both implementations' convention).
        $firstKeyId = is_string($document['keys'][0]['key_id'] ?? null) ? $document['keys'][0]['key_id'] : null;

        if ($confirmPin && $partner->pinnedKeyId !== null && $firstKeyId !== null && $firstKeyId !== $partner->pinnedKeyId) {
            $columns['pinned_key_id'] = $firstKeyId;
            $this->logger->log(
                'partner',
                "Pinned key for {$partner->domain} moved to {$firstKeyId} on administrator confirmation",
                'partner_repinned',
                $partner->id,
            );
        }

        $this->partners->update($partner->id, $columns);

        return $this->fresh($partner);
    }

    /**
     * Human approval: the partner becomes verified (FP-13).
     */
    public function approve(Partner $partner, ?int $approvedByUserId): Partner
    {
        $this->partners->update($partner->id, [
            'trust_level' => TrustLevel::Verified,
            'approved_by_user_id' => $approvedByUserId,
            // Establish the pin if a partner predates pin-arming at first
            // contact; approval is the human "I trust this partner" moment.
            'pinned_key_id' => $partner->pinnedKeyId ?? $partner->currentSigningKeyId(),
        ]);

        $this->logger->log('partner', "Partner {$partner->domain} approved", 'partner_approved', $partner->id);

        return $this->fresh($partner);
    }

    /**
     * Explicit refusal: all requests from this partner are rejected (FP-9).
     */
    public function block(Partner $partner): Partner
    {
        $this->partners->update($partner->id, [
            'trust_level' => TrustLevel::Blocked,
        ]);

        $this->logger->log('partner', "Partner {$partner->domain} blocked", 'partner_blocked', $partner->id);

        return $this->fresh($partner);
    }

    private function assertNotRegistered(string $domain): void
    {
        if ($this->partners->findByDomain($domain) !== null) {
            throw new RuntimeException("Partner {$domain} is already registered.");
        }
    }

    private function fresh(Partner $partner): Partner
    {
        return $this->partners->find($partner->id) ?? $partner;
    }
}
