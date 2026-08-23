<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

use RuntimeException;

/**
 * The vendored well-known feature vocabulary (seeded from production
 * reference data). Feature names stay free text on the wire; when a typed
 * name matches an entry here, the slug (and, absent a typed category, the
 * entry's grouping) travels with it so consumers can aggregate without
 * parsing prose.
 *
 * // listing-schema.md §Features
 */
class FeatureRegistry
{
    /** @var array<string, array{slug: string, name: string, category: string|null}>|null keyed by lowercase name */
    private ?array $byName = null;

    public function __construct(
        private readonly string $path = OPENYACHT_PATH . '/resources/registry/features.json',
    ) {
    }

    /**
     * @return array{slug: string, name: string, category: string|null}|null
     */
    public function matchName(string $name): ?array
    {
        $this->load();

        return $this->byName[strtolower(trim($name))] ?? null;
    }

    /**
     * @return list<array{slug: string, name: string, category: string|null}>
     */
    public function all(): array
    {
        $this->load();

        return array_values((array) $this->byName);
    }

    private function load(): void
    {
        if ($this->byName !== null) {
            return;
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException("The vendored feature registry is missing at [{$this->path}].");
        }

        $document = json_decode($contents, true);
        $this->byName = [];

        foreach (is_array($document['features'] ?? null) ? $document['features'] : [] as $feature) {
            if (is_array($feature) && is_string($feature['name'] ?? null) && is_string($feature['slug'] ?? null)) {
                $this->byName[strtolower($feature['name'])] = [
                    'slug' => $feature['slug'],
                    'name' => $feature['name'],
                    'category' => is_string($feature['category'] ?? null) ? $feature['category'] : null,
                ];
            }
        }
    }
}
