<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Vehicles;

/**
 * How one app stores SSO vehicles: which table and columns, how an SSO row
 * lands on them, and where the crew-level and yearly-usage child rows live.
 * VehicleMirror owns everything shared (SSO link, stale guard, pending
 * adoption, turn off never delete, location and division translation, the
 * in-place child reconcile, the push mapping); the projection owns only the
 * app's shape.
 *
 * The package binds StandardVehicleProjection (a `vehicles` table with the
 * contract's own column names, written only where the column exists). An
 * app with another shape binds its own in AppServiceProvider::register(),
 * BEFORE running the mirror migration:
 *
 *     $this->app->bind(VehicleProjection::class, DemVehicleProjection::class);
 *
 * CloudPCR's `dem_vehicles` (dvehicle_01 unit number, 02 VIN, 03 call sign,
 * ...) with `dem_vehicle_cert_levels` and `dem_vehicle_years` as children is
 * the case this exists for; tests/Stubs/DemVehicleProjection.php is the
 * template.
 *
 * Every method that touches the database gets the local company id the
 * mirror resolved from an authoritative SSO id and must filter by it
 * (DEV_GUIDELINES §4a). Projections never delete the vehicle row.
 */
interface VehicleProjection
{
    public function table(): string;

    /** The local column holding the unit number (SSO `unit_number`, dVehicle.01). */
    public function unitNumberColumn(): string;

    /** The local VIN column, or null. Adoption tries it before the unit number. */
    public function vinColumn(): ?string;

    /** The local call sign column, or null. Adoption tries it after the unit number. */
    public function callSignColumn(): ?string;

    /** The local column holding the home location's local id, or null. */
    public function homeLocationColumn(): ?string;

    /** The local column holding the division's local id, or null. */
    public function divisionColumn(): ?string;

    /**
     * Local columns for an SSO vehicle row (the `vehicle` object of the
     * webhook, or one row of the snapshot), other than the unit number, the
     * active flag, the home location, the division and the SSO link
     * columns, which the mirror writes itself. Return the same values for
     * the same row, so an unchanged row compares equal. Columns the app
     * owns alone (Fleet's mileage, notes) must never appear here.
     *
     * @param  array<string, mixed>  $vehicle
     * @return array<string, mixed>
     */
    public function toColumns(array $vehicle): array;

    /**
     * Local columns the push reads besides id, unit number, active flag,
     * home location and division.
     *
     * @return list<string>
     */
    public function pushColumns(): array;

    /**
     * The import fields for one local row (SSO's POST .../vehicles/import),
     * other than `local_id`, `unit_number`, `is_active`, the location and
     * division references and the child lists, which the mirror adds: any
     * of `uuid`, `call_sign`, `export_unit_number`, `export_call_sign`,
     * `vin`, `vehicle_type`, `category`, `model_year`, `initial_cost`,
     * `make`, `model`, `color`, `license_plate`, `license_plate_state`,
     * `fuel_type`, `status`. Leave out what the app does not have.
     *
     * @return array<string, mixed>
     */
    public function toImport(int $localCompanyId, object $row): array;

    /**
     * The app's child tables, keyed by the SSO list they hold:
     * `crew_levels` and/or `yearly_usage`. Empty when the app keeps
     * neither.
     *
     * @return array<'crew_levels'|'yearly_usage', VehicleChildTable>
     */
    public function childTables(): array;
}
