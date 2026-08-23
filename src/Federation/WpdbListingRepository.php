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

    public function feedPage(int $partnerId, ?string $updatedSinceStored, ?array $cursor, int $limit): array
    {
        $schema = new Schema($this->wpdb);
        $events = $schema->tableName('visibility_events');
        $audience = $schema->tableName('listing_audience');

        $visible = $this->wpdb->prepare(
            "(l.audience = 'everyone' OR (l.audience = 'selected' AND EXISTS (SELECT 1 FROM {$audience} a WHERE a.listing_id = l.id AND a.partner_id = %d)))",
            $partnerId,
        );
        $effective = "GREATEST(COALESCE(l.federation_updated_at, l.created_at), COALESCE(ev.occurred_at, '1000-01-01 00:00:00'))";

        $where = [$this->wpdb->prepare('l.status != %s', ListingStatus::Draft->value)];

        if ($updatedSinceStored !== null) {
            $where[] = $this->wpdb->prepare(
                "(({$visible} AND {$effective} >= %s) OR (NOT {$visible} AND ev.event = %s AND ev.occurred_at >= %s))",
                $updatedSinceStored,
                'hidden',
                $updatedSinceStored,
            );
        } else {
            $where[] = $visible;
            $where[] = $this->wpdb->prepare(
                'l.status IN (%s, %s)',
                ListingStatus::Active->value,
                ListingStatus::UnderOffer->value,
            );
        }

        if ($cursor !== null) {
            $where[] = $this->wpdb->prepare(
                "({$effective} > %s OR ({$effective} = %s AND l.id > %d))",
                $cursor['updated_at'],
                $cursor['updated_at'],
                $cursor['id'],
            );
        }

        $sql = "SELECT l.*, ev.event AS last_event, {$visible} AS visible_now, {$effective} AS effective_updated_at
            FROM {$this->table()} l
            LEFT JOIN (
                SELECT e.listing_id, e.event, e.occurred_at
                FROM {$events} e
                JOIN (" . $this->wpdb->prepare(
            "SELECT listing_id, MAX(id) AS max_id FROM {$events} WHERE partner_id = %d GROUP BY listing_id",
            $partnerId,
        ) . ') latest ON latest.max_id = e.id
            ) ev ON ev.listing_id = l.id
            WHERE ' . implode(' AND ', $where)
            . " ORDER BY {$effective}, l.id"
            . $this->wpdb->prepare(' LIMIT %d', $limit + 1);

        $rows = $this->wpdb->get_results($sql, 'ARRAY_A');
        $items = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $items[] = new FeedItem(
                listing: $this->hydrate($row),
                visible: (bool) $row['visible_now'],
                effectiveUpdatedAt: (string) $row['effective_updated_at'],
                lastEvent: isset($row['last_event']) && $row['last_event'] !== '' ? (string) $row['last_event'] : null,
            );
        }

        return $items;
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

        if (array_key_exists('audience', $columns) && $columns['audience'] instanceof Audience) {
            $columns['audience'] = $columns['audience']->value;
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
            audience: Audience::tryFrom((string) ($row['audience'] ?? '')) ?? Audience::Everyone,
        );
    }

    private function table(): string
    {
        return (new Schema($this->wpdb))->tableName('listings');
    }
}
