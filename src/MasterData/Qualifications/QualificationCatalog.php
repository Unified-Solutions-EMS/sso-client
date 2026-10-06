<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Qualifications;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read side of the qualifications mirror: what this app should offer and
 * enforce for one company.
 *
 * "Usable" means active and applicable to this app: `applies_to` empty (all
 * apps) or containing `sso.app_slug`. On an app that has not run the mirror
 * migration every row is usable, which is the behaviour it had before.
 */
class QualificationCatalog
{
    /**
     * @var array<string, bool>
     */
    private static array $mirrorColumns = [];

    /**
     * @return Collection<int, object>
     */
    public static function usableForCompany(int $companyId): Collection
    {
        return static::filterUsable(
            DB::table(QualificationMirror::TABLE)->where('company_id', $companyId)->orderBy('name')->get()
        );
    }

    /**
     * The user's usable qualifications in one company.
     *
     * @return Collection<int, object>
     */
    public static function heldBy(int $userId, int $companyId): Collection
    {
        return static::filterUsable(
            DB::table(QualificationMirror::TABLE)
                ->join(QualificationMirror::ASSIGNMENT_TABLE, QualificationMirror::ASSIGNMENT_TABLE.'.qualification_id', '=', QualificationMirror::TABLE.'.id')
                ->where(QualificationMirror::ASSIGNMENT_TABLE.'.user_id', $userId)
                ->where(QualificationMirror::ASSIGNMENT_TABLE.'.company_id', $companyId)
                ->where(QualificationMirror::TABLE.'.company_id', $companyId)
                ->select(QualificationMirror::TABLE.'.*')
                ->distinct()
                ->orderBy(QualificationMirror::TABLE.'.name')
                ->get()
        );
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    private static function filterUsable(Collection $rows): Collection
    {
        if (! static::mirrorInstalled()) {
            return $rows->values();
        }

        $slug = (string) config('sso.app_slug');

        return $rows->filter(function (object $row) use ($slug): bool {
            if (! (bool) $row->is_active) {
                return false;
            }

            $appliesTo = is_string($row->applies_to) ? json_decode($row->applies_to, true) : null;

            return ! is_array($appliesTo) || $appliesTo === [] || in_array($slug, $appliesTo, true);
        })->values();
    }

    private static function mirrorInstalled(): bool
    {
        $connection = DB::connection()->getName();

        return self::$mirrorColumns[$connection] ??= Schema::hasColumns(QualificationMirror::TABLE, ['applies_to', 'is_active']);
    }

    public static function flushSchemaCache(): void
    {
        self::$mirrorColumns = [];
    }
}
