<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\Facilities\FacilityMirror;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * Turns the app's facility table into a mirror of SSO's facilities, or
 * creates the standard one for an app that has none. Published by
 * `vendor:publish --tag=sso-master-data-facilities`.
 *
 * The table and name column come from the app's bound FacilityProjection,
 * so the same file extends a `facilities` table and CloudPCR's
 * `dem_facility_entries` (bind the projection BEFORE migrating). Only link
 * and list columns are added, each skipped when present: `sso_facility_id`,
 * `is_active` (existing rows start on), `sort_order`, `sso_updated_at`,
 * `sso_link_pending`. The SSO link is unique per company where the table
 * has a company_id, otherwise on its own (SSO ids are unique platform-wide,
 * and CloudPCR's entries reach their company through the type group).
 * Nothing here deletes or rewrites a row, and no column the app already has
 * is changed.
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
                $blueprint->string($mirror->nameColumn(), 100);
                $blueprint->string('type', 20)->nullable();
                $blueprint->string('location_code', 50)->nullable();
                $blueprint->text('designations')->nullable();
                $blueprint->text('npis')->nullable();
                $blueprint->string('fax', 30)->nullable();
                $blueprint->string('address_suite', 50)->nullable();
                $blueprint->string('address_street')->nullable();
                $blueprint->string('address_street2')->nullable();
                $blueprint->string('address_city_gnis', 20)->nullable();
                $blueprint->string('address_city_name', 100)->nullable();
                $blueprint->string('address_state', 2)->nullable();
                $blueprint->string('address_zip', 10)->nullable();
                $blueprint->string('address_county', 5)->nullable();
                $blueprint->string('address_country', 2)->nullable();
                $blueprint->decimal('latitude', 9, 6)->nullable();
                $blueprint->decimal('longitude', 9, 6)->nullable();
                $blueprint->string('registry_state', 2)->nullable();
                $blueprint->string('registry_code', 50)->nullable();
                $blueprint->timestamps();
            });
        }

        $columns = [
            FacilityMirror::SSO_ID_COLUMN => fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger(FacilityMirror::SSO_ID_COLUMN)->nullable(),
            $mirror->activeColumn() => fn (Blueprint $blueprint) => $blueprint->boolean($mirror->activeColumn())->default(true),
            'sort_order' => fn (Blueprint $blueprint) => $blueprint->unsignedInteger('sort_order')->default(0),
            // SSO's own updated_at (UTC) for the row, so a delayed webhook
            // delivery cannot overwrite a newer one.
            FacilityMirror::SSO_UPDATED_AT_COLUMN => fn (Blueprint $blueprint) => $blueprint->timestamp(FacilityMirror::SSO_UPDATED_AT_COLUMN)->nullable(),
            // A row a webhook linked by code or by name and ZIP before this
            // app pushed its facilities to SSO; deliveries leave it alone
            // until the push, --link-by-name or a full resync confirms it.
            FacilityMirror::LINK_PENDING_COLUMN => fn (Blueprint $blueprint) => $blueprint->boolean(FacilityMirror::LINK_PENDING_COLUMN)->default(false),
        ];

        $missing = array_filter($columns, fn (string $column): bool => ! Schema::hasColumn($table, $column), ARRAY_FILTER_USE_KEY);

        if ($missing !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($missing): void {
                foreach ($missing as $add) {
                    $add($blueprint);
                }
            });
        }

        $index = $this->uniqueIndex($table);

        if (! Schema::hasIndex($table, $index)) {
            $unique = Schema::hasColumn($table, 'company_id') ? ['company_id', FacilityMirror::SSO_ID_COLUMN] : [FacilityMirror::SSO_ID_COLUMN];

            Schema::table($table, function (Blueprint $blueprint) use ($unique, $index): void {
                $blueprint->unique($unique, $index);
            });
        }
    }

    /**
     * Drops only the SSO link columns. The list columns (is_active,
     * sort_order) and a table this migration created stay: the app reads
     * them and other rows point at the facilities.
     */
    public function down(): void
    {
        $table = $this->mirror()->table();
        $index = $this->uniqueIndex($table);

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
            [FacilityMirror::SSO_ID_COLUMN, FacilityMirror::SSO_UPDATED_AT_COLUMN, FacilityMirror::LINK_PENDING_COLUMN],
            fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($columns !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                $blueprint->dropColumn($columns);
            });
        }
    }

    private function mirror(): FacilityMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror('facilities');

        assert($mirror instanceof FacilityMirror);

        return $mirror;
    }

    private function uniqueIndex(string $table): string
    {
        return "{$table}_sso_facility_unique";
    }
};
