<?php

declare(strict_types=1);

namespace OpenYacht\Media;

use RuntimeException;

/**
 * wp_get_image_editor-backed renditions: WebP at the standard widths
 * (scale, never upscale — a narrow source just emits fewer sizes), plus
 * 16:9 cover crops of the profile image for hero use.
 *
 * WP quirk handled here: image_resize_dimensions() returns false when the
 * requested size equals the source size, so same-size targets are
 * re-encoded without a resize call, and crop targets are clamped to what
 * the source can cover without upscaling.
 */
final class WpRenditionGenerator implements RenditionGenerator
{
    /** @var list<int> */
    public const WIDTHS = [480, 960, 1920];

    private const QUALITY = 80;

    private const CROP_RATIO = 16 / 9;

    public function generate(string $bytes, bool $profileCrops = false): array
    {
        if (! function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php'; // wp_tempnam is admin-only; CLI/cron contexts don't load it.
        }

        $source = (string) wp_tempnam('openyacht-source');

        if ($source === '' || file_put_contents($source, $bytes) === false) {
            throw new RuntimeException('Could not stage source image for rendition generation.');
        }

        try {
            $manifest = [];
            $emitted = [];
            $lastFailure = null;

            foreach (self::WIDTHS as $width) {
                try {
                    $this->add($manifest, $emitted, "w{$width}", $this->render($source, $width, false));
                } catch (RuntimeException $exception) {
                    $lastFailure = $exception; // One odd rendition never sinks the whole image.
                }
            }

            if ($profileCrops) {
                $emittedCrops = [];

                foreach (self::WIDTHS as $width) {
                    try {
                        $this->add($manifest, $emittedCrops, "crop_{$width}", $this->render($source, $width, true));
                    } catch (RuntimeException $exception) {
                        $lastFailure = $exception;
                    }
                }
            }

            if ($manifest === []) {
                throw $lastFailure ?? new RuntimeException('No renditions could be generated.');
            }

            return $manifest;
        } finally {
            @unlink($source);
        }
    }

    /**
     * Deduplicate: a narrow source clamps several requested widths to the
     * same output; keep only the first.
     *
     * @param array<string, array{path: string, width: int, height: int}> $manifest
     * @param list<string> $emitted
     * @param array{path: string, width: int, height: int} $rendition
     */
    private function add(array &$manifest, array &$emitted, string $key, array $rendition): void
    {
        $dimensions = $rendition['width'] . 'x' . $rendition['height'];

        if (in_array($dimensions, $emitted, true)) {
            @unlink($rendition['path']);

            return;
        }

        $emitted[] = $dimensions;
        $manifest[$key] = $rendition;
    }

    /**
     * @return array{path: string, width: int, height: int}
     */
    private function render(string $source, int $width, bool $crop): array
    {
        $editor = wp_get_image_editor($source);

        if (is_wp_error($editor)) {
            throw new RuntimeException('No usable image editor: ' . $editor->get_error_message());
        }

        $size = $editor->get_size();
        $sourceWidth = (int) ($size['width'] ?? 0);
        $sourceHeight = (int) ($size['height'] ?? 0);

        if ($sourceWidth < 1 || $sourceHeight < 1) {
            throw new RuntimeException('Could not read source image dimensions.');
        }

        if ($crop) {
            // Clamp to what the source covers without upscaling.
            $width = min($width, $sourceWidth, (int) floor($sourceHeight * self::CROP_RATIO));
            $height = min((int) round($width / self::CROP_RATIO), $sourceHeight);
        } else {
            $width = min($width, $sourceWidth);
            $height = null;
        }

        // WP's image_resize_dimensions() bails via wp_fuzzy_number_match
        // when the target is within 1px of the source — match its fuzz.
        $isSourceSize = abs($width - $sourceWidth) <= 1
            && ($height === null || abs($height - $sourceHeight) <= 1);

        if (! $isSourceSize) {
            $resized = $editor->resize($width, $height, $crop);

            if (is_wp_error($resized)) {
                throw new RuntimeException('Resize failed: ' . $resized->get_error_message());
            }
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
