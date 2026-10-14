<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Vehicles\VehicleMirror;

/**
 * Turns the app's vehicle table into a mirror of SSO's vehicles, or creates
 * the standard one for an app that has none. Published by
 * `vendor:publish --tag=sso-master-data-vehicles`.
 *
 * The table, unit number column and child tables come from the app's bound
 * VehicleProjection, so the same file extends Fleet's `vehicles` and
 * CloudPCR's `dem_vehicles` + `dem_vehicle_cert_levels` / `dem_vehicle_years`
 * (bind the projection BEFORE migrating). Only link and list columns are
 * added, each skipped when present: `sso_vehicle_id`, `is_active` (existing
 * rows start on), `sort_order`, `sso_updated_at`, `sso_link_pending` on the
 * vehicle table and `sso_crew_level_id` / `sso_yearly_usage_id` on the child
 * tables. Nothing here deletes or rewrites a row, and no column the app
 * already has is changed.
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
                $blueprint->string($mirror->nameColumn(), 25);
                $blueprint->string('call_sign', 50)->nullable();
                $blueprint->string('export_unit_number', 25)->nullable();
                $blueprint->string('export_call_sign', 50)->nullable();
                $blueprint->string('vin', 17)->nullable();
                $blueprint->string('vehicle_type', 20)->nullable();
                $blueprint->unsignedSmallInteger('model_year')->nullable();
                $blueprint->unsignedInteger('initial_cost')->nullable();
                $blueprint->string('make')->nullable();
                $blueprint->string('model')->nullable();
                $blueprint->string('color')->nullable();
                $blueprint->string('license_plate', 20)->nullable();
                $blueprint->string('license_plate_state', 2)->nullable();
                $blueprint->string('fuel_type', 20)->nullable();
                $blueprint->string('status', 20)->nullable();
                $blueprint->unsignedBigInteger('home_location_id')->nullable();
                $blueprint->unsignedBigInteger('division_id')->nullable();
                $blueprint->timestamps();
            });
        }

        $columns = [
            VehicleMirror::SSO_ID_COLUMN => fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger(VehicleMirror::SSO_ID_COLUMN)->nullable()->after('company_id'),
            $mirror->activeColumn() => fn (Blueprint $blueprint) => $blueprint->boolean($mirror->activeColumn())->default(true),
            'sort_order' => fn (Blueprint $blueprint) => $blueprint->unsignedInteger('sort_order')->default(0),
            // SSO's own updated_at (UTC) for the row, so a delayed webhook
            // delivery cannot overwrite a newer one.
            VehicleMirror::SSO_UPDATED_AT_COLUMN => fn (Blueprint $blueprint) => $blueprint->timestamp(VehicleMirror::SSO_UPDATED_AT_COLUMN)->nullable(),
            // A row a webhook linked by VIN, unit number or call sign before
            // this app pushed its vehicles to SSO; deliveries leave it alone
            // until the push, --link-by-name or a full resync confirms it.
            VehicleMirror::LINK_PENDING_COLUMN => fn (Blueprint $blueprint) => $blueprint->boolean(VehicleMirror::LINK_PENDING_COLUMN)->default(false),
        ];

        $this->addMissing($table, $columns);
        $this->addUnique($table, ['company_id', VehicleMirror::SSO_ID_COLUMN], "{$table}_company_sso_vehicle_unique");

        foreach ($mirror->childTables() as $kind => $child) {
            $column = VehicleMirror::CHILD_SSO_ID_COLUMNS[$kind];

            $this->addMissing($child->table(), [
                $column => fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger($column)->nullable(),
            ]);
            $this->addUnique($child->table(), [$child->parentColumn(), $column], "{$child->table()}_parent_{$column}_unique");
        }
    }

    /**
     * Drops only the SSO link columns. The list columns (is_active,
     * sort_order) and a table this migration created stay: the app reads
     * them and other rows point at the vehicles.
     */
    public function down(): void
    {
        $mirror = $this->mirror();
        $table = $mirror->table();

        foreach ($mirror->childTables() as $kind => $child) {
            $column = VehicleMirror::CHILD_SSO_ID_COLUMNS[$kind];
            $this->dropUnique($child->table(), "{$child->table()}_parent_{$column}_unique", $child->parentColumn());
            $this->dropColumns($child->table(), [$column]);
        }

        $this->dropUnique($table, "{$table}_company_sso_vehicle_unique", 'company_id');
        $this->dropColumns($table, [VehicleMirror::SSO_ID_COLUMN, VehicleMirror::SSO_UPDATED_AT_COLUMN, VehicleMirror::LINK_PENDING_COLUMN]);
    }

    /**
     * @param  array<string, Closure(Blueprint): mixed>  $columns
     */
    private function addMissing(string $table, array $columns): void
    {
        $missing = array_filter($columns, fn (string $column): bool => ! Schema::hasColumn($table, $column), ARRAY_FILTER_USE_KEY);

        if ($missing !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($missing): void {
                foreach ($missing as $add) {
                    $add($blueprint);
                }
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addUnique(string $table, array $columns, string $index): void
    {
        if (! Schema::hasIndex($table, $index)) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $index): void {
                $blueprint->unique($columns, $index);
            });
        }
    }

    private function dropUnique(string $table, string $index, string $leadingColumn): void
    {
        if (! Schema::hasIndex($table, $index)) {
            return;
        }

        // MySQL may have adopted the unique index for the leading column's
        // foreign key; give the key its own index before dropping it.
        $leadingIndexed = collect(Schema::getIndexes($table))->contains(
            fn (array $existing): bool => $existing['name'] !== $index && ($existing['columns'][0] ?? null) === $leadingColumn,
        );

        Schema::table($table, function (Blueprint $blueprint) use ($leadingIndexed, $leadingColumn, $index, $table): void {
            if (! $leadingIndexed) {
                $blueprint->index($leadingColumn, "{$table}_{$leadingColumn}_foreign");
            }

            $blueprint->dropUnique($index);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropColumns(string $table, array $columns): void
    {
        $present = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column)));

        if ($present !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($present): void {
                $blueprint->dropColumn($present);
            });
        }
    }

    private function mirror(): VehicleMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror('vehicles');

        assert($mirror instanceof VehicleMirror);

        return $mirror;
    }
};
