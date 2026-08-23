<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

use OpenYacht\Schema;
use RuntimeException;

final class WpdbListingRepository implements ListingRepository
{
    private const JSON_COLUMNS = ['previous_names', 'specifications', 'descriptions', 'features', 'compliance'];

    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    public function find(int $id): ?Listing
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            'ARRAY_A',
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByUuid(string $uuid): ?Listing
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE uuid = %s", $uuid),
            'ARRAY_A',
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function insert(array $columns): Listing
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->wpdb->insert($this->table(), $this->serialize($columns) + ['created_at' => $now, 'updated_at' => $now]);

        $listing = $this->findByUuid((string) $columns['uuid']);

        if ($listing === null) {
            throw new RuntimeException('Failed to insert listing.');
        }

        return $listing;
    }

    public function update(int $id, array $columns): void
    {
        unset($columns['id'], $columns['uuid']); // Canonical identity never changes (ID-1).

        $this->wpdb->update(
            $this->table(),
            $this->serialize($columns) + ['updated_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $id],
        );
    }

    public function page(?string $updatedSinceStored, ?array $cursor, int $limit): array
    {
        $where = [$this->wpdb->prepare('status != %s', ListingStatus::Draft->value)];

        if ($updatedSinceStored !== null) {
            $where[] = $this->wpdb->prepare('federation_updated_at >= %s', $updatedSinceStored);
        } else {
            $where[] = $this->wpdb->prepare(
                'status IN (%s, %s)',
                ListingStatus::Active->value,
                ListingStatus::UnderOffer->value,
            );
        }

        if ($cursor !== null) {
            $where[] = $this->wpdb->prepare(
                '(federation_updated_at > %s OR (federation_updated_at = %s AND id > %d))',
                $cursor['updated_at'],
                $cursor['updated_at'],
                $cursor['id'],
            );
        }

        $sql = "SELECT * FROM {$this->table()} WHERE " . implode(' AND ', $where)
            . ' ORDER BY federation_updated_at, id'
            . $this->wpdb->prepare(' LIMIT %d', $limit + 1);

        $rows = $this->wpdb->get_results($sql, 'ARRAY_A');

        return array_map($this->hydrate(...), is_array($rows) ? $rows : []);
    }

    public function countAll(): int
    {
        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table()}");
    }

    public function delete(int $id): void
    {
        $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$this->table()} WHERE id = %d", $id));

        $schema = new Schema($this->wpdb);
        $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$schema->tableName('price_history')} WHERE listing_id = %d", $id));
        $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$schema->tableName('listing_media')} WHERE listing_id = %d", $id));
    }

    /**
     * @param array<string, mixed> $columns
     * @return array<string, mixed>
     */
    private function serialize(array $columns): array
    {
        foreach (self::JSON_COLUMNS as $column) {
            if (array_key_exists($column, $columns) && is_array($columns[$column])) {
                $columns[$column] = wp_json_encode($columns[$column], JSON_UNESCAPED_SLASHES);
            }
        }

        if (array_key_exists('status', $columns) && $columns['status'] instanceof ListingStatus) {
            $columns['status'] = $columns['status']->value;
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Listing
    {
        $json = static function (mixed $value): array {
            $decoded = is_string($value) ? json_decode($value, true) : null;

            return is_array($decoded) ? $decoded : [];
        };
        $string = static fn (mixed $value): ?string => isset($value) && $value !== '' ? (string) $value : null;

        return new Listing(
            id: (int) $row['id'],
            uuid: (string) $row['uuid'],
            type: (string) ($row['type'] ?? 'sale'),
            status: ListingStatus::tryFrom((string) ($row['status'] ?? '')) ?? ListingStatus::Draft,
            name: $string($row['name'] ?? null),
            summary: $string($row['summary'] ?? null),
            condition: $string($row['yacht_condition'] ?? null),
            hin: $string($row['hin'] ?? null),
            imo: $string($row['imo'] ?? null),
            mmsi: $string($row['mmsi'] ?? null),
            officialNumber: $string($row['official_number'] ?? null),
            builderName: $string($row['builder_name'] ?? null),
            builderSlug: $string($row['builder_slug'] ?? null),
            modelName: $string($row['model_name'] ?? null),
            yearBuilt: isset($row['year_built']) && $row['year_built'] !== '' ? (int) $row['year_built'] : null,
            refitYear: isset($row['refit_year']) && $row['refit_year'] !== '' ? (int) $row['refit_year'] : null,
            loaM: isset($row['loa_m']) && $row['loa_m'] !== '' ? (float) $row['loa_m'] : null,
            previousNames: array_values($json($row['previous_names'] ?? null)),
            priceAmount: $string($row['price_amount'] ?? null),
            priceCurrency: $string($row['price_currency'] ?? null),
            priceOnApplication: (bool) ($row['price_on_application'] ?? false),
            startingPrice: (bool) ($row['starting_price'] ?? false),
            locationDisplay: $string($row['location_display'] ?? null),
            locationCity: $string($row['location_city'] ?? null),
            locationState: $string($row['location_state'] ?? null),
            locationCountry: $string($row['location_country'] ?? null),
            locationMarina: $string($row['location_marina'] ?? null),
            locationLat: isset($row['location_lat']) && $row['location_lat'] !== '' ? (float) $row['location_lat'] : null,
            locationLon: isset($row['location_lon']) && $row['location_lon'] !== '' ? (float) $row['location_lon'] : null,
            specifications: $json($row['specifications'] ?? null),
            descriptions: array_values($json($row['descriptions'] ?? null)),
            features: array_values($json($row['features'] ?? null)),
            compliance: $json($row['compliance'] ?? null),
            listedAt: $string($row['listed_at'] ?? null),
            federationUpdatedAt: $string($row['federation_updated_at'] ?? null),
        );
    }

    private function table(): string
    {
        return (new Schema($this->wpdb))->tableName('listings');
    }
}
