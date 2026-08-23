<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Functions;
use OpenYacht\Federation\KeyManager;
use OpenYacht\Federation\WellKnownDocument;
use OpenYacht\Tests\Support\InMemoryKeyRepository;
use OpenYacht\Tests\Unit\TestCase;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Group;

#[Group('FP-1')]
#[Group('FP-5')]
final class WellKnownDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('home_url')->justReturn('https://node.example/');
        Functions\when('get_bloginfo')->justReturn('Test Node');
        Functions\when('get_option')->alias(static fn (string $name, $fallback = false) => match ($name) {
            'openyacht_node_uuid' => 'c6d45cc6-dd45-4f8f-b588-53738473a183',
            'openyacht_settings' => ['node_name' => 'Test Node'],
            default => $fallback,
        });
    }

    private function document(): array
    {
        $repository = new InMemoryKeyRepository();
        $manager = new KeyManager($repository);
        $manager->generate();

        return (new WellKnownDocument($manager))->toArray();
    }

    public function testValidatesAgainstTheVendoredJsonSchema(): void
    {
        $schemaPath = dirname(__DIR__, 3) . '/resources/schemas/v1/well-known.schema.json';
        $validator = new Validator();
        $validator->resolver()?->registerFile(
            'https://openyacht.org/schemas/v1/well-known.schema.json',
            $schemaPath,
        );

        $result = $validator->validate(
            json_decode((string) json_encode($this->document())),
            'https://openyacht.org/schemas/v1/well-known.schema.json',
        );

        self::assertTrue($result->isValid(), 'well-known document must validate against the vendored schema');
    }

    public function testPublishesOnlyActiveAndRetiringKeys(): void
    {
        $repository = new InMemoryKeyRepository();
        $manager = new KeyManager($repository);

        $manager->generate();
        $manager->rotate();          // old key -> retiring, new active
        $manager->rotateEmergency(); // everything revoked, one fresh key

        $document = (new WellKnownDocument($manager))->toArray();

        self::assertCount(1, $document['keys']);
        self::assertSame('ed25519', $document['keys'][0]['algorithm']);
    }
}
