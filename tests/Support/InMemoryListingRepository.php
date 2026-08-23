<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Federation\Listing;
use OpenYacht\Federation\ListingRepository;
use OpenYacht\Federation\ListingStatus;

final class InMemoryListingRepository implements ListingRepository
{
    /** @var array<int, Listing> */
    public array $listings = [];

    private int $nextId = 1;

    public function find(int $id): ?Listing
    {
        return $this->listings[$id] ?? null;
    }

    public function findByUuid(string $uuid): ?Listing
    {
        foreach ($this->listings as $listing) {
            if ($listing->uuid === $uuid) {
                return $listing;
            }
        }

        return null;
    }

    public function insert(array $columns): Listing
    {
        $listing = $this->build($this->nextId++, $columns);
        $this->listings[$listing->id] = $listing;

        return $listing;
    }

    public function update(int $id, array $columns): void
    {
        $existing = $this->listings[$id] ?? null;

        if ($existing === null) {
            return;
        }

        unset($columns['id'], $columns['uuid']);
        $this->listings[$id] = $this->build($id, $columns + $this->toColumns($existing));
    }

    public function page(?string $updatedSinceStored, ?array $cursor, int $limit): array
    {
        $rows = array_filter($this->listings, static function (Listing $listing) use ($updatedSinceStored): bool {
            if ($listing->status === ListingStatus::Draft) {
                return false;
            }

            if ($updatedSinceStored !== null) {
                return $listing->federationUpdatedAt !== null && $listing->federationUpdatedAt >= $updatedSinceStored;
            }

            return in_array($listing->status, [ListingStatus::Active, ListingStatus::UnderOffer], true);
        });

        if ($cursor !== null) {
            $rows = array_filter($rows, static fn (Listing $listing): bool => [$listing->federationUpdatedAt, $listing->id] > [$cursor['updated_at'], $cursor['id']]);
        }

        usort($rows, static fn (Listing $a, Listing $b): int => [$a->federationUpdatedAt, $a->id] <=> [$b->federationUpdatedAt, $b->id]);

        return array_slice(array_values($rows), 0, $limit + 1);
    }

    public function countAll(): int
    {
        return count($this->listings);
    }

    public function delete(int $id): void
    {
        unset($this->listings[$id]);
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function build(int $id, array $columns): Listing
    {
        $status = $columns['status'] ?? ListingStatus::Draft;
        $string = static fn (mixed $value): ?string => isset($value) && $value !== '' ? (string) $value : null;

        return new Listing(
            id: $id,
            uuid: (string) ($columns['uuid'] ?? ''),
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
            yearBuilt: isset($columns['year_built']) ? (int) $columns['year_built'] : null,
            refitYear: isset($columns['refit_year']) ? (int) $columns['refit_year'] : null,
            loaM: isset($columns['loa_m']) ? (float) $columns['loa_m'] : null,
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
            locationLat: isset($columns['location_lat']) ? (float) $columns['location_lat'] : null,
            locationLon: isset($columns['location_lon']) ? (float) $columns['location_lon'] : null,
            specifications: is_array($columns['specifications'] ?? null) ? $columns['specifications'] : [],
            descriptions: is_array($columns['descriptions'] ?? null) ? array_values($columns['descriptions']) : [],
            features: is_array($columns['features'] ?? null) ? array_values($columns['features']) : [],
            compliance: is_array($columns['compliance'] ?? null) ? $columns['compliance'] : [],
            listedAt: $string($columns['listed_at'] ?? null),
            federationUpdatedAt: $string($columns['federation_updated_at'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toColumns(Listing $listing): array
    {
        return [
            'uuid' => $listing->uuid,
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
        ];
    }
}
