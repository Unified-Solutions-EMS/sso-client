<?php

declare(strict_types=1);

namespace Unified\SsoClient\Trial;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\Devices\DeviceProjection;
use Unified\SsoClient\MasterData\Divisions\DivisionMirror;
use Unified\SsoClient\MasterData\Locations\LocationProjection;
use Unified\SsoClient\MasterData\Vehicles\VehicleProjection;
use Unified\SsoClient\Trial\Contracts\TrialMirrorCleanupPolicy;

/**
 * Deletes unless a reference the package knows about still points at the row,
 * in which case the row is only turned off:
 * - a division a person, location or vehicle of the company is still in
 *   (Crew's locations cascade-delete with their division, so deleting one
 *   under a location the prospect added would take the location with it);
 * - a location a vehicle of the company still calls home;
 * - a vehicle a device of the company is still on.
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
    ) {}

    public function decide(string $entity, int $localCompanyId, object $row): TrialMirrorCleanupAction
    {
        $references = match ($entity) {
            'divisions' => [
                [$this->divisions->assignmentTable(), $this->divisions->assignmentColumn()],
                [$this->locations->table(), $this->locations->divisionColumn()],
                [$this->vehicles->table(), $this->vehicles->divisionColumn()],
            ],
            'locations' => [
                [$this->vehicles->table(), $this->vehicles->homeLocationColumn()],
            ],
            'vehicles' => $this->devices === null ? [] : [
                [$this->devices->table(), $this->devices->vehicleColumn()],
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

    public function beforeDelete(string $entity, int $localCompanyId, object $row): void {}

    protected function referenced(string $table, ?string $column, int $localCompanyId, int $localId): bool
    {
        if ($column === null || ! Schema::hasTable($table) || ! Schema::hasColumns($table, ['company_id', $column])) {
            return false;
        }

        return DB::table($table)->where('company_id', $localCompanyId)->where($column, $localId)->exists();
    }
}
