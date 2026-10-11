<?php

namespace Unified\SsoClient\Tests\Stubs;

use Unified\SsoClient\MasterData\Vehicles\StandardVehicleProjection;

/**
 * Fleet-Management's shape: `vehicles(unit_number, vin, license_plate,
 * category, make, model, model_year, color, fuel_type, status)` plus
 * columns Fleet owns alone (mileage, notes) that the mirror never reads or
 * writes. The standard projection already covers every column Fleet shares
 * with the contract; Fleet only adds its free-text `category`, which SSO
 * uses to suggest a vehicle type on the push and which carries SSO's type
 * name back down.
 */
class FleetVehicleProjection extends StandardVehicleProjection
{
    public function toColumns(array $vehicle): array
    {
        return parent::toColumns($vehicle) + ['category' => $vehicle['vehicle_type_name'] ?? null];
    }

    public function pushColumns(): array
    {
        return [...parent::pushColumns(), 'category'];
    }

    public function toImport(int $localCompanyId, object $row): array
    {
        return parent::toImport($localCompanyId, $row) + array_filter(['category' => $row->category], fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
