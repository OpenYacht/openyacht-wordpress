<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Media\RenditionGenerator;

final class FakeRenditionGenerator implements RenditionGenerator
{
    public function generate(string $bytes, bool $profileCrops = false): array
    {
        $file = tempnam(sys_get_temp_dir(), 'oy-test-');
        file_put_contents((string) $file, $bytes);

        $manifest = ['w480' => ['path' => (string) $file, 'width' => 480, 'height' => 320]];

        if ($profileCrops) {
            $crop = tempnam(sys_get_temp_dir(), 'oy-test-');
            file_put_contents((string) $crop, $bytes);
            $manifest['crop_480'] = ['path' => (string) $crop, 'width' => 480, 'height' => 270];
        }

        return $manifest;
    }
}
