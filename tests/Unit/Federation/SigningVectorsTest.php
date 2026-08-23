<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use OpenYacht\Federation\ErrorCode;
use OpenYacht\Federation\FederationKey;
use OpenYacht\Federation\KeyManager;
use OpenYacht\Federation\KeyStatus;
use OpenYacht\Federation\Signer;
use OpenYacht\Federation\Verifier;
use OpenYacht\Tests\Support\InMemoryKeyRepository;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The published request-signing test vectors, plus the five negative tests
 * every verifier must pass. Reproducing these byte-for-byte is the
 * interoperability gate for the whole federation layer.
 *
 * // signing-test-vectors.md
 */
final class SigningVectorsTest extends TestCase
{
    private const VECTOR_SEED = 'OpenYacht-test-vector-seed-00001';

    private const VECTOR_PUBLIC_KEY = 'QKcwbi+S0spqvUIba9P45r2SDvKqbXmjCb6zsTn51Ac=';

    private const VECTOR_KEY_ID = '25f0c5c537a07c58';

    private const VECTOR_1_PATH = '/openyacht/v1/listings?updated_since=2026-08-01T00:00:00Z&page_size=50';

    private const VECTOR_1_TIMESTAMP = '2026-08-21T09:00:00Z';

    private const VECTOR_1_SIGNATURE = '0ZS5EQbB26H01ovHjBIJeYIp2hpK1rmB11zNr89HOKmbWsrTaAbfLXGrJ8kzigOBn8+3Z9ADf0g46/K9HInYAw==';

    private const VECTOR_2_PATH = '/openyacht/v1/partners/request';

    private const VECTOR_2_TIMESTAMP = '2026-08-21T09:05:00Z';

    private const VECTOR_2_BODY = '{"message":"Requesting partnership for co-brokerage.","contact_email":"broker@sender.example"}';

    private const VECTOR_2_SIGNATURE = 'GdI9tqtzMIm3fzSArP8DHu1P2iKbcyOHQ9rST27sbeXXD7w9vPmeXBXmShjTwAxuJYrtuokrY7VGNvTzdGY8AA==';

    private function vectorKey(): FederationKey
    {
        $keypair = sodium_crypto_sign_seed_keypair(self::VECTOR_SEED);

        return new FederationKey(
            keyId: KeyManager::deriveKeyId(sodium_crypto_sign_publickey($keypair)),
            publicKey: base64_encode(sodium_crypto_sign_publickey($keypair)),
            privateKey: base64_encode(sodium_crypto_sign_secretkey($keypair)),
            status: KeyStatus::Active,
        );
    }

    /**
     * @return array<string, string>
     */
    private function vectorPublishedKeys(): array
    {
        return [self::VECTOR_KEY_ID => self::VECTOR_PUBLIC_KEY];
    }

    private function signer(): Signer
    {
        Functions\when('home_url')->justReturn('https://sender.example/');

        return new Signer(new KeyManager(new InMemoryKeyRepository()));
    }

    private static function at(string $timestamp): DateTimeImmutable
    {
        return new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));
    }

    #[Group('FP-3')]
    public function testTestKeypairReproducesPublishedPublicKeyAndKeyId(): void
    {
        $key = $this->vectorKey();

        self::assertSame(self::VECTOR_PUBLIC_KEY, $key->publicKey);
        self::assertSame(self::VECTOR_KEY_ID, $key->keyId);
    }

    #[Group('FP-3')]
    #[Group('FP-7')]
    public function testVector1BodylessGetSignsByteForByte(): void
    {
        $headers = $this->signer()->headers(
            method: 'GET',
            pathWithQuery: self::VECTOR_1_PATH,
            receivingHost: 'receiver.example',
            key: $this->vectorKey(),
            timestamp: self::at(self::VECTOR_1_TIMESTAMP),
        );

        self::assertSame(self::VECTOR_1_SIGNATURE, $headers['X-OpenYacht-Signature']);
        self::assertSame('sender.example', $headers['X-OpenYacht-Node']);
        self::assertSame(self::VECTOR_KEY_ID, $headers['X-OpenYacht-Key']);
        self::assertSame(self::VECTOR_1_TIMESTAMP, $headers['X-OpenYacht-Timestamp']);
    }

    #[Group('FP-3')]
    #[Group('FP-7')]
    public function testVector2PostWithJsonBodySignsByteForByte(): void
    {
        $headers = $this->signer()->headers(
            method: 'POST',
            pathWithQuery: self::VECTOR_2_PATH,
            receivingHost: 'receiver.example',
            rawBody: self::VECTOR_2_BODY,
            key: $this->vectorKey(),
            timestamp: self::at(self::VECTOR_2_TIMESTAMP),
        );

        self::assertSame(self::VECTOR_2_SIGNATURE, $headers['X-OpenYacht-Signature']);
    }

    #[Group('FP-7')]
    public function testVerifierAcceptsBothVectors(): void
    {
        $verifier = new Verifier();

        $vector1 = $verifier->verify(
            method: 'GET',
            pathWithQuery: self::VECTOR_1_PATH,
            receivingHost: 'receiver.example',
            rawBody: '',
            senderKeyId: self::VECTOR_KEY_ID,
            timestamp: self::VECTOR_1_TIMESTAMP,
            signature: self::VECTOR_1_SIGNATURE,
            publishedKeys: $this->vectorPublishedKeys(),
            now: self::at(self::VECTOR_1_TIMESTAMP),
        );

        $vector2 = $verifier->verify(
            method: 'POST',
            pathWithQuery: self::VECTOR_2_PATH,
            receivingHost: 'receiver.example',
            rawBody: self::VECTOR_2_BODY,
            senderKeyId: self::VECTOR_KEY_ID,
            timestamp: self::VECTOR_2_TIMESTAMP,
            signature: self::VECTOR_2_SIGNATURE,
            publishedKeys: $this->vectorPublishedKeys(),
            now: self::at(self::VECTOR_2_TIMESTAMP),
        );

        self::assertTrue($vector1->verified);
        self::assertTrue($vector2->verified);
    }

    #[Group('FP-7')]
    public function testNegative1AnyChangedByteOfTheSigningStringInvalidatesTheSignature(): void
    {
        $result = (new Verifier())->verify(
            method: 'GET',
            pathWithQuery: '/openyacht/v1/listings?updated_since=2026-08-01T00:00:00Z&page_size=51',
            receivingHost: 'receiver.example',
            rawBody: '',
            senderKeyId: self::VECTOR_KEY_ID,
            timestamp: self::VECTOR_1_TIMESTAMP,
            signature: self::VECTOR_1_SIGNATURE,
            publishedKeys: $this->vectorPublishedKeys(),
            now: self::at(self::VECTOR_1_TIMESTAMP),
        );

        self::assertFalse($result->verified);
        self::assertSame(ErrorCode::SignatureInvalid, $result->error);
    }

    #[Group('FP-7')]
    public function testNegative2AHeaderTimestampDifferingFromTheSignedOneInvalidatesTheSignature(): void
    {
        $result = (new Verifier())->verify(
            method: 'GET',
            pathWithQuery: self::VECTOR_1_PATH,
            receivingHost: 'receiver.example',
            rawBody: '',
            senderKeyId: self::VECTOR_KEY_ID,
            timestamp: '2026-08-21T09:01:00Z',
            signature: self::VECTOR_1_SIGNATURE,
            publishedKeys: $this->vectorPublishedKeys(),
            now: self::at('2026-08-21T09:01:00Z'),
        );

        self::assertFalse($result->verified);
        self::assertSame(ErrorCode::SignatureInvalid, $result->error);
    }

    #[Group('FP-8')]
    public function testNegative3AValidlySignedTimestampOutsideTheWindowIsRejectedAsOutOfRange(): void
    {
        $result = (new Verifier())->verify(
            method: 'GET',
            pathWithQuery: self::VECTOR_1_PATH,
            receivingHost: 'receiver.example',
            rawBody: '',
            senderKeyId: self::VECTOR_KEY_ID,
            timestamp: self::VECTOR_1_TIMESTAMP,
            signature: self::VECTOR_1_SIGNATURE,
            publishedKeys: $this->vectorPublishedKeys(),
            now: self::at(self::VECTOR_1_TIMESTAMP)->modify('+301 seconds'),
        );

        self::assertFalse($result->verified);
        self::assertSame(ErrorCode::TimestampOutOfRange, $result->error);
    }

    #[Group('FP-7')]
    public function testNegative4ABodyAlteredAfterSigningInvalidatesTheSignature(): void
    {
        $alteredBody = '{"message": "Requesting partnership for co-brokerage.","contact_email":"broker@sender.example"}';

        $result = (new Verifier())->verify(
            method: 'POST',
            pathWithQuery: self::VECTOR_2_PATH,
            receivingHost: 'receiver.example',
            rawBody: $alteredBody,
            senderKeyId: self::VECTOR_KEY_ID,
            timestamp: self::VECTOR_2_TIMESTAMP,
            signature: self::VECTOR_2_SIGNATURE,
            publishedKeys: $this->vectorPublishedKeys(),
            now: self::at(self::VECTOR_2_TIMESTAMP),
        );

        self::assertFalse($result->verified);
        self::assertSame(ErrorCode::SignatureInvalid, $result->error);
    }

    #[Group('FP-7')]
    public function testNegative5AKeyIdAbsentFromThePublishedKeysIsRejectedAsSignatureInvalid(): void
    {
        $result = (new Verifier())->verify(
            method: 'GET',
            pathWithQuery: self::VECTOR_1_PATH,
            receivingHost: 'receiver.example',
            rawBody: '',
            senderKeyId: 'ffffffffffffffff',
            timestamp: self::VECTOR_1_TIMESTAMP,
            signature: self::VECTOR_1_SIGNATURE,
            publishedKeys: $this->vectorPublishedKeys(),
            now: self::at(self::VECTOR_1_TIMESTAMP),
        );

        self::assertFalse($result->verified);
        self::assertSame(ErrorCode::SignatureInvalid, $result->error);
    }
}
