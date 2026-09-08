<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Http\RequestContext;

/**
 * Authenticates inbound federation requests, implementing the verification
 * procedure end to end: timestamp window, blocked-partner rejection, key
 * selection with pinning, signature verification with the one permitted
 * well-known refetch, and node-UUID-change detection. First contact from
 * an unknown domain becomes a provisional partner; only verified partners
 * may read listings.
 *
 * Every request is logged with its verification outcome — the
 * usage-compliance evidence base (api-design.md §Implementation notes).
 *
 * // federation-protocol.md §Request Signing — Verification procedure
 */
final class InboundVerification
{
    private const REFETCH_WINDOW_SECONDS = 60;

    /**
     * @param string|null $ownDomain this node's identity domain; resolved
     *                               from NodeConfig when null (tests pass
     *                               it explicitly)
     */
    public function __construct(
        private readonly Verifier $verifier,
        private readonly PartnerRepository $partners,
        private readonly PartnerService $partnerService,
        private readonly Logger $logger,
        private readonly ?string $ownDomain = null,
    ) {
    }

    /**
     * @param bool $allowProvisional the endpoint exists for unapproved
     *                               partners (the partnership request)
     * @param bool $deferFirstContact do not fire openyacht_partner_first_contact
     *                                for a sender this request registers:
     *                                the caller stores something from the
     *                                request the notification should carry
     *                                and fires it itself. Fired here
     *                                regardless when the request is then
     *                                rejected, since the partner row exists
     *                                either way.
     */
    public function authenticate(RequestContext $request, bool $allowProvisional = false, bool $deferFirstContact = false): InboundResult
    {
        $senderDomain = strtolower($request->header('X-OpenYacht-Node'));
        $keyId = $request->header('X-OpenYacht-Key');
        $timestamp = $request->header('X-OpenYacht-Timestamp');
        $signature = $request->header('X-OpenYacht-Signature');

        if ($senderDomain === '' || $keyId === '' || $timestamp === '' || $signature === '') {
            return $this->reject($request, $senderDomain, ErrorCode::SignatureInvalid, 'Missing X-OpenYacht signature headers.');
        }

        // A request claiming to be this node is rejected before the
        // partner lookup: otherwise an impostor presenting our own domain
        // would be auto-registered as our provisional self-partner (and
        // fire the first-contact notification).
        if ($senderDomain === ($this->ownDomain ?? NodeConfig::identityDomain())) {
            return $this->reject($request, $senderDomain, ErrorCode::SignatureInvalid, 'The sender claims to be this node.');
        }

        // First contact from an unknown domain is trusted-on-first-use as
        // provisional (FP-13); an unreachable well-known document means we
        // cannot authenticate the sender at all.
        $partner = $this->partners->findByDomain($senderDomain);
        $firstContact = false;

        if ($partner === null) {
            try {
                $partner = $this->partnerService->add($senderDomain);
            } catch (InvalidWellKnownDocument) {
                return $this->reject($request, $senderDomain, ErrorCode::PartnerUnknown, "Could not fetch the sender's well-known document.");
            }

            $firstContact = true;
        }

        $result = $this->verifyRegistered($request, $partner, $senderDomain, $keyId, $timestamp, $signature, $allowProvisional, $firstContact);

        // Unlike openyacht_partner_added (which also fires for
        // operator-initiated adds), this marks an unsolicited
        // introduction — the surface admins want notifying about. It
        // fires whatever the request's fate, since the partner row now
        // exists; a caller that deferred it gets it only for a request
        // that verified, and owes it from there.
        if ($firstContact && (! $deferFirstContact || ! $result->verified())) {
            do_action('openyacht_partner_first_contact', $result->partner ?? $partner);
        }

        return $result;
    }

    /**
     * The verification procedure for a sender that is (now) registered:
     * blocked-partner rejection, key selection with pinning, signature
     * verification with the one permitted refetch, the provisional gate.
     */
    private function verifyRegistered(
        RequestContext $request,
        Partner $partner,
        string $senderDomain,
        string $keyId,
        string $timestamp,
        string $signature,
        bool $allowProvisional,
        bool $firstContact,
    ): InboundResult {
        if ($partner->trustLevel === TrustLevel::Blocked) {
            return $this->reject($request, $senderDomain, ErrorCode::PartnerBlocked, 'This partner is blocked.');
        }

        // A pinned key is the only acceptable key until an administrator
        // confirms otherwise, even if the well-known document serves more
        // (FP-12).
        if ($partner->pinnedKeyId !== null && $keyId !== $partner->pinnedKeyId) {
            return $this->reject($request, $senderDomain, ErrorCode::SignatureInvalid, 'The presented key is not the pinned key for this partner.');
        }

        $result = $this->verify($request, $keyId, $timestamp, $signature, $partner->publishedKeys());

        if (! $result->verified && $result->error === ErrorCode::TimestampOutOfRange) {
            return $this->reject($request, $senderDomain, ErrorCode::TimestampOutOfRange, 'The request timestamp is outside the accepted window.');
        }

        if (! $result->verified) {
            // On failure: refetch the sender's well-known document once
            // (fresh, rate-limit-respecting) and retry (FP-10). A changed
            // node UUID downgrades the partner and rejects (FP-11).
            if (! $this->refetchPermitted($senderDomain)) {
                return $this->reject($request, $senderDomain, ErrorCode::SignatureInvalid, 'Signature verification failed.');
            }

            $previousUuid = $partner->nodeUuid;

            try {
                $partner = $this->partnerService->refreshKeys($partner);
            } catch (InvalidWellKnownDocument) {
                return $this->reject($request, $senderDomain, ErrorCode::SignatureInvalid, 'Signature verification failed and the well-known document could not be refetched.');
            }

            if ($previousUuid !== null && $partner->nodeUuid !== $previousUuid) {
                return $this->reject($request, $senderDomain, ErrorCode::SignatureInvalid, 'The node UUID for this domain changed; the partnership requires re-approval.');
            }

            $result = $this->verify($request, $keyId, $timestamp, $signature, $partner->publishedKeys());

            if (! $result->verified) {
                return $this->reject($request, $senderDomain, $result->error ?? ErrorCode::SignatureInvalid, 'Signature verification failed after key refresh.');
            }
        }

        // Authenticated but unapproved partners receive no listings until a
        // human approves them (FP-13). Endpoints that exist for unapproved
        // partners — the partnership request itself — pass allowProvisional.
        if (! $allowProvisional && $partner->trustLevel !== TrustLevel::Verified) {
            return $this->reject($request, $senderDomain, ErrorCode::PartnerProvisional, 'Partnership is pending approval; no listings are shared yet.');
        }

        $this->logger->log('request', "Verified request from {$senderDomain}", 'ok', $partner->id, $request->pathWithQuery);

        return InboundResult::ok($partner, $firstContact);
    }

    /**
     * @param array<string, string> $publishedKeys
     */
    private function verify(RequestContext $request, string $keyId, string $timestamp, string $signature, array $publishedKeys): VerificationResult
    {
        return $this->verifier->verify(
            method: $request->method,
            pathWithQuery: $request->pathWithQuery,
            receivingHost: $request->host,
            rawBody: $request->rawBody,
            senderKeyId: $keyId,
            timestamp: $timestamp,
            signature: $signature,
            publishedKeys: $publishedKeys,
        );
    }

    private function refetchPermitted(string $senderDomain): bool
    {
        $key = 'openyacht_refetch_' . md5($senderDomain);

        if (get_transient($key) !== false) {
            return false;
        }

        set_transient($key, 1, self::REFETCH_WINDOW_SECONDS);

        return true;
    }

    private function reject(RequestContext $request, string $senderDomain, ErrorCode $code, string $message): InboundResult
    {
        $this->logger->log(
            'request',
            $message,
            strtolower($code->value),
            null,
            $request->pathWithQuery,
            $senderDomain !== '' ? ['sender' => $senderDomain] : [],
        );

        return InboundResult::rejected($code, $message);
    }
}
