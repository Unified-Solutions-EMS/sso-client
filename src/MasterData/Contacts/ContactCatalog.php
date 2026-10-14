<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Contacts;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * Read side of the agency contacts mirror: the company's turned-on contacts
 * in SSO's order, then by name. Goes through the registry so an app's bound
 * projection is read the way it is written.
 *
 * Before the mirror migration has run every row is usable, which is how the
 * app behaved before.
 */
class ContactCatalog
{
    /**
     * @var array<string, bool>
     */
    private static array $installed = [];

    /**
     * @return Collection<int, object>
     */
    public static function activeForCompany(int $companyId): Collection
    {
        $mirror = static::mirror();
        $table = $mirror->table();
        $installed = self::$installed[DB::connection()->getName()] ??= $mirror->isInstalled();

        return $mirror->forCompany($companyId)
            ->when($installed, fn ($query) => $query->where($table.'.'.$mirror->activeColumn(), true)->orderBy($table.'.sort_order'))
            ->orderBy($table.'.'.$mirror->nameColumn())
            ->get();
    }

    /**
     * Active contacts of one platform type (ContactMirror::TYPES keys), for
     * the standard table; an app with its own projection filters its own
     * type codes.
     *
     * @return Collection<int, object>
     */
    public static function ofType(int $companyId, string $type): Collection
    {
        $column = app(ContactProjection::class)->typeColumn();

        return static::activeForCompany($companyId)
            ->filter(fn (object $row): bool => (string) ($row->{$column} ?? '') === $type)
            ->values();
    }

    /**
     * The agency's primary contact, when the table keeps `is_primary`.
     */
    public static function primaryForCompany(int $companyId): ?object
    {
        return static::activeForCompany($companyId)->first(fn (object $row): bool => (bool) ($row->is_primary ?? false));
    }

    public static function flushSchemaCache(): void
    {
        self::$installed = [];
    }

    private static function mirror(): ContactMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror('agency_contacts');

        assert($mirror instanceof ContactMirror);

        return $mirror;
    }
}
