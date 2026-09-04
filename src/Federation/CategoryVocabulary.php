<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * The vendored category vocabulary (LS-11/LS-12/LS-13, same rules as the
 * builder registry).
 *
 * // listing-schema.md §Shared vocabulary — Categories
 */
class CategoryVocabulary
{
    /** @var array<string, array{slug: string, name: string}>|null */
    private ?array $bySlug = null;

    public function __construct(
        private readonly string $path = OPENYACHT_PATH . '/resources/registry/categories.json',
    ) {
    }

    public function has(string $slug): bool
    {
        $this->load();

        return isset($this->bySlug[$slug]);
    }

    /**
     * @return list<array{slug: string, name: string}>
     */
    public function all(): array
    {
        $this->load();

        return array_values((array) $this->bySlug);
    }

    private function load(): void
    {
        if ($this->bySlug !== null) {
            return;
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException("The vendored category vocabulary is missing at [{$this->path}].");
        }

        $document = json_decode($contents, true);

        if (! is_array($document) || ! is_array($document['categories'] ?? null)) {
            throw new RuntimeException('The vendored category vocabulary is malformed.');
        }

        $this->bySlug = [];

        foreach ($document['categories'] as $category) {
            if (is_array($category) && is_string($category['slug'] ?? null)) {
                $this->bySlug[$category['slug']] = $category;
            }
        }
    }
}
