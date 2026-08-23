<?php

declare(strict_types=1);

namespace OpenYacht\Media;

use OpenYacht\Federation\CopyMedia;
use OpenYacht\Federation\CopyMediaRepository;
use OpenYacht\Federation\ListingCopy;
use OpenYacht\Federation\Logger;
use Throwable;

/**
 * Caches a synced copy's images in the plugin store: fetch under the FP-14
 * rules, generate renditions, write content-hash-named files under
 * {copy-id}/, record the manifest. A change in the wire media set (URL or
 * hash) re-syncs from scratch; a tombstone deletes the whole directory —
 * cached media inherits the usage terms (ID-10), no orphan sweeps needed.
 *
 * One bad image logs a warning and never fails the pass.
 */
final class MediaService
{
    public function __construct(
        private readonly Storage $storage,
        private readonly ImageFetcher $fetcher,
        private readonly RenditionGenerator $renditions,
        private readonly CopyMediaRepository $media,
        private readonly Logger $logger,
    ) {
    }

    /**
     * The wire media items this node caches: the profile image and the
     * gallery. Layouts/documents/videos are referenced, not cached, in v1.
     *
     * @return list<array{kind: string, url: string, sha256: ?string, caption: ?string, sort: int}>
     */
    public function wireItems(ListingCopy $copy): array
    {
        $media = $copy->payload['media'] ?? null;

        if (! is_array($media)) {
            return [];
        }

        $items = [];
        $profile = $media['profile'] ?? null;

        if (is_array($profile) && is_string($profile['url'] ?? null)) {
            $items[] = [
                'kind' => 'profile',
                'url' => $profile['url'],
                'sha256' => is_string($profile['sha256'] ?? null) ? $profile['sha256'] : null,
                'caption' => is_string($profile['caption'] ?? null) ? $profile['caption'] : null,
                'sort' => 0,
            ];
        }

        $gallery = [];

        foreach (is_array($media['gallery'] ?? null) ? $media['gallery'] : [] as $index => $entry) {
            if (! is_array($entry) || ! is_string($entry['url'] ?? null)) {
                continue;
            }

            $gallery[] = [
                'kind' => 'gallery',
                'url' => $entry['url'],
                'sha256' => is_string($entry['sha256'] ?? null) ? $entry['sha256'] : null,
                'caption' => is_string($entry['caption'] ?? null) ? $entry['caption'] : null,
                'sort' => is_numeric($entry['sort'] ?? null) ? (int) $entry['sort'] : (int) $index,
            ];
        }

        usort($gallery, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_merge($items, $gallery);
    }

    /**
     * The stored set is stale when the wire identities (kind + URL + hash,
     * in order) differ.
     */
    public function needsSync(ListingCopy $copy): bool
    {
        $identity = static fn (array $item): array => [$item['kind'], $item['url'], $item['sha256']];
        $wire = array_map($identity, $this->wireItems($copy));
        $stored = array_map(
            static fn (CopyMedia $item): array => [$item->kind, $item->sourceUrl, $item->sourceSha256],
            $this->media->forCopy($copy->id),
        );

        return $wire !== $stored;
    }

    /**
     * @return int images cached
     */
    public function sync(ListingCopy $copy): int
    {
        if (! $this->needsSync($copy)) {
            return 0;
        }

        $this->expire($copy);
        $stored = 0;

        foreach ($this->wireItems($copy) as $item) {
            try {
                $bytes = $this->fetcher->fetch($item['url'], $item['sha256']);
                $manifest = $this->renditions->generate($bytes, $item['kind'] === 'profile');
                $hash = substr(hash('sha256', $bytes), 0, 16);
                $storedManifest = [];

                foreach ($manifest as $key => $file) {
                    $path = $this->storage->write(
                        "{$copy->id}/{$hash}-{$key}.webp",
                        (string) file_get_contents($file['path']),
                    );
                    @unlink($file['path']);
                    $storedManifest[$key] = ['path' => $path, 'width' => $file['width'], 'height' => $file['height']];
                }

                $this->media->insert($copy->id, $item['kind'], $item['url'], $item['sha256'], $item['caption'], $item['sort'], $storedManifest);
                $stored++;
            } catch (Throwable $exception) {
                $this->logger->log(
                    'media',
                    "Media import failed for {$copy->canonicalUri} [{$item['url']}]: {$exception->getMessage()}",
                    'warning',
                    $copy->partnerId,
                );
            }
        }

        return $stored;
    }

    public function expire(ListingCopy $copy): void
    {
        $this->media->deleteForCopy($copy->id);
        $this->storage->deleteDirectory((string) $copy->id);
    }

    /**
     * Rendition URLs for one cached item, ready for src/srcset use.
     *
     * @return array<string, array{url: string, width: int, height: int}>
     */
    public function urls(CopyMedia $item): array
    {
        $urls = [];

        foreach ($item->renditions as $key => $rendition) {
            $urls[$key] = [
                'url' => $this->storage->url($rendition['path']),
                'width' => $rendition['width'],
                'height' => $rendition['height'],
            ];
        }

        return $urls;
    }
}
