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
        private readonly ListingRepository $listings,
        private readonly ListingMediaRepository $media,
        private readonly ListingSerializer $serializer,
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
        $listing = $this->listingService->create($this->decompose($data));
        $this->storeMedia($listing->id, is_array($data['media'] ?? null) ? $data['media'] : []);

        // Validate the persisted listing's complete wire view — the same
        // serialisation partners will receive. A draft is never
        // distributed (LS-7), so nothing leaks before validation passes;
        // the wire enum has no 'draft', so validate as the target status.
        $wire = $this->serializer->serialize($this->listings->find($listing->id) ?? $listing, null);
        $wire['status'] = $targetStatus === ListingStatus::Draft ? ListingStatus::Active->value : $targetStatus->value;
        $errors = $this->validator->validate($wire);

        if ($errors !== []) {
            $this->listings->delete($listing->id); // Roll back: it never existed.

            return new \WP_Error('openyacht_invalid_listing', 'The listing failed validation.', $errors);
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
     */
    private function storeMedia(int $listingId, array $media): void
    {
        $profile = $media['profile'] ?? null;

        if (is_array($profile) && is_string($profile['url'] ?? null)) {
            $this->media->insert($this->mediaColumns($listingId, 'profile', $profile, 0));
        }

        foreach (is_array($media['gallery'] ?? null) ? array_values($media['gallery']) : [] as $index => $entry) {
            if (is_array($entry) && is_string($entry['url'] ?? null)) {
                $this->media->insert($this->mediaColumns($listingId, 'gallery', $entry, $index + 1));
            }
        }
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
