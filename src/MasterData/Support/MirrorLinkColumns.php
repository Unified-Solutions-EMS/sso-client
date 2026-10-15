<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\CatalogMirror;

/**
 * The link and list columns a projected mirror's migration adds to the
 * app's table (agency, contacts, devices, state configurations), each
 * skipped when present: the SSO link column, the on/off column (existing
 * rows start on), `sort_order`, `sso_updated_at` and `sso_link_pending`,
 * plus a unique index on the link per company (on its own where the table
 * has no company_id). Nothing here deletes or rewrites a row, and no column
 * the app already has is changed.
 */
final class MirrorLinkColumns
{
    public static function add(string $table, string $ssoIdColumn, ?string $activeColumn, bool $sortOrder = true): void
    {
        $columns = array_filter([
            $ssoIdColumn => fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger($ssoIdColumn)->nullable(),
            (string) $activeColumn => $activeColumn === null ? null : fn (Blueprint $blueprint) => $blueprint->boolean($activeColumn)->default(true),
            'sort_order' => $sortOrder ? fn (Blueprint $blueprint) => $blueprint->unsignedInteger('sort_order')->default(0) : null,
            // SSO's own updated_at (UTC) for the row, so a delayed webhook
            // delivery cannot overwrite a newer one.
            CatalogMirror::SSO_UPDATED_AT_COLUMN => fn (Blueprint $blueprint) => $blueprint->timestamp(CatalogMirror::SSO_UPDATED_AT_COLUMN)->nullable(),
            // A row a delivery adopted before this app pushed its data to
            // SSO; deliveries leave it alone until the push, --link-by-name
            // or a full resync confirms it.
            CatalogMirror::LINK_PENDING_COLUMN => fn (Blueprint $blueprint) => $blueprint->boolean(CatalogMirror::LINK_PENDING_COLUMN)->default(false),
        ], fn (?\Closure $add, string $column): bool => $add !== null && $column !== '' && ! Schema::hasColumn($table, $column), ARRAY_FILTER_USE_BOTH);

        if ($columns !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $add) {
                    $add($blueprint);
                }
            });
        }

        $index = self::index($table, $ssoIdColumn);

        if (! Schema::hasIndex($table, $index)) {
            $unique = Schema::hasColumn($table, 'company_id') ? ['company_id', $ssoIdColumn] : [$ssoIdColumn];

            Schema::table($table, function (Blueprint $blueprint) use ($unique, $index): void {
                $blueprint->unique($unique, $index);
            });
        }
    }

    /**
     * Drops only the SSO link columns. The list columns (on/off,
     * sort_order) and a table the migration created stay: the app reads
     * them.
     */
    public static function drop(string $table, string $ssoIdColumn): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $index = self::index($table, $ssoIdColumn);

        if (Schema::hasIndex($table, $index)) {
            // MySQL may have adopted the unique index for the company_id
            // foreign key; give the key its own index before dropping it.
            $needsCompanyIndex = Schema::hasColumn($table, 'company_id') && ! collect(Schema::getIndexes($table))->contains(
                fn (array $existing): bool => $existing['name'] !== $index && ($existing['columns'][0] ?? null) === 'company_id',
            );

            Schema::table($table, function (Blueprint $blueprint) use ($needsCompanyIndex, $index, $table): void {
                if ($needsCompanyIndex) {
                    $blueprint->index('company_id', "{$table}_company_id_foreign");
                }

                $blueprint->dropUnique($index);
            });
        }

        $columns = array_values(array_filter(
            [$ssoIdColumn, CatalogMirror::SSO_UPDATED_AT_COLUMN, CatalogMirror::LINK_PENDING_COLUMN],
            fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($columns !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                $blueprint->dropColumn($columns);
            });
        }
    }

    public static function index(string $table, string $ssoIdColumn): string
    {
        return "{$table}_{$ssoIdColumn}_unique";
    }
}
