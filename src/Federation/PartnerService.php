<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use LogicException;
use RuntimeException;

/**
 * Partner lifecycle: first contact (TOFU), the outbound partnership
 * request that introduces this node to the other side and the inbound
 * one it records, key refresh with reinstall detection, out-of-band
 * registration, the human approve/block decisions, and removal of a
 * partner nothing has been received from.
 *
 * // federation-protocol.md §Identity and Trust Model, §Partner Lifecycle
 */
final class PartnerService
{
    public const REQUEST_PATH = '/openyacht/v1/partners/request';

    /**
     * @param string|null $ownDomain this node's identity domain; resolved
     *                               from NodeConfig when null (tests pass
     *                               it explicitly)
     * @param FederationClient|null $client the signed outbound client the
     *                                      partnership request goes out on
     */
    public function __construct(
        private readonly PartnerRepository $partners,
        private readonly WellKnownClient $wellKnown,
        private readonly Logger $logger,
        private readonly ?CopyRepository $copies = null,
        private readonly ?string $ownDomain = null,
        private readonly ?FederationClient $client = null,
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
        $this->assertNotOwnDomain($domain);
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
        $this->assertNotOwnDomain($domain);
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
     * Introduce this node to a partner with a signed partnership request
     * (federation-protocol.md §Partner Lifecycle step 1): their node
     * verifies the signature, stores this node as provisional and
     * notifies its administrators — the request is what puts a "who are
     * you and why" in front of the person who approves it. Without it a
     * partnership shows up over there only when the first sync poll
     * happens to arrive, and a partner added but never polled introduces
     * itself to nobody.
     *
     * Nodes without the partners/request endpoint answer 404/405 from
     * their router before any signature check, so nothing is registered
     * over there; every conformant node authenticates a signed listings
     * read, which is why that is the fallback. The partner row is saved
     * before the attempt, so every outcome is reported, never thrown.
     *
     * Both fields always go on the wire: a receiver may reject a body
     * missing either as a validation error after registering the sender,
     * which would read as a failed introduction.
     */
    public function introduce(Partner $partner, ?string $message = null, ?string $contactEmail = null): PartnerIntroduction
    {
        $message = trim((string) $message);
        $contactEmail = trim((string) $contactEmail);

        if ($message === '') {
            $message = sprintf('%s would like to federate listings with you via OpenYacht.', NodeConfig::nodeName());
        }

        if ($contactEmail === '') {
            $adminEmail = get_option('admin_email');
            $contactEmail = is_string($adminEmail) ? trim($adminEmail) : '';
        }

        $introduction = $this->attempt($partner, $message, $contactEmail);

        if ($introduction->outcome->reachedPartner()) {
            $this->partners->update($partner->id, ['request_sent_at' => gmdate('Y-m-d H:i:s')]);
        }

        $this->logger->log(
            'partner',
            "Partnership request to {$partner->domain}: {$introduction->outcome->value}",
            'partner_request_sent',
            $partner->id,
            self::REQUEST_PATH,
            [
                'outcome' => $introduction->outcome->value,
                'detail' => $introduction->detail,
                'message' => $message,
                'contact_email' => $contactEmail,
            ],
        );

        return $introduction;
    }

    /**
     * The receiving side of the same step: a partner introduced itself
     * with a message and a contact. Stored on its row so the Partners
     * screen and the notification put "who and why" in front of the
     * person deciding whether to approve (FP-13).
     *
     * Lenient by design — either field may be missing. The sender has
     * introduced itself regardless, and a receiver that refuses after
     * registering the sender leaves both sides confused: registered here,
     * told it failed over there.
     */
    public function recordRequest(Partner $partner, ?string $message, ?string $contactEmail): Partner
    {
        $message = trim((string) $message);
        $contactEmail = trim((string) $contactEmail);

        $this->partners->update($partner->id, [
            'request_message' => $message !== '' ? $message : null,
            'request_contact_email' => $contactEmail !== '' ? $contactEmail : null,
            'requested_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->logger->log(
            'partner',
            "Partnership requested by {$partner->domain}",
            'partner_request_received',
            $partner->id,
            self::REQUEST_PATH,
            ['message' => $message, 'contact_email' => $contactEmail],
        );

        return $this->fresh($partner);
    }

    /**
     * The wire round trip. Every failure mode short of a bug is reported
     * as an outcome here so the caller never has to catch.
     */
    private function attempt(Partner $partner, string $message, string $contactEmail): PartnerIntroduction
    {
        $client = $this->client ?? throw new LogicException('PartnerService needs a FederationClient to send partnership requests.');

        try {
            $response = $client->post($partner, self::REQUEST_PATH, [
                'message' => $message,
                'contact_email' => $contactEmail,
            ]);

            $probed = in_array($response->status, [404, 405], true);

            if ($probed) {
                $response = $client->get($partner, '/openyacht/v1/listings?page_size=1');
            }
        } catch (RuntimeException $exception) {
            // TransportException (unreachable, or the outbound guard
            // refused the host) or the Signer's "no active key": all for
            // the operator to fix, none of them the partner's answer.
            return new PartnerIntroduction(IntroductionOutcome::Failed, $exception->getMessage());
        }

        return $this->interpret($response, $probed);
    }

    /**
     * Read the partner's answer. A 2xx from the request endpoint means it
     * was registered, and its trust_level says whether a human there
     * still has to approve; a 2xx from the listings probe means they are
     * already serving this node listings, which only a verified partner
     * gets (FP-13). A 403 PARTNER_PROVISIONAL from either is "registered,
     * awaiting a human".
     */
    private function interpret(HttpResponse $response, bool $probed): PartnerIntroduction
    {
        $json = $response->json() ?? [];
        $code = $json['error']['code'] ?? null;

        if ($response->ok()) {
            if ($probed) {
                return new PartnerIntroduction(IntroductionOutcome::Accepted, 'the partner already serves this node listings');
            }

            return ($json['trust_level'] ?? null) === TrustLevel::Verified->value
                ? new PartnerIntroduction(IntroductionOutcome::Accepted, 'the partner already lists this node as verified')
                : new PartnerIntroduction(IntroductionOutcome::Delivered, 'registered there as provisional, awaiting their approval');
        }

        if ($response->status === 403 && $code === ErrorCode::PartnerProvisional->value) {
            return new PartnerIntroduction(IntroductionOutcome::Delivered, 'registered there as provisional, awaiting their approval');
        }

        if ($response->status === 403 && $code === ErrorCode::PartnerBlocked->value) {
            return new PartnerIntroduction(IntroductionOutcome::Blocked, 'the partner has blocked this node');
        }

        return new PartnerIntroduction(
            IntroductionOutcome::Failed,
            sprintf('unexpected answer HTTP %d%s', $response->status, is_string($code) ? " {$code}" : ''),
        );
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

    /**
     * A partner can be removed while, and only while, nothing has been
     * received from it. Once copies exist the partner row is their
     * provenance anchor (ID-3) and the partnership ends by blocking — the
     * trust model has no "removed" state (federation-protocol.md §Trust
     * levels). Approval status is irrelevant: approve/block are already in
     * the activity log keyed by domain and survive the row.
     */
    public function isRemovable(Partner $partner): bool
    {
        return $this->copies()->countForPartner($partner->id) === 0;
    }

    /**
     * Undo a mistaken add. Deletes the partner together with its audience
     * selections, group memberships and visibility-log rows, and logs the
     * removal with the domain and trust level at the time. A removed
     * partner that polls again reappears through first-contact TOFU as a
     * fresh provisional partner — that IS an unsolicited introduction
     * again; block it if that is unwanted.
     *
     * @throws RuntimeException when listings have been received from it
     */
    public function remove(Partner $partner): void
    {
        if (! $this->isRemovable($partner)) {
            throw new RuntimeException("Listings have been received from {$partner->domain}; block it instead of removing it.");
        }

        $this->logger->log(
            'partner',
            "Partner {$partner->domain} removed ({$partner->trustLevel->value}, nothing received)",
            'partner_removed',
            $partner->id,
            null,
            ['domain' => $partner->domain, 'trust_level' => $partner->trustLevel->value],
        );

        $this->partners->delete($partner->id);
        do_action('openyacht_partner_removed', $partner);
    }

    private function assertNotRegistered(string $domain): void
    {
        if ($this->partners->findByDomain($domain) !== null) {
            throw new RuntimeException("Partner {$domain} is already registered.");
        }
    }

    /**
     * A node cannot partner with itself: it would fetch its own well-known
     * document, pin its own key, sign requests to itself and store its own
     * listings as partner copies under its own authority domain.
     */
    private function assertNotOwnDomain(string $domain): void
    {
        if ($domain === $this->ownDomain()) {
            throw new RuntimeException("{$domain} is this node's own identity domain; a node cannot partner with itself.");
        }
    }

    private function ownDomain(): string
    {
        return $this->ownDomain ?? NodeConfig::identityDomain();
    }

    private function copies(): CopyRepository
    {
        return $this->copies ?? throw new LogicException('PartnerService needs a CopyRepository to decide removability.');
    }

    private function fresh(Partner $partner): Partner
    {
        return $this->partners->find($partner->id) ?? $partner;
    }
}
