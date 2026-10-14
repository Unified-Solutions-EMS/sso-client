<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Personnel\PersonnelMirror;
use Unified\SsoClient\MasterData\Support\ChildRowPolicy;
use Unified\SsoClient\MasterData\Vehicles\VehicleMirror;

/**
 * Adds `sso_removed_at` to every personnel and vehicle child table the
 * app's bound projections name (each skipped when the table is missing or
 * already has it). A child row SSO removed and the app kept (ChildRowPolicy
 * unlink / deactivate) is stamped with it, and the push leaves stamped rows
 * out so a removed licence is not sent back up as new. Published with both
 * `sso-master-data-personnel` and `sso-master-data-vehicles`; bind the
 * projections BEFORE migrating. Nothing here deletes or rewrites a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->childTables() as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, ChildRowPolicy::REMOVED_AT_COLUMN)) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->timestamp(ChildRowPolicy::REMOVED_AT_COLUMN)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->childTables() as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, ChildRowPolicy::REMOVED_AT_COLUMN)) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropColumn(ChildRowPolicy::REMOVED_AT_COLUMN);
                });
            }
        }
    }

    /**
     * @return list<string>
     */
    private function childTables(): array
    {
        $registry = app(MasterDataRegistry::class);
        $tables = [];

        foreach (['personnel', 'vehicles'] as $entity) {
            $mirror = $registry->mirror($entity);

            assert($mirror instanceof PersonnelMirror || $mirror instanceof VehicleMirror);

            foreach ($mirror->childTables() as $child) {
                $tables[] = $child->table();
            }
        }

        return array_values(array_unique($tables));
    }
};
