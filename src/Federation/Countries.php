<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * ISO 3166-1 alpha-2 countries (vendored, generated from ICU): the wire's
 * location.country is the code; the editor searches by name.
 */
class Countries
{
    /** @var array<string, string>|null code => name */
    private ?array $byCode = null;

    public function __construct(
        private readonly string $path = OPENYACHT_PATH . '/resources/data/countries.json',
    ) {
    }

    public function has(string $code): bool
    {
        $this->load();

        return isset($this->byCode[strtoupper($code)]);
    }

    public function nameFor(string $code): ?string
    {
        $this->load();

        return $this->byCode[strtoupper($code)] ?? null;
    }

    /**
     * @return array<string, string> code => name, sorted by name
     */
    public function all(): array
    {
        $this->load();

        return (array) $this->byCode;
    }

    private function load(): void
    {
        if ($this->byCode !== null) {
            return;
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException("The vendored country list is missing at [{$this->path}].");
        }

        $document = json_decode($contents, true);
        $this->byCode = [];

        foreach (is_array($document['countries'] ?? null) ? $document['countries'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['code'] ?? null) && is_string($entry['name'] ?? null)) {
                $this->byCode[$entry['code']] = $entry['name'];
            }
        }
    }
}
