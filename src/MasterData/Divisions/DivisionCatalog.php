<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Divisions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * Read side of the divisions mirror for pickers and filters. Goes through the
 * registry so an app that bound a DivisionMirror subclass (different table or
 * column names) is read the same way it is written.
 *
 * "Usable" means turned on. Before the mirror migration has run every row is
 * usable, which is how the app behaved before.
 */
class DivisionCatalog
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
            ->when($installed, fn ($query) => $query->where('is_active', true)->orderBy('sort_order'))
            ->orderBy('name')
            ->get();
    }

    /**
     * The person's division row in the company, turned off or not (they are
     * still recorded in it; show it as such), or null.
     */
    public static function forUser(int $userId, int $companyId): ?object
    {
        $mirror = static::mirror();
        $divisionId = $mirror->currentDivisionId($companyId, $userId);

        if ($divisionId === null) {
            return null;
        }

        return DB::table($mirror->table())->where('company_id', $companyId)->where('id', $divisionId)->first();
    }

    public static function flushSchemaCache(): void
    {
        self::$installed = [];
    }

    private static function mirror(): DivisionMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror('divisions');

        assert($mirror instanceof DivisionMirror);

        return $mirror;
    }
}
