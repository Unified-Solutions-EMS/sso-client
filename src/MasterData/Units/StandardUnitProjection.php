<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Units;

use Unified\SsoClient\MasterData\Support\StandardRecordProjection;

/**
 * The default projection: the package-owned `sso_units` read model with the
 * contract's field names as columns (equipment as JSON text), the local
 * vehicle, location and division ids in `vehicle_id`, `location_id` and
 * `division_id`, and the seats in `sso_unit_seats`. An app with its own
 * unit table binds its own UnitProjection; one that only needs a column or
 * two more extends this.
 */
class StandardUnitProjection extends StandardRecordProjection implements UnitProjection
{
    public const TABLE = 'sso_units';

    public function table(): string
    {
        return self::TABLE;
    }

    public function nameColumn(): string
    {
        return 'name';
    }

    public function matchColumns(): array
    {
        return ['name'];
    }

    public function vehicleColumn(): ?string
    {
        return $this->column('vehicle_id');
    }

    public function locationColumn(): ?string
    {
        return $this->column('location_id');
    }

    public function divisionColumn(): ?string
    {
        return $this->column('division_id');
    }

    public function seatTable(): ?UnitSeatTable
    {
        return new StandardUnitSeatTable;
    }

    public function written(int $localCompanyId, int|string $localId, array $record): void {}

    public function accepts(int $localCompanyId, array $record): bool
    {
        return true;
    }

    public function pushable(int $localCompanyId, array $rows): array
    {
        return $rows;
    }

    protected function fields(): array
    {
        return [
            'name' => 'name',
            'display_name' => 'display_name',
            'unit_type' => 'unit_type',
            'level_of_care' => 'level_of_care',
            'capabilities' => 'capabilities',
            'crew_size' => 'crew_size',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['capabilities'];
    }
}
