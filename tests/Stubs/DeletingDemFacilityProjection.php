<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Stubs;

use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\Facilities\DeletesFacilities;

/**
 * DemFacilityProjection that deletes unreferenced entries on facility.deleted,
 * the way CloudPCR does: an entry named in `referenced` is kept.
 */
class DeletingDemFacilityProjection extends DemFacilityProjection implements DeletesFacilities
{
    /** @var list<int> */
    public static array $referenced = [];

    public function deleteUnreferenced(int $localCompanyId, array $localIds): array
    {
        $deletable = array_values(array_diff($localIds, self::$referenced));

        DB::table('dem_facility_entry_phones')->whereIn('dem_facility_entry_id', $deletable)->delete();
        $this->scope(DB::table('dem_facility_entries'), $localCompanyId)->whereIn('id', $deletable)->delete();

        return $deletable;
    }
}
