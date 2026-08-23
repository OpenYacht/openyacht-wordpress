<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * Column-map <-> Listing DTO conversion, shared by the wpdb repository's
 * callers, candidate validation (building an unsaved Listing to serialise
 * and validate before anything is persisted), and the test fakes.
 */
final class ListingFactory
{
    /**
     * @param array<string, mixed> $columns
     */
    public static function fromColumns(int $id, string $uuid, array $columns): Listing
    {
        $status = $columns['status'] ?? ListingStatus::Draft;
        $string = static fn (mixed $value): ?string => isset($value) && $value !== '' ? (string) $value : null;
        $int = static fn (mixed $value): ?int => isset($value) && $value !== '' && is_numeric($value) ? (int) $value : null;
        $float = static fn (mixed $value): ?float => isset($value) && $value !== '' && is_numeric($value) ? (float) $value : null;

        return new Listing(
            id: $id,
            uuid: $uuid,
            type: (string) ($columns['type'] ?? 'sale'),
            status: $status instanceof ListingStatus ? $status : (ListingStatus::tryFrom((string) $status) ?? ListingStatus::Draft),
            name: $string($columns['name'] ?? null),
            summary: $string($columns['summary'] ?? null),
            condition: $string($columns['yacht_condition'] ?? null),
            hin: $string($columns['hin'] ?? null),
            imo: $string($columns['imo'] ?? null),
            mmsi: $string($columns['mmsi'] ?? null),
            officialNumber: $string($columns['official_number'] ?? null),
            builderName: $string($columns['builder_name'] ?? null),
            builderSlug: $string($columns['builder_slug'] ?? null),
            modelName: $string($columns['model_name'] ?? null),
            yearBuilt: $int($columns['year_built'] ?? null),
            refitYear: $int($columns['refit_year'] ?? null),
            loaM: $float($columns['loa_m'] ?? null),
            previousNames: is_array($columns['previous_names'] ?? null) ? array_values($columns['previous_names']) : [],
            priceAmount: $string($columns['price_amount'] ?? null),
            priceCurrency: $string($columns['price_currency'] ?? null),
            priceOnApplication: (bool) ($columns['price_on_application'] ?? false),
            startingPrice: (bool) ($columns['starting_price'] ?? false),
            locationDisplay: $string($columns['location_display'] ?? null),
            locationCity: $string($columns['location_city'] ?? null),
            locationState: $string($columns['location_state'] ?? null),
            locationCountry: $string($columns['location_country'] ?? null),
            locationMarina: $string($columns['location_marina'] ?? null),
            locationLat: $float($columns['location_lat'] ?? null),
            locationLon: $float($columns['location_lon'] ?? null),
            specifications: is_array($columns['specifications'] ?? null) ? $columns['specifications'] : [],
            descriptions: is_array($columns['descriptions'] ?? null) ? array_values($columns['descriptions']) : [],
            features: is_array($columns['features'] ?? null) ? array_values($columns['features']) : [],
            compliance: is_array($columns['compliance'] ?? null) ? $columns['compliance'] : [],
            listedAt: $string($columns['listed_at'] ?? null),
            federationUpdatedAt: $string($columns['federation_updated_at'] ?? null),
            audience: ($columns['audience'] ?? null) instanceof Audience
                ? $columns['audience']
                : (Audience::tryFrom((string) ($columns['audience'] ?? '')) ?? Audience::Everyone),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function toColumns(Listing $listing): array
    {
        return [
            'type' => $listing->type,
            'status' => $listing->status,
            'name' => $listing->name,
            'summary' => $listing->summary,
            'yacht_condition' => $listing->condition,
            'hin' => $listing->hin,
            'imo' => $listing->imo,
            'mmsi' => $listing->mmsi,
            'official_number' => $listing->officialNumber,
            'builder_name' => $listing->builderName,
            'builder_slug' => $listing->builderSlug,
            'model_name' => $listing->modelName,
            'year_built' => $listing->yearBuilt,
            'refit_year' => $listing->refitYear,
            'loa_m' => $listing->loaM,
            'previous_names' => $listing->previousNames,
            'price_amount' => $listing->priceAmount,
            'price_currency' => $listing->priceCurrency,
            'price_on_application' => $listing->priceOnApplication,
            'starting_price' => $listing->startingPrice,
            'location_display' => $listing->locationDisplay,
            'location_city' => $listing->locationCity,
            'location_state' => $listing->locationState,
            'location_country' => $listing->locationCountry,
            'location_marina' => $listing->locationMarina,
            'location_lat' => $listing->locationLat,
            'location_lon' => $listing->locationLon,
            'specifications' => $listing->specifications,
            'descriptions' => $listing->descriptions,
            'features' => $listing->features,
            'compliance' => $listing->compliance,
            'listed_at' => $listing->listedAt,
            'federation_updated_at' => $listing->federationUpdatedAt,
            'audience' => $listing->audience,
        ];
    }

    /**
     * Media DTOs from column maps, for candidate validation before rows
     * are persisted.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<ListingMedia>
     */
    public static function mediaFromRows(int $listingId, array $rows): array
    {
        $items = [];
        $string = static fn (mixed $value): ?string => isset($value) && $value !== '' ? (string) $value : null;

        foreach ($rows as $index => $row) {
            $items[] = new ListingMedia(
                id: $index + 1,
                listingId: $listingId,
                kind: (string) ($row['kind'] ?? 'gallery'),
                attachmentId: isset($row['attachment_id']) ? (int) $row['attachment_id'] : null,
                url: $string($row['url'] ?? null),
                thumbnailUrl: $string($row['thumbnail_url'] ?? null),
                sha256: $string($row['sha256'] ?? null),
                width: isset($row['width']) && is_numeric($row['width']) ? (int) $row['width'] : null,
                height: isset($row['height']) && is_numeric($row['height']) ? (int) $row['height'] : null,
                caption: $string($row['caption'] ?? null),
                category: $string($row['category'] ?? null),
                sort: (int) ($row['sort'] ?? 0),
            );
        }

        return $items;
    }
}
