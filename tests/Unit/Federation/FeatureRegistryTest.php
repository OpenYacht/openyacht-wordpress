<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use OpenYacht\Federation\Countries;
use OpenYacht\Federation\FeatureRegistry;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('LS-11')]
final class FeatureRegistryTest extends TestCase
{
    private function registry(): FeatureRegistry
    {
        return new FeatureRegistry(dirname(__DIR__, 3) . '/resources/registry/features.json');
    }

    public function testMatchesKnownNamesCaseInsensitively(): void
    {
        $match = $this->registry()->matchName('air conditioning');

        self::assertNotNull($match);
        self::assertSame('air-conditioning', $match['slug']);
        self::assertSame('Air Conditioning', $match['name'], 'canonical casing wins');
        self::assertSame('comfort-luxury', $match['category']);
    }

    public function testUnknownNamesNeverInventASlug(): void
    {
        self::assertNull($this->registry()->matchName('Imaginary cinema'));
    }

    public function testVendoredCountriesLoad(): void
    {
        $countries = new Countries(dirname(__DIR__, 3) . '/resources/data/countries.json');

        self::assertTrue($countries->has('FR'));
        self::assertTrue($countries->has('fr'), 'case-insensitive');
        self::assertSame('France', $countries->nameFor('FR'));
        self::assertGreaterThan(240, count($countries->all()));
        self::assertFalse($countries->has('ZZ'), 'non-country ICU codes filtered');
    }
}
