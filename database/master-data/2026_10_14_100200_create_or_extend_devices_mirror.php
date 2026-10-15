<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\Devices\DeviceMirror;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Support\MirrorLinkColumns;

/**
 * Turns the app's device table into a mirror of SSO's devices, or creates
 * the standard `sso_devices` read model for an app with none. Published by
 * `vendor:publish --tag=sso-master-data-devices`.
 *
 * The table comes from the app's bound DeviceProjection (bind it BEFORE
 * migrating; CloudPCR: dem_devices). Adds `sso_device_id`, `is_active`
 * (existing rows start on), `sort_order`, `sso_updated_at` and
 * `sso_link_pending`, each skipped when present. Nothing here deletes or
 * rewrites a row.
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
                $blueprint->string('serial', 100)->nullable();
                $blueprint->string('name')->nullable();
                $blueprint->text('device_types')->nullable();
                $blueprint->string('manufacturer')->nullable();
                $blueprint->string('model')->nullable();
                $blueprint->date('purchase_date')->nullable();
                $blueprint->string('source', 20)->nullable();
                $blueprint->timestamp('last_seen_at')->nullable();
                $blueprint->unsignedBigInteger('vehicle_id')->nullable();
                $blueprint->text('not_values')->nullable();
                $blueprint->timestamps();
            });
        }

        MirrorLinkColumns::add($table, DeviceMirror::SSO_ID_COLUMN, $mirror->activeColumn());
    }

    /**
     * Drops only the SSO link columns; the table and its data stay.
     */
    public function down(): void
    {
        MirrorLinkColumns::drop($this->mirror()->table(), DeviceMirror::SSO_ID_COLUMN);
    }

    private function mirror(): DeviceMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror('devices');

        assert($mirror instanceof DeviceMirror);

        return $mirror;
    }
};
