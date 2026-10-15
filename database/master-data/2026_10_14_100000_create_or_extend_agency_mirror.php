<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\Agency\AgencyMirror;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Support\MirrorLinkColumns;

/**
 * Turns the app's agency table into a mirror of SSO's agency information,
 * or creates the standard `sso_agency_profiles` read model for an app with
 * none. Published by `vendor:publish --tag=sso-master-data-agency`.
 *
 * The table comes from the app's bound AgencyProjection (bind it BEFORE
 * migrating; CloudPCR: dem_agencies). Adds `sso_agency_id`,
 * `sso_updated_at` and `sso_link_pending`, each skipped when present; the
 * agency has no on/off switch. Nothing here deletes or rewrites a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = $this->mirror()->table();

        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
                $blueprint->string('state', 2)->nullable();
                $blueprint->string('state_agency_id', 50)->nullable();
                $blueprint->string('agency_number', 50)->nullable();
                $blueprint->string('npi', 10)->nullable();
                $blueprint->string('tax_id', 20)->nullable();
                $blueprint->string('tax_status', 20)->nullable();
                $blueprint->string('license_number', 50)->nullable();
                $blueprint->boolean('is_licensed_agency')->nullable();
                $blueprint->string('primary_service_type', 20)->nullable();
                $blueprint->text('other_service_types')->nullable();
                $blueprint->string('level_of_service', 20)->nullable();
                $blueprint->string('organization_status', 20)->nullable();
                $blueprint->string('organization_type', 20)->nullable();
                $blueprint->string('fire_department_id', 50)->nullable();
                $blueprint->string('neris_department_id', 50)->nullable();
                $blueprint->unsignedSmallInteger('statistical_year')->nullable();
                $blueprint->unsignedInteger('total_calls')->nullable();
                $blueprint->text('address')->nullable();
                $blueprint->text('mailing')->nullable();
                $blueprint->decimal('latitude', 9, 6)->nullable();
                $blueprint->decimal('longitude', 9, 6)->nullable();
                $blueprint->string('phone', 30)->nullable();
                $blueprint->string('fax', 30)->nullable();
                $blueprint->string('email')->nullable();
                $blueprint->string('website')->nullable();
                $blueprint->text('service_areas')->nullable();
                $blueprint->text('year_stats')->nullable();
                $blueprint->text('custom_values')->nullable();
                $blueprint->text('not_values')->nullable();
                $blueprint->timestamps();
            });
        }

        MirrorLinkColumns::add($table, AgencyMirror::SSO_ID_COLUMN, activeColumn: null, sortOrder: false);
    }

    /**
     * Drops only the SSO link columns; the table and its data stay.
     */
    public function down(): void
    {
        MirrorLinkColumns::drop($this->mirror()->table(), AgencyMirror::SSO_ID_COLUMN);
    }

    private function mirror(): AgencyMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror('agency');

        assert($mirror instanceof AgencyMirror);

        return $mirror;
    }
};
