<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class OptionsAutoloadTest extends TestCase
{
    /**
     * Project rule: no plugin option is ever autoloaded. Every
     * update_option()/add_option() in src/ must pass an explicit
     * non-true autoload argument.
     */
    public function testNoOptionWriteInSrcAutoloads(): void
    {
        $src = dirname(__DIR__, 2) . '/src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            self::assertDoesNotMatchRegularExpression(
                '/\b(?:update_option|add_option)\s*\([^;]*,\s*true\s*\)/s',
                $code,
                $file->getPathname() . ' writes an autoloaded option',
            );

            // Two-arg update_option defaults autoload for new options; require the explicit third argument.
            if (preg_match_all('/\bupdate_option\s*\(([^;]*)\);/sU', $code, $matches) > 0) {
                foreach ($matches[1] as $args) {
                    self::assertGreaterThanOrEqual(
                        2,
                        substr_count($args, ','),
                        $file->getPathname() . ': update_option must pass an explicit autoload argument',
                    );
                }
            }
        }
    }
}
