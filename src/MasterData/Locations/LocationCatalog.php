<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Locations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * Read side of the locations mirror for pickers: the company's turned-on
 * locations in SSO's order. Goes through the registry so an app's bound
 * projection (another table or name column) is read the way it is written.
 *
 * Before the mirror migration has run every row is usable, which is how the
 * app behaved before.
 */
class LocationCatalog
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

        return DB::table($mirror->table())
            ->where('company_id', $companyId)
            ->when($installed, fn ($query) => $query->where($mirror->activeColumn(), true)->orderBy('sort_order'))
            ->orderBy($mirror->nameColumn())
            ->get();
    }

    public static function flushSchemaCache(): void
    {
        self::$installed = [];
    }

    private static function mirror(): LocationMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror('locations');

        assert($mirror instanceof LocationMirror);

        return $mirror;
    }
}
