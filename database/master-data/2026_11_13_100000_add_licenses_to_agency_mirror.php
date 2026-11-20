<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\Agency\StandardAgencyProjection;

/**
 * Adds SSO's agency `licenses` (every state the agency reports to, JSON
 * text) to an `sso_agency_profiles` table created before it existed
 * (UNI-1597). A fresh install gets it from create_or_extend_agency_mirror,
 * and an app that binds its own projection keeps licenses in its own
 * tables, so this only touches the standard table and checks first.
 * Published by `vendor:publish --tag=sso-master-data-agency`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = StandardAgencyProjection::TABLE;

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'licenses')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->text('licenses')->nullable();
        });
    }

    public function down(): void
    {
        $table = StandardAgencyProjection::TABLE;

        if (Schema::hasTable($table) && Schema::hasColumn($table, 'licenses')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('licenses');
            });
        }
    }
};
