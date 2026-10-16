<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Support\ChildRowPolicy;
use Unified\SsoClient\MasterData\Support\MirrorLinkColumns;
use Unified\SsoClient\MasterData\Units\StandardUnitProjection;
use Unified\SsoClient\MasterData\Units\StandardUnitSeatTable;
use Unified\SsoClient\MasterData\Units\UnitMirror;

/**
 * Turns the app's unit table into a mirror of SSO's units, or creates the
 * standard `sso_units` + `sso_unit_seats` read model for an app with none.
 * Published by `vendor:publish --tag=sso-master-data-units`.
 *
 * The tables come from the app's bound UnitProjection (bind it BEFORE
 * migrating; CAD: units, Crew: resources, Fire: neris_station_units).
 * Adds `sso_unit_id`, `is_active` (existing rows start on), `sort_order`,
 * `sso_updated_at` and `sso_link_pending` to the unit table and
 * `sso_unit_seat_id` + `sso_removed_at` to the seat table, each skipped
 * when present. Nothing here deletes or rewrites a row, and no column the
 * app already has is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mirror = $this->mirror();
        $table = $mirror->table();

        if (! Schema::hasTable($table) && $table === StandardUnitProjection::TABLE) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
                $blueprint->string('name', 100);
                $blueprint->string('display_name')->nullable();
                $blueprint->string('unit_type', 30)->nullable();
                $blueprint->string('level_of_care', 10)->nullable();
                $blueprint->text('capabilities')->nullable();
                $blueprint->unsignedTinyInteger('crew_size')->nullable();
                $blueprint->unsignedBigInteger('vehicle_id')->nullable();
                $blueprint->unsignedBigInteger('location_id')->nullable();
                $blueprint->unsignedBigInteger('division_id')->nullable();
                $blueprint->timestamps();
            });
        }

        MirrorLinkColumns::add($table, UnitMirror::SSO_ID_COLUMN, $mirror->activeColumn());

        $seats = $mirror->units()->seatTable();

        if ($seats === null) {
            return;
        }

        if (! Schema::hasTable($seats->table()) && $seats->table() === StandardUnitSeatTable::TABLE) {
            Schema::create($seats->table(), function (Blueprint $blueprint) use ($table): void {
                $blueprint->id();
                $blueprint->foreignId('unit_id')->constrained($table)->cascadeOnDelete();
                $blueprint->unsignedTinyInteger('position')->nullable();
                $blueprint->string('label');
                $blueprint->unsignedBigInteger('qualification_id')->nullable();
                $blueprint->unsignedInteger('sort_order')->default(0);
                $blueprint->timestamps();
            });
        }

        $missing = array_filter([
            UnitMirror::SEAT_SSO_ID_COLUMN => fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger(UnitMirror::SEAT_SSO_ID_COLUMN)->nullable(),
            ChildRowPolicy::REMOVED_AT_COLUMN => fn (Blueprint $blueprint) => $blueprint->timestamp(ChildRowPolicy::REMOVED_AT_COLUMN)->nullable(),
        ], fn (string $column): bool => ! Schema::hasColumn($seats->table(), $column), ARRAY_FILTER_USE_KEY);

        if ($missing !== []) {
            Schema::table($seats->table(), function (Blueprint $blueprint) use ($missing): void {
                foreach ($missing as $add) {
                    $add($blueprint);
                }
            });
        }

        $index = "{$seats->table()}_parent_sso_unit_seat_unique";

        if (! Schema::hasIndex($seats->table(), $index)) {
            Schema::table($seats->table(), function (Blueprint $blueprint) use ($seats, $index): void {
                $blueprint->unique([$seats->parentColumn(), UnitMirror::SEAT_SSO_ID_COLUMN], $index);
            });
        }
    }

    /**
     * Drops only the SSO link columns; the tables and their data stay.
     */
    public function down(): void
    {
        $mirror = $this->mirror();
        $seats = $mirror->units()->seatTable();

        if ($seats !== null && Schema::hasTable($seats->table()) && Schema::hasColumn($seats->table(), UnitMirror::SEAT_SSO_ID_COLUMN)) {
            $index = "{$seats->table()}_parent_sso_unit_seat_unique";

            Schema::table($seats->table(), function (Blueprint $blueprint) use ($seats, $index): void {
                if (Schema::hasIndex($seats->table(), $index)) {
                    $blueprint->dropUnique($index);
                }

                $blueprint->dropColumn(UnitMirror::SEAT_SSO_ID_COLUMN);
            });
        }

        MirrorLinkColumns::drop($mirror->table(), UnitMirror::SSO_ID_COLUMN);
    }

    private function mirror(): UnitMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror('units');

        assert($mirror instanceof UnitMirror);

        return $mirror;
    }
};
