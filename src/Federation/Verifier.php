<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use DateTimeImmutable;
use DateTimeZone;

/**
 * Verifies inbound federation request signatures.
 *
 * This service performs the cryptographic half of the verification
 * procedure: the timestamp window check, key selection, signing-string
 * reconstruction, and the Ed25519 check. The partner-level steps (blocked
 * partners, the one permitted well-known refetch, node-UUID change
 * detection) belong to the dispatch/partner layer that calls it.
 *
 * // federation-protocol.md §Request Signing — Verification procedure
 */
final class Verifier
{
    public const TIMESTAMP_TOLERANCE_SECONDS = 300;

    /**
     * Verify a request against the sender's published keys.
     *
     * The timestamp window is checked first: a request outside ±300 seconds
     * is rejected as TIMESTAMP_OUT_OF_RANGE without the signature check
     * being the deciding factor (FP-8, signing-test-vectors.md negative
     * test 3).
     *
     * @param array<string, string> $publishedKeys key_id => base64 raw 32-byte public key
     */
    public function verify(
        string $method,
        string $pathWithQuery,
        string $receivingHost,
        string $rawBody,
        string $senderKeyId,
        string $timestamp,
        string $signature,
        array $publishedKeys,
        ?DateTimeImmutable $now = null,
    ): VerificationResult {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if (! $this->timestampWithinWindow($timestamp, $now)) {
            return VerificationResult::failed(ErrorCode::TimestampOutOfRange);
        }

        $publicKey = $publishedKeys[$senderKeyId] ?? null;

        if ($publicKey === null) {
            return VerificationResult::failed(ErrorCode::SignatureInvalid);
        }

        $rawPublicKey = base64_decode($publicKey, true);
        $rawSignature = base64_decode($signature, true);

        if ($rawPublicKey === false || strlen($rawPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return VerificationResult::failed(ErrorCode::SignatureInvalid);
        }

        if ($rawSignature === false || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return VerificationResult::failed(ErrorCode::SignatureInvalid);
        }

        $signingString = SigningString::build(
            $method,
            $pathWithQuery,
            $receivingHost,
            $timestamp,
            $rawBody,
        );

        if (! sodium_crypto_sign_verify_detached($rawSignature, $signingString, $rawPublicKey)) {
            return VerificationResult::failed(ErrorCode::SignatureInvalid);
        }

        return VerificationResult::passed();
    }

    /**
     * Replay protection: the timestamp must be within ±300 seconds of
     * server time (FP-8).
     */
    private function timestampWithinWindow(string $timestamp, DateTimeImmutable $now): bool
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $timestamp,
            new DateTimeZone('UTC'),
        );

        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s\Z') !== $timestamp) {
            return false;
        }

        return abs($parsed->getTimestamp() - $now->getTimestamp()) <= self::TIMESTAMP_TOLERANCE_SECONDS;
    }
}
