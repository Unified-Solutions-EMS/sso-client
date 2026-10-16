<?php

declare(strict_types=1);

namespace Unified\SsoClient\Trial;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\Devices\DeviceProjection;
use Unified\SsoClient\MasterData\Divisions\DivisionMirror;
use Unified\SsoClient\MasterData\Locations\LocationProjection;
use Unified\SsoClient\MasterData\Units\StandardUnitProjection;
use Unified\SsoClient\MasterData\Units\UnitProjection;
use Unified\SsoClient\MasterData\Vehicles\VehicleProjection;
use Unified\SsoClient\Trial\Contracts\TrialMirrorCleanupPolicy;

/**
 * Deletes unless a reference the package knows about still points at the row,
 * in which case the row is only turned off:
 * - a division a person, location or vehicle of the company is still in
 *   (Crew's locations cascade-delete with their division, so deleting one
 *   under a location the prospect added would take the location with it);
 * - a location a vehicle of the company still calls home;
 * - a vehicle a device of the company is still on;
 * - a vehicle, location or division a unit of the company still names;
 * - a unit in the app's own unit table (CAD's units, Crew's resources):
 *   those carry the app's schedules and templates, so only the package's
 *   `sso_units` read model is ever deleted.
 *
 * The cleaner deletes devices before vehicles, vehicles before locations
 * and locations before divisions, so the sample rows' own references are
 * gone by the time these
 * checks run; what is left belongs to the prospect. Any other reference an
 * app's foreign keys enforce makes the delete fail, and the cleaner then
 * deactivates. Apps extend this to add references the database cannot see.
 */
class DefaultTrialMirrorCleanupPolicy implements TrialMirrorCleanupPolicy
{
    public function __construct(
        private readonly DivisionMirror $divisions,
        private readonly LocationProjection $locations,
        private readonly VehicleProjection $vehicles,
        private readonly ?DeviceProjection $devices = null,
        private readonly ?UnitProjection $units = null,
    ) {}

    public function decide(string $entity, int $localCompanyId, object $row): TrialMirrorCleanupAction
    {
        if ($entity === 'units' && $this->units !== null && $this->units->table() !== StandardUnitProjection::TABLE) {
            return TrialMirrorCleanupAction::Deactivate;
        }

        $references = match ($entity) {
            'divisions' => [
                [$this->divisions->assignmentTable(), $this->divisions->assignmentColumn()],
                [$this->locations->table(), $this->locations->divisionColumn()],
                [$this->vehicles->table(), $this->vehicles->divisionColumn()],
                ...$this->unitReference(fn (UnitProjection $units): ?string => $units->divisionColumn()),
            ],
            'locations' => [
                [$this->vehicles->table(), $this->vehicles->homeLocationColumn()],
                ...$this->unitReference(fn (UnitProjection $units): ?string => $units->locationColumn()),
            ],
            'vehicles' => [
                ...($this->devices === null ? [] : [[$this->devices->table(), $this->devices->vehicleColumn()]]),
                ...$this->unitReference(fn (UnitProjection $units): ?string => $units->vehicleColumn()),
            ],
            default => [],
        };

        foreach ($references as [$table, $column]) {
            if ($this->referenced($table, $column, $localCompanyId, (int) $row->id)) {
                return TrialMirrorCleanupAction::Deactivate;
            }
        }

        return TrialMirrorCleanupAction::Delete;
    }

    /**
     * @param  \Closure(UnitProjection): ?string  $column
     * @return list<array{0: string, 1: ?string}>
     */
    private function unitReference(\Closure $column): array
    {
        return $this->units === null ? [] : [[$this->units->table(), $column($this->units)]];
    }

    public function beforeDelete(string $entity, int $localCompanyId, object $row): void {}

    protected function referenced(string $table, ?string $column, int $localCompanyId, int $localId): bool
    {
        if ($column === null || ! Schema::hasTable($table) || ! Schema::hasColumns($table, ['company_id', $column])) {
            return false;
        }

        return DB::table($table)->where('company_id', $localCompanyId)->where($column, $localId)->exists();
    }
}
