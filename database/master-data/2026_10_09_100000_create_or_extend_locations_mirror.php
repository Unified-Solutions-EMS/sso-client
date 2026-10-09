<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\Locations\LocationMirror;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * Turns the app's location table into a mirror of SSO's locations, or
 * creates a minimal one for an app that has none. Published by
 * `vendor:publish --tag=sso-master-data-locations`.
 *
 * The table and name column come from the app's bound LocationProjection,
 * so the same file extends Crew-Scheduling's `locations` and CloudPCR's
 * `dem_locations` (bind the projection BEFORE migrating). Only the link and
 * list columns are added, each skipped when present: `sso_location_id`,
 * `is_active` (existing rows start on), `sort_order`, `sso_updated_at`,
 * `sso_link_pending`. Nothing here deletes or rewrites a row, and no column
 * the app already has (Crew's NOT NULL division_id included) is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mirror = $this->mirror();
        $table = $mirror->table();

        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $blueprint) use ($mirror): void {
                $blueprint->id();
                $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
                $blueprint->string($mirror->nameColumn());
                $blueprint->timestamps();
            });
        }

        $columns = [
            LocationMirror::SSO_ID_COLUMN => fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger(LocationMirror::SSO_ID_COLUMN)->nullable()->after('company_id'),
            $mirror->activeColumn() => fn (Blueprint $blueprint) => $blueprint->boolean($mirror->activeColumn())->default(true),
            'sort_order' => fn (Blueprint $blueprint) => $blueprint->unsignedInteger('sort_order')->default(0),
            // SSO's own updated_at (UTC) for the row, so a delayed webhook
            // delivery cannot overwrite a newer one.
            LocationMirror::SSO_UPDATED_AT_COLUMN => fn (Blueprint $blueprint) => $blueprint->timestamp(LocationMirror::SSO_UPDATED_AT_COLUMN)->nullable(),
            // A row a webhook linked by number or name before this app
            // pushed its locations to SSO; deliveries leave it alone until
            // the push, --link-by-name or a full resync confirms it.
            LocationMirror::LINK_PENDING_COLUMN => fn (Blueprint $blueprint) => $blueprint->boolean(LocationMirror::LINK_PENDING_COLUMN)->default(false),
        ];

        $missing = array_filter($columns, fn (string $column): bool => ! Schema::hasColumn($table, $column), ARRAY_FILTER_USE_KEY);

        if ($missing !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($missing): void {
                foreach ($missing as $add) {
                    $add($blueprint);
                }
            });
        }

        if (! Schema::hasIndex($table, $this->uniqueIndex($table))) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->unique(['company_id', LocationMirror::SSO_ID_COLUMN], $this->uniqueIndex($table));
            });
        }
    }

    /**
     * Drops only the SSO link columns. The list columns (is_active,
     * sort_order) and a table this migration created stay: the app reads
     * them and other rows point at the locations.
     */
    public function down(): void
    {
        $table = $this->mirror()->table();
        $index = $this->uniqueIndex($table);

        if (Schema::hasIndex($table, $index)) {
            // MySQL may have adopted the unique index for the company_id
            // foreign key; give the key its own index before dropping it.
            $companyIdIndexed = collect(Schema::getIndexes($table))->contains(
                fn (array $existing): bool => $existing['name'] !== $index && ($existing['columns'][0] ?? null) === 'company_id',
            );

            Schema::table($table, function (Blueprint $blueprint) use ($companyIdIndexed, $index, $table): void {
                if (! $companyIdIndexed) {
                    $blueprint->index('company_id', "{$table}_company_id_foreign");
                }

                $blueprint->dropUnique($index);
            });
        }

        $columns = array_values(array_filter(
            [LocationMirror::SSO_ID_COLUMN, LocationMirror::SSO_UPDATED_AT_COLUMN, LocationMirror::LINK_PENDING_COLUMN],
            fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($columns !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                $blueprint->dropColumn($columns);
            });
        }
    }

    private function mirror(): LocationMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror('locations');

        assert($mirror instanceof LocationMirror);

        return $mirror;
    }

    private function uniqueIndex(string $table): string
    {
        return "{$table}_company_sso_location_unique";
    }
};
