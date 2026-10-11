<?php

namespace Unified\SsoClient\Tests\Stubs;

use Unified\SsoClient\MasterData\Vehicles\VehicleChildTable;
use Unified\SsoClient\MasterData\Vehicles\VehicleProjection;

/**
 * CloudPCR's shape, as a CloudPCR projection would bind it: `dem_vehicles`
 * with NEMSIS columns (dvehicle_01 unit number, 02 VIN, 03 call sign, 04
 * type, 09 initial cost, 10 model year), the "reported to the state as"
 * overrides `export_vehicle_number` / `export_call_sign`, soft deletes, and
 * two child tables: `dem_vehicle_cert_levels` (dvehicle_05 level, 06/07/08
 * crew counts) and `dem_vehicle_years` (dvehicle_11 year, 12 hours, 13
 * distance). A starting point for the real one, which also owns the `_nv`
 * columns.
 */
class DemVehicleProjection implements VehicleProjection
{
    public function table(): string
    {
        return 'dem_vehicles';
    }

    public function unitNumberColumn(): string
    {
        return 'dvehicle_01';
    }

    public function vinColumn(): ?string
    {
        return 'dvehicle_02';
    }

    public function callSignColumn(): ?string
    {
        return 'dvehicle_03';
    }

    public function homeLocationColumn(): ?string
    {
        return null;
    }

    public function divisionColumn(): ?string
    {
        return null;
    }

    public function toColumns(array $vehicle): array
    {
        return [
            'dvehicle_02' => $vehicle['vin'] ?? null,
            'dvehicle_03' => $vehicle['call_sign'] ?? null,
            'dvehicle_04' => $vehicle['vehicle_type'] ?? null,
            'dvehicle_09' => $vehicle['initial_cost'] ?? null,
            'dvehicle_10' => $vehicle['model_year'] ?? null,
            'export_vehicle_number' => $vehicle['export_unit_number'] ?? null,
            'export_call_sign' => $vehicle['export_call_sign'] ?? null,
            // SSO owns the lifecycle: turned off is is_active, never a soft delete.
            'deleted_at' => null,
        ];
    }

    public function pushColumns(): array
    {
        return ['uuid', 'dvehicle_02', 'dvehicle_03', 'dvehicle_04', 'dvehicle_09', 'dvehicle_10', 'export_vehicle_number', 'export_call_sign'];
    }

    public function toImport(int $localCompanyId, object $row): array
    {
        return array_filter([
            'uuid' => $row->uuid,
            'vin' => $row->dvehicle_02,
            'call_sign' => $row->dvehicle_03,
            'vehicle_type' => $row->dvehicle_04,
            'initial_cost' => $row->dvehicle_09 === null ? null : (int) $row->dvehicle_09,
            'model_year' => $row->dvehicle_10 === null ? null : (int) $row->dvehicle_10,
            'export_unit_number' => $row->export_vehicle_number,
            'export_call_sign' => $row->export_call_sign,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function childTables(): array
    {
        return [
            'crew_levels' => new class implements VehicleChildTable
            {
                public function table(): string
                {
                    return 'dem_vehicle_cert_levels';
                }

                public function parentColumn(): string
                {
                    return 'dem_vehicle_id';
                }

                public function keyColumn(): string
                {
                    return 'dvehicle_05';
                }

                public function toColumns(array $child): array
                {
                    return [
                        'dvehicle_05' => $child['level'] ?? null,
                        'dvehicle_06' => $child['on_911_ambulance'] ?? null,
                        'dvehicle_07' => $child['on_911_non_transport'] ?? null,
                        'dvehicle_08' => $child['on_non_911_transport'] ?? null,
                    ];
                }

                public function pushColumns(): array
                {
                    return ['dvehicle_05', 'dvehicle_06', 'dvehicle_07', 'dvehicle_08'];
                }

                public function toImport(object $row): array
                {
                    return array_filter([
                        'level' => $row->dvehicle_05,
                        'on_911_ambulance' => $row->dvehicle_06 === null ? null : (int) $row->dvehicle_06,
                        'on_911_non_transport' => $row->dvehicle_07 === null ? null : (int) $row->dvehicle_07,
                        'on_non_911_transport' => $row->dvehicle_08 === null ? null : (int) $row->dvehicle_08,
                    ], fn (mixed $value): bool => $value !== null);
                }
            },
            'yearly_usage' => new class implements VehicleChildTable
            {
                public function table(): string
                {
                    return 'dem_vehicle_years';
                }

                public function parentColumn(): string
                {
                    return 'dem_vehicle_id';
                }

                public function keyColumn(): string
                {
                    return 'dvehicle_11';
                }

                public function toColumns(array $child): array
                {
                    return [
                        'dvehicle_11' => $child['year'] ?? null,
                        'dvehicle_12' => $child['hours'] ?? null,
                        'dvehicle_13' => $child['distance'] ?? null,
                    ];
                }

                public function pushColumns(): array
                {
                    return ['dvehicle_11', 'dvehicle_12', 'dvehicle_13'];
                }

                public function toImport(object $row): array
                {
                    return array_filter([
                        'year' => $row->dvehicle_11 === null ? null : (int) $row->dvehicle_11,
                        'hours' => $row->dvehicle_12 === null ? null : (int) $row->dvehicle_12,
                        'distance' => $row->dvehicle_13 === null ? null : (float) $row->dvehicle_13,
                    ], fn (mixed $value): bool => $value !== null);
                }
            },
        ];
    }
}
