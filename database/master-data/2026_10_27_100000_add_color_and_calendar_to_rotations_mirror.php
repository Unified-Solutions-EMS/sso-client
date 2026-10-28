<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\Rotations\StandardRotationProjection;

/**
 * Adds SSO's rotation `color` (`#rrggbb`) and `show_on_calendar` to an
 * `sso_rotations` table created before they existed (UNI-1226). A fresh
 * install gets both from create_or_extend_rotations_mirror, so every step
 * checks first. Published by `vendor:publish --tag=sso-master-data-rotations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = StandardRotationProjection::TABLE;

        if (! Schema::hasTable($table)) {
            return;
        }

        if (! Schema::hasColumn($table, 'color')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('color', 7)->nullable();
            });
        }

        if (! Schema::hasColumn($table, 'show_on_calendar')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->boolean('show_on_calendar')->default(false);
            });
        }
    }

    public function down(): void
    {
        $table = StandardRotationProjection::TABLE;

        foreach (['show_on_calendar', 'color'] as $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->dropColumn($column);
                });
            }
        }
    }
};
