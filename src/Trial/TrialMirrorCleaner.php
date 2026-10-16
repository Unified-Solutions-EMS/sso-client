<?php

declare(strict_types=1);

namespace Unified\SsoClient\Trial;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\MasterData\CatalogMirror;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Rotations\RotationChildTable;
use Unified\SsoClient\MasterData\Rotations\RotationMirror;
use Unified\SsoClient\MasterData\Units\UnitMirror;
use Unified\SsoClient\MasterData\Vehicles\VehicleMirror;
use Unified\SsoClient\Trial\Contracts\TrialMirrorCleanupPolicy;

/**
 * Removes the local mirror rows of the sample lists SSO deleted when it
 * purged a trial (`trial.purge_data` `master_data.seeded`), after the app's
 * own purger has removed the extension rows that pointed at them.
 *
 * Only the sample lists are touched (design D1: registry facilities, state
 * medications/procedures, the agency's information and its state
 * configurations are the agency's real data and stay), only
 * rows linked to the listed SSO ids, and only in the one local company the
 * HMAC-verified payload named (§4a). Entities this app does not mirror are
 * skipped: an unlinked local list is the app's own, and its purger owns it.
 * Each row goes through the bound {@see TrialMirrorCleanupPolicy}, and a
 * delete a foreign key refuses becomes a deactivation.
 */
class TrialMirrorCleaner
{
    /**
     * Cleanup order: what points at a row is removed before the row
     * (a rotation assigns units; a device names its vehicle; a unit its
     * vehicle, location and division).
     */
    public const ENTITIES = ['rotations', 'units', 'devices', 'contacts', 'vehicles', 'locations', 'divisions', 'qualifications'];

    public function __construct(
        private readonly MasterDataRegistry $registry,
        private readonly TrialMirrorCleanupPolicy $policy,
    ) {}

    /**
     * @param  array<string, mixed>  $seeded  SSO ids per entity
     * @return array<string, array{deleted: int, deactivated: int, kept: int, missing: int}|string>
     */
    public function clean(int $localCompanyId, array $seeded): array
    {
        $report = [];

        foreach (self::ENTITIES as $entity) {
            $ssoIds = $this->ids($seeded[$entity] ?? []);

            if ($ssoIds === []) {
                continue;
            }

            $mirror = $this->registry->enabled($entity) ? $this->registry->mirror($entity) : null;

            if (! $mirror instanceof CatalogMirror || ! $mirror->isInstalled()) {
                $report[$entity] = 'not_mirrored';

                continue;
            }

            $report[$entity] = $this->cleanEntity($mirror, $entity, $localCompanyId, $ssoIds);
        }

        return $report;
    }

    /**
     * @param  list<int>  $ssoIds
     * @return array{deleted: int, deactivated: int, kept: int, missing: int}
     */
    private function cleanEntity(CatalogMirror $mirror, string $entity, int $localCompanyId, array $ssoIds): array
    {
        $counts = ['deleted' => 0, 'deactivated' => 0, 'kept' => 0, 'missing' => 0];

        $rows = $mirror->forCompany($localCompanyId)->whereIn($mirror->ssoIdColumn(), $ssoIds)->get();
        $counts['missing'] = count($ssoIds) - $rows->count();

        foreach ($rows as $row) {
            $action = $this->policy->decide($entity, $localCompanyId, $row);

            if ($action === TrialMirrorCleanupAction::Delete && ! $this->delete($mirror, $entity, $localCompanyId, $row)) {
                $action = TrialMirrorCleanupAction::Deactivate;
            }

            if ($action === TrialMirrorCleanupAction::Deactivate) {
                $mirror->forCompany($localCompanyId)->where('id', $row->id)->update([$mirror->activeColumn() => false, 'updated_at' => now()]);
            }

            $counts[match ($action) {
                TrialMirrorCleanupAction::Delete => 'deleted',
                TrialMirrorCleanupAction::Deactivate => 'deactivated',
                TrialMirrorCleanupAction::Keep => 'kept',
            }]++;
        }

        return $counts;
    }

    private function delete(CatalogMirror $mirror, string $entity, int $localCompanyId, object $row): bool
    {
        try {
            DB::transaction(function () use ($mirror, $entity, $localCompanyId, $row): void {
                $this->policy->beforeDelete($entity, $localCompanyId, $row);

                if ($mirror instanceof VehicleMirror) {
                    foreach ($mirror->childTables() as $child) {
                        DB::table($child->table())->where($child->parentColumn(), $row->id)->delete();
                    }
                }

                if ($mirror instanceof RotationMirror) {
                    foreach ([RotationChildTable::BLOCKS, RotationChildTable::ASSIGNMENTS] as $child) {
                        DB::table($child)->where(RotationChildTable::PARENT_COLUMN, $row->id)->delete();
                    }
                }

                $seats = $mirror instanceof UnitMirror ? $mirror->units()->seatTable() : null;

                if ($seats !== null) {
                    DB::table($seats->table())->where($seats->parentColumn(), $row->id)->delete();
                }

                $mirror->forCompany($localCompanyId)->where('id', $row->id)->delete();
            });

            return true;
        } catch (QueryException $e) {
            Log::info('Trial mirror cleanup: delete refused by the database, deactivating instead', [
                'entity' => $entity,
                'local_company_id' => $localCompanyId,
                'local_id' => $row->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
    }
}
