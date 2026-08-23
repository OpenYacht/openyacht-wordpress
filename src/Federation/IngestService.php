<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

use RuntimeException;

/**
 * The developer ingest path: a wire-shaped listing comes in (the same
 * stable contract the protocol serves — the party who knows the site's
 * structures maps INTO it), gets validated against the vendored schema and
 * registries, and lands in the plugin's tables. Descriptions are
 * sanitised to the restricted HTML subset on input (LS-4).
 *
 * One code path for every entry point: the admin authoring UI, the
 * openyacht_publish_listing() API, and the seed command all pass through
 * here.
 */
final class IngestService
{
    public function __construct(
        private readonly ListingService $listingService,
        private readonly ListingMediaRepository $media,
        private readonly ListingValidator $validator,
        private readonly RichTextSanitizer $sanitizer,
    ) {
    }

    /**
     * Create (and by default activate) a listing from wire-shaped input.
     *
     * @param array<string, mixed> $data
     * @return Listing|\WP_Error
     */
    public function publish(array $data)
    {
        $targetStatus = ListingStatus::tryFrom((string) ($data['status'] ?? 'active')) ?? ListingStatus::Active;
        $mediaRows = $this->mediaRows(0, is_array($data['media'] ?? null) ? $data['media'] : []);

        return $this->createFromColumns($this->decompose($data), $mediaRows, $targetStatus);
    }

    /**
     * Create a listing from a column map + media rows — the shared entry
     * for the admin form and the wire-shaped API. Validates the candidate
     * wire view BEFORE anything is persisted.
     *
     * @param array<string, mixed> $columns
     * @param list<array<string, mixed>> $mediaRows
     * @return Listing|\WP_Error
     */
    public function createFromColumns(array $columns, array $mediaRows, ListingStatus $targetStatus)
    {
        $errors = $this->validateCandidate($columns, $mediaRows, $targetStatus);

        if ($errors !== []) {
            return new \WP_Error('openyacht_invalid_listing', __('The listing failed validation.', 'openyacht'), $errors);
        }

        $listing = $this->listingService->create($columns);

        foreach ($mediaRows as $row) {
            $this->media->insert(['listing_id' => $listing->id] + $row);
        }

        if ($targetStatus !== ListingStatus::Draft) {
            $listing = $this->listingService->transition($listing, ListingStatus::Active);

            if ($targetStatus !== ListingStatus::Active) {
                $listing = $this->listingService->transition($listing, $targetStatus);
            }
        }

        return $listing;
    }

    /**
     * Revise an existing listing. The merged candidate is validated before
     * any stored data changes, so an invalid edit is refused with zero
     * side effects (no updated_at bump, no spurious price history).
     *
     * @param array<string, mixed> $columns
     * @param list<array<string, mixed>> $mediaRows the complete new media set
     * @return Listing|\WP_Error
     */
    public function reviseFromColumns(Listing $listing, array $columns, array $mediaRows)
    {
        $merged = array_merge(ListingFactory::toColumns($listing), $columns);
        $errors = $this->validateCandidate($merged, $mediaRows, $listing->status);

        if ($errors !== []) {
            return new \WP_Error('openyacht_invalid_listing', __('The listing failed validation.', 'openyacht'), $errors);
        }

        $fresh = $this->listingService->update($listing, $columns);
        $this->media->deleteForListing($listing->id);

        foreach ($mediaRows as $row) {
            $this->media->insert(['listing_id' => $listing->id] + $row);
        }

        return $fresh;
    }

    /**
     * Validate the complete wire view of a not-yet-persisted candidate —
     * the same serialisation partners will receive. The wire enum has no
     * 'draft' (LS-7), so drafts validate as if active.
     *
     * @param array<string, mixed> $columns
     * @param list<array<string, mixed>> $mediaRows
     * @return list<string>
     */
    public function validateCandidate(array $columns, array $mediaRows, ListingStatus $targetStatus): array
    {
        $candidate = ListingFactory::fromColumns(
            0,
            '00000000-0000-4000-8000-000000000000',
            $columns + ['federation_updated_at' => gmdate('Y-m-d H:i:s')],
        );

        $serializer = new ListingSerializer(
            new FixedPriceHistory(),
            new FixedMediaSet(ListingFactory::mediaFromRows(0, $mediaRows)),
        );

        $wire = $serializer->serialize($candidate, null);
        $wire['status'] = $targetStatus === ListingStatus::Draft ? ListingStatus::Active->value : $targetStatus->value;

        return $this->validator->validate($wire);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed> column map for the listings table
     */
    private function decompose(array $data): array
    {
        $vessel = is_array($data['vessel'] ?? null) ? $data['vessel'] : [];
        $listing = is_array($data['listing'] ?? null) ? $data['listing'] : [];
        $price = is_array($listing['price'] ?? null) ? $listing['price'] : [];
        $location = is_array($listing['location'] ?? null) ? $listing['location'] : [];
        $coordinates = is_array($location['coordinates'] ?? null) ? $location['coordinates'] : [];

        return [
            'type' => 'sale',
            'name' => $listing['name'] ?? null,
            'summary' => $listing['summary'] ?? null,
            'yacht_condition' => $data['condition'] ?? null,
            'hin' => $vessel['hin'] ?? null,
            'imo' => $vessel['imo'] ?? null,
            'mmsi' => $vessel['mmsi'] ?? null,
            'official_number' => $vessel['official_number'] ?? null,
            'builder_name' => $vessel['builder']['name'] ?? null,
            'builder_slug' => $vessel['builder']['slug'] ?? null,
            'model_name' => $vessel['model']['name'] ?? null,
            'year_built' => $vessel['year_built'] ?? null,
            'refit_year' => $vessel['refit_year'] ?? null,
            'loa_m' => $vessel['loa_m'] ?? null,
            'previous_names' => is_array($vessel['previous_names'] ?? null) ? $vessel['previous_names'] : [],
            'price_amount' => $price['amount'] ?? null,
            'price_currency' => $price['currency'] ?? null,
            'price_on_application' => ! empty($price['on_application']) ? 1 : 0,
            'starting_price' => ! empty($price['starting_price']) ? 1 : 0,
            'location_display' => $location['display'] ?? null,
            'location_city' => $location['city'] ?? null,
            'location_state' => $location['state'] ?? null,
            'location_country' => $location['country'] ?? null,
            'location_marina' => $location['marina'] ?? null,
            'location_lat' => $coordinates['lat'] ?? null,
            'location_lon' => $coordinates['lon'] ?? null,
            'specifications' => is_array($data['specifications'] ?? null) ? $data['specifications'] : [],
            'descriptions' => $this->sanitizeDescriptions(is_array($data['descriptions'] ?? null) ? $data['descriptions'] : []),
            'features' => is_array($data['features'] ?? null) ? array_values($data['features']) : [],
            'compliance' => is_array($data['compliance'] ?? null) ? $data['compliance'] : [],
        ];
    }

    /**
     * Rich text is restricted on input (LS-4) — the wire never carries
     * anything outside the allowed subset.
     *
     * @param list<array<string, mixed>> $descriptions
     * @return list<array<string, mixed>>
     */
    private function sanitizeDescriptions(array $descriptions): array
    {
        $clean = [];

        foreach ($descriptions as $entry) {
            if (! is_array($entry) || ! is_string($entry['content'] ?? null)) {
                continue;
            }

            $clean[] = [
                'section' => is_string($entry['section'] ?? null) ? $entry['section'] : null,
                'content' => $this->sanitizer->sanitize($entry['content'], [NodeConfig::identityDomain()]),
            ];
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $media wire-shaped media block
     * @return list<array<string, mixed>>
     */
    private function mediaRows(int $listingId, array $media): array
    {
        $rows = [];
        $profile = $media['profile'] ?? null;

        if (is_array($profile) && is_string($profile['url'] ?? null)) {
            $rows[] = $this->mediaColumns($listingId, 'profile', $profile, 0);
        }

        foreach (is_array($media['gallery'] ?? null) ? array_values($media['gallery']) : [] as $index => $entry) {
            if (is_array($entry) && is_string($entry['url'] ?? null)) {
                $rows[] = $this->mediaColumns($listingId, 'gallery', $entry, $index + 1);
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function mediaColumns(int $listingId, string $kind, array $item, int $sort): array
    {
        $url = (string) $item['url'];
        [$sha256, $width, $height] = $this->completeImageFacts($item);

        return [
            'listing_id' => $listingId,
            'kind' => $kind,
            'attachment_id' => isset($item['attachment_id']) ? (int) $item['attachment_id'] : null,
            'url' => $url,
            'thumbnail_url' => is_string($item['thumbnail_url'] ?? null) ? $item['thumbnail_url'] : null,
            'sha256' => $sha256,
            'width' => $width,
            'height' => $height,
            'caption' => is_string($item['caption'] ?? null) ? $item['caption'] : null,
            'category' => is_string($item['category'] ?? null) ? $item['category'] : null,
            'sort' => $sort,
        ];
    }

    /**
     * Ingest callers supply URLs the site already hosts; the plugin
     * computes and stores the hash and dimensions when they are absent.
     *
     * @param array<string, mixed> $item
     * @return array{0: ?string, 1: ?int, 2: ?int}
     */
    private function completeImageFacts(array $item): array
    {
        $sha256 = is_string($item['sha256'] ?? null) ? $item['sha256'] : null;
        $width = is_numeric($item['width'] ?? null) ? (int) $item['width'] : null;
        $height = is_numeric($item['height'] ?? null) ? (int) $item['height'] : null;

        if ($sha256 !== null && $width !== null && $height !== null) {
            return [$sha256, $width, $height];
        }

        try {
            $response = wp_remote_get((string) $item['url'], ['timeout' => 30, 'sslverify' => true]);

            if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
                return [$sha256, $width, $height];
            }

            $bytes = (string) wp_remote_retrieve_body($response);
            $sha256 ??= hash('sha256', $bytes);
            $size = getimagesizefromstring($bytes);

            if (is_array($size)) {
                $width ??= (int) $size[0];
                $height ??= (int) $size[1];
            }
        } catch (RuntimeException) {
            // Facts stay null; validation decides whether that is acceptable.
        }

        return [$sha256, $width, $height];
    }
}
