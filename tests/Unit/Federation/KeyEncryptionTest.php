<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use OpenYacht\Federation\KeyEncryption;
use OpenYacht\Federation\SaltsRotatedException;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('FP-4')]
final class KeyEncryptionTest extends TestCase
{
    public function testRoundTripsAndNeverStoresPlaintext(): void
    {
        $encryption = new KeyEncryption('salt-material-a');
        $secret = base64_encode(random_bytes(64));

        $stored = $encryption->encrypt($secret);

        self::assertStringStartsWith('v1:' . $encryption->fingerprint() . ':', $stored);
        self::assertStringNotContainsString($secret, $stored);
        self::assertSame($secret, $encryption->decrypt($stored));
    }

    public function testRotatedSaltsFailLoudlyNotSilently(): void
    {
        $stored = (new KeyEncryption('salt-material-a'))->encrypt('the-secret');

        $this->expectException(SaltsRotatedException::class);

        (new KeyEncryption('salt-material-b'))->decrypt($stored);
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $encryption = new KeyEncryption('salt-material-a');
        $stored = $encryption->encrypt('the-secret');

        [$version, $fingerprint, $payload] = explode(':', $stored, 3);
        $raw = (string) base64_decode($payload, true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === "\0" ? "\1" : "\0";
        $tampered = $version . ':' . $fingerprint . ':' . base64_encode($raw);

        $this->expectException(RuntimeException::class);

        $encryption->decrypt($tampered);
    }
}
