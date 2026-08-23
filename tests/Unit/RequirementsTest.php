<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit;

use Brain\Monkey\Functions;
use OpenYacht\Requirements;

final class RequirementsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
    }

    public function testHttpsSiteWithSodiumPasses(): void
    {
        Functions\when('home_url')->justReturn('https://example.test/');

        self::assertSame([], (new Requirements())->errors());
    }

    public function testPlainHttpSiteIsRejected(): void
    {
        Functions\when('home_url')->justReturn('http://example.test/');

        $errors = (new Requirements())->errors();

        self::assertCount(1, $errors);
        self::assertStringContainsString('HTTPS', $errors[0]);
    }
}
