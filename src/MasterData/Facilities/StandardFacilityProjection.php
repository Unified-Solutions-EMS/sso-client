<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Facilities;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * The default projection: a `facilities` table with company_id and the
 * contract's own column names (`type`, `location_code`, `designations` and
 * `npis` as JSON, `fax`, `address_*`, `latitude`, `longitude`,
 * `registry_state`, `registry_code`). Only the columns the table actually
 * has are read or written, so an app whose table shares the names but not
 * all of them works unchanged and every other column of its table (Billing's
 * claim specifics) is never touched. The mirror migration creates the full
 * table for an app with none. No child rows: phones are not kept (an app
 * that needs them extends this and overrides written()).
 */
class StandardFacilityProjection implements FacilityProjection
{
    /**
     * Local column => how it is read from an SSO facility. JSON columns hold
     * lists.
     */
    public const FIELDS = [
        'type' => 'type',
        'location_code' => 'location_code',
        'designations' => 'designations',
        'npis' => 'npis',
        'fax' => 'fax',
        'address_suite' => 'address.suite',
        'address_street' => 'address.street',
        'address_street2' => 'address.street2',
        'address_city_gnis' => 'address.city_gnis',
        'address_city_name' => 'address.city_name',
        'address_state' => 'address.state',
        'address_zip' => 'address.zip',
        'address_county' => 'address.county',
        'address_country' => 'address.country',
        'latitude' => 'latitude',
        'longitude' => 'longitude',
        'registry_state' => 'registry.state',
        'registry_code' => 'registry.code',
    ];

    public const JSON_FIELDS = ['designations', 'npis'];

    /**
     * @var array<string, true>|null
     */
    private ?array $columns = null;

    public function table(): string
    {
        return 'facilities';
    }

    public function nameColumn(): string
    {
        return 'name';
    }

    public function codeColumn(): ?string
    {
        return $this->column('location_code');
    }

    public function zipColumn(): ?string
    {
        return $this->column('address_zip');
    }

    public function scope(Builder $query, int $localCompanyId): Builder
    {
        return $query->where('company_id', $localCompanyId);
    }

    public function insertColumns(int $localCompanyId): array
    {
        return ['company_id' => $localCompanyId];
    }

    public function toColumns(int $localCompanyId, array $facility): array
    {
        $columns = [];

        foreach ($this->present() as $column) {
            $value = data_get($facility, self::FIELDS[$column]);

            if (in_array($column, self::JSON_FIELDS, true)) {
                $value = is_array($value) && $value !== [] ? json_encode(array_values($value)) : null;
            }

            $columns[$column] = $value === '' ? null : $value;
        }

        return $columns;
    }

    public function written(int $localCompanyId, int $localId, array $facility): void {}

    public function pushColumns(): array
    {
        return $this->present();
    }

    public function toImport(int $localCompanyId, object $row): array
    {
        $fields = [];

        foreach ($this->present() as $column) {
            $value = $row->{$column} ?? null;

            if (in_array($column, self::JSON_FIELDS, true)) {
                $value = is_string($value) ? json_decode($value, true) : $value;
                $value = is_array($value) && $value !== [] ? array_values($value) : null;
            }

            if ($value === null || $value === '' || str_starts_with(self::FIELDS[$column], 'registry.')) {
                continue;
            }

            data_set($fields, self::FIELDS[$column], $value);
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    protected function present(): array
    {
        return array_values(array_filter(array_keys(self::FIELDS), fn (string $column): bool => $this->column($column) !== null));
    }

    protected function column(string $column): ?string
    {
        $this->columns ??= array_fill_keys(Schema::getColumnListing($this->table()), true);

        return isset($this->columns[$column]) ? $column : null;
    }
}
