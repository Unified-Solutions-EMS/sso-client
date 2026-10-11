<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Facilities;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * Read side of the facilities mirror for pickers: the company's turned-on
 * facilities in SSO's order. Goes through the registry so an app's bound
 * projection (CloudPCR's entries under type groups) is read the way it is
 * written.
 *
 * Before the mirror migration has run every row is usable, which is how the
 * app behaved before.
 */
class FacilityCatalog
{
    /**
     * @var array<string, bool>
     */
    private static array $installed = [];

    /**
     * @return Collection<int, object>
     */
    public static function usableForCompany(int $companyId): Collection
    {
        $mirror = static::mirror();
        $installed = self::$installed[DB::connection()->getName()] ??= $mirror->isInstalled();

        return $mirror->forCompany($companyId)
            ->when($installed, fn ($query) => $query->where($mirror->table().'.'.$mirror->activeColumn(), true)->orderBy($mirror->table().'.sort_order'))
            ->orderBy($mirror->table().'.'.$mirror->nameColumn())
            ->get();
    }

    public static function flushSchemaCache(): void
    {
        self::$installed = [];
    }

    private static function mirror(): FacilityMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror('facilities');

        assert($mirror instanceof FacilityMirror);

        return $mirror;
    }
}
