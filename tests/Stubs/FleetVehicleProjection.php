<?php

namespace Unified\SsoClient\Tests\Stubs;

use Unified\SsoClient\MasterData\Vehicles\StandardVehicleProjection;

/**
 * Fleet-Management's shape: `vehicles(unit_number, vin, license_plate,
 * category, make, model, model_year, color, fuel_type, status)` plus
 * columns Fleet owns alone (mileage, notes) that the mirror never reads or
 * writes. The standard projection already covers every column Fleet shares
 * with the contract; Fleet only adds its free-text `category`, a Fleet
 * extension field that is push-only: SSO uses it to suggest a vehicle type
 * at import, and nothing from SSO is ever written back into it.
 */
class FleetVehicleProjection extends StandardVehicleProjection
{
    public function pushColumns(): array
    {
        return [...parent::pushColumns(), 'category'];
    }

    public function toImport(int $localCompanyId, object $row): array
    {
        return parent::toImport($localCompanyId, $row) + array_filter(['category' => $row->category], fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
