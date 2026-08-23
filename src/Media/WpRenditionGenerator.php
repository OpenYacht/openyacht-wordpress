<?php

declare(strict_types=1);

namespace OpenYacht\Media;

use RuntimeException;

/**
 * wp_get_image_editor-backed renditions: WebP at the standard widths
 * (scale, never upscale — a narrow source just emits fewer sizes), plus
 * 16:9 cover crops of the profile image for hero use.
 */
final class WpRenditionGenerator implements RenditionGenerator
{
    /** @var list<int> */
    public const WIDTHS = [480, 960, 1920];

    private const QUALITY = 80;

    private const CROP_RATIO = 16 / 9;

    public function generate(string $bytes, bool $profileCrops = false): array
    {
        $source = (string) wp_tempnam('openyacht-source');

        if ($source === '' || file_put_contents($source, $bytes) === false) {
            throw new RuntimeException('Could not stage source image for rendition generation.');
        }

        try {
            $probe = wp_get_image_editor($source);

            if (is_wp_error($probe)) {
                throw new RuntimeException('No usable image editor: ' . $probe->get_error_message());
            }

            $sourceSize = $probe->get_size();
            $sourceWidth = (int) ($sourceSize['width'] ?? 0);
            $manifest = [];
            $emitted = [];

            foreach (self::WIDTHS as $width) {
                $target = min($width, $sourceWidth);

                if ($target < 1 || in_array($target, $emitted, true)) {
                    continue;
                }

                $emitted[] = $target;
                $manifest["w{$width}"] = $this->render($source, $target, null, false);
            }

            if ($profileCrops) {
                foreach (self::WIDTHS as $width) {
                    $target = min($width, $sourceWidth);

                    if ($target < 1) {
                        continue;
                    }

                    $manifest["crop_{$width}"] = $this->render($source, $target, (int) round($target / self::CROP_RATIO), true);
                }
            }

            return $manifest;
        } finally {
            @unlink($source);
        }
    }

    /**
     * @return array{path: string, width: int, height: int}
     */
    private function render(string $source, int $width, ?int $height, bool $crop): array
    {
        $editor = wp_get_image_editor($source);

        if (is_wp_error($editor)) {
            throw new RuntimeException('No usable image editor: ' . $editor->get_error_message());
        }

        $resized = $editor->resize($width, $height, $crop);

        if (is_wp_error($resized)) {
            throw new RuntimeException('Resize failed: ' . $resized->get_error_message());
        }

        $editor->set_quality(self::QUALITY);
        $saved = $editor->save((string) wp_tempnam('openyacht-rendition'), 'image/webp');

        if (is_wp_error($saved)) {
            throw new RuntimeException('Rendition save failed: ' . $saved->get_error_message());
        }

        return [
            'path' => (string) $saved['path'],
            'width' => (int) $saved['width'],
            'height' => (int) $saved['height'],
        ];
    }
}
