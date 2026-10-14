<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Vehicles;

use Illuminate\Support\Facades\Schema;

/**
 * The default projection: a `vehicles` table whose columns carry the
 * contract's own names (`unit_number`, `vin`, `license_plate`, ...,
 * `home_location_id`, `division_id`). Only the columns the table actually
 * has are read or written, so an app whose table shares the names but not
 * all of them (Fleet has no call sign or plate state) works unchanged and
 * every other column of its table (Fleet's mileage, notes) is never
 * touched. The mirror migration creates the full table for an app with
 * none. No child tables: crew levels and yearly usage are not kept.
 *
 * An app with the same idea but another field (Fleet's free-text
 * `category`) extends this and adds it.
 */
class StandardVehicleProjection implements VehicleProjection
{
    /**
     * Contract fields this projection mirrors, other than `unit_number`.
     */
    public const FIELDS = [
        'call_sign',
        'export_unit_number',
        'export_call_sign',
        'vin',
        'vehicle_type',
        'model_year',
        'initial_cost',
        'make',
        'model',
        'color',
        'license_plate',
        'license_plate_state',
        'fuel_type',
        'status',
    ];

    /**
     * @var array<string, true>|null
     */
    private ?array $columns = null;

    public function table(): string
    {
        return 'vehicles';
    }

    public function unitNumberColumn(): string
    {
        return 'unit_number';
    }

    public function vinColumn(): ?string
    {
        return $this->column('vin');
    }

    public function callSignColumn(): ?string
    {
        return $this->column('call_sign');
    }

    public function homeLocationColumn(): ?string
    {
        return $this->column('home_location_id');
    }

    public function divisionColumn(): ?string
    {
        return $this->column('division_id');
    }

    public function toColumns(array $vehicle): array
    {
        $columns = [];

        foreach ($this->present() as $field) {
            $columns[$field] = $vehicle[$field] ?? null;
        }

        return $columns;
    }

    public function pushColumns(): array
    {
        return $this->present();
    }

    public function toImport(int $localCompanyId, object $row): array
    {
        $fields = [];

        foreach ($this->present() as $field) {
            $value = $row->{$field} ?? null;

            if ($value !== null && $value !== '') {
                $fields[$field] = $value;
            }
        }

        return $fields;
    }

    public function childTables(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    protected function present(): array
    {
        return array_values(array_filter(self::FIELDS, fn (string $field): bool => $this->column($field) !== null));
    }

    protected function column(string $column): ?string
    {
        $this->columns ??= array_fill_keys(Schema::getColumnListing($this->table()), true);

        return isset($this->columns[$column]) ? $column : null;
    }
}
