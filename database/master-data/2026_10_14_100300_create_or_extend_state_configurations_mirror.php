<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\StateConfigurations\StateConfigurationMirror;
use Unified\SsoClient\MasterData\Support\MirrorLinkColumns;

/**
 * Turns the app's state configuration table into a mirror of SSO's, or
 * creates the standard `sso_state_configurations` read model for an app
 * with none. Published by
 * `vendor:publish --tag=sso-master-data-state-configurations`.
 *
 * The table comes from the app's bound StateConfigurationProjection (bind
 * it BEFORE migrating; CloudPCR: dem_configurations). Adds
 * `sso_state_configuration_id`, `is_active` (existing rows start on),
 * `sort_order`, `sso_updated_at` and `sso_link_pending`, each skipped when
 * present. Nothing here deletes or rewrites a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mirror = $this->mirror();
        $table = $mirror->table();

        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
                $blueprint->string('state', 2)->nullable();
                $blueprint->text('protocols')->nullable();
                $blueprint->text('specialty_service_capabilities')->nullable();
                $blueprint->string('billing_status', 20)->nullable();
                $blueprint->string('emd_performed', 20)->nullable();
                $blueprint->string('emd_vendor')->nullable();
                $blueprint->text('patient_monitoring_capabilities')->nullable();
                $blueprint->text('crew_call_signs')->nullable();
                $blueprint->string('dispatch_center')->nullable();
                $blueprint->string('dispatch_center_phone', 30)->nullable();
                $blueprint->text('not_values')->nullable();
                $blueprint->timestamps();
            });
        }

        MirrorLinkColumns::add($table, StateConfigurationMirror::SSO_ID_COLUMN, $mirror->activeColumn());
    }

    /**
     * Drops only the SSO link columns; the table and its data stay.
     */
    public function down(): void
    {
        MirrorLinkColumns::drop($this->mirror()->table(), StateConfigurationMirror::SSO_ID_COLUMN);
    }

    private function mirror(): StateConfigurationMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror('state_configurations');

        assert($mirror instanceof StateConfigurationMirror);

        return $mirror;
    }
};
