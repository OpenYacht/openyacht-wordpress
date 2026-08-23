<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use OpenYacht\Federation\RichTextSanitizer;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('LS-5')]
final class RichTextSanitizerTest extends TestCase
{
    private RichTextSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new RichTextSanitizer();
    }

    public function testAllowedSubsetSurvives(): void
    {
        $html = '<p>Hello <strong>world</strong></p><ul><li>one</li></ul><h3>Head</h3>';

        self::assertSame($html, $this->sanitizer->sanitize($html));
    }

    public function testDangerousElementsAreDroppedWithTheirContent(): void
    {
        $clean = $this->sanitizer->sanitize('<p>ok</p><script>alert(1)</script><iframe src="x"></iframe>');

        self::assertSame('<p>ok</p>', $clean);
    }

    public function testUnknownElementsAreUnwrappedToTheirText(): void
    {
        $clean = $this->sanitizer->sanitize('<div class="x"><p>kept <span style="color:red">text</span></p></div>');

        self::assertSame('<p>kept text</p>', $clean);
    }

    public function testOnlyHttpsHrefsSurviveOnLinks(): void
    {
        $clean = $this->sanitizer->sanitize('<p><a href="https://safe.example/x" onclick="evil()">a</a> <a href="javascript:evil()">b</a></p>');

        self::assertStringContainsString('href="https://safe.example/x"', $clean);
        self::assertStringNotContainsString('onclick', $clean);
        self::assertStringNotContainsString('javascript', $clean);
    }

    public function testSelfPromotionHostsAreStripped(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<p><a href="https://www.authority.example/buy">buy from us</a></p>',
            ['authority.example'],
        );

        self::assertStringNotContainsString('href', $clean);
        self::assertStringContainsString('buy from us', $clean, 'link text survives, the link does not');
    }
}
