<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Input-time validation of a listing's wire view: the vendored JSON Schema
 * (never fetched at request time, LS-13) plus registry-slug checks (LS-11)
 * — a non-null slug on the wire is a claim of registry membership, never
 * invented. Runs at authoring/ingest time only, never on the serve path.
 */
final class ListingValidator
{
    private const SCHEMA_ID = 'https://openyacht.org/schemas/v1/listing.schema.json';

    private ?Validator $validator = null;

    public function __construct(
        private readonly BuilderRegistry $builders,
        private readonly CategoryVocabulary $categories,
        private readonly string $schemaPath = OPENYACHT_PATH . '/resources/schemas/v1/listing.schema.json',
    ) {
    }

    /**
     * @param array<string, mixed> $wire the complete wire view
     * @return list<string> human-readable validation errors; empty when valid
     */
    public function validate(array $wire): array
    {
        $errors = [];
        $result = $this->validator()->validate(
            json_decode((string) json_encode($wire, JSON_UNESCAPED_SLASHES)),
            self::SCHEMA_ID,
        );

        $error = $result->error();

        if ($error !== null) {
            foreach ((new ErrorFormatter())->format($error) as $pointer => $messages) {
                foreach ((array) $messages as $message) {
                    $errors[] = "{$pointer}: {$message}";
                }
            }
        }

        $builderSlug = $wire['vessel']['builder']['slug'] ?? null;

        if (is_string($builderSlug) && ! $this->builders->has($builderSlug)) {
            $errors[] = "vessel.builder.slug: [{$builderSlug}] is not in the vendored builder registry (LS-11). Use null and keep the name.";
        }

        $category = $wire['specifications']['category'] ?? null;
        $categorySlug = is_array($category) ? ($category['slug'] ?? null) : null;

        if (is_string($categorySlug) && ! $this->categories->has($categorySlug)) {
            $errors[] = "specifications.category.slug: [{$categorySlug}] is not in the vendored category vocabulary (LS-11). Use null and keep the name.";
        }

        return $errors;
    }

    private function validator(): Validator
    {
        if ($this->validator === null) {
            $this->validator = new Validator();
            $this->validator->resolver()?->registerFile(self::SCHEMA_ID, $this->schemaPath);
        }

        return $this->validator;
    }
}
