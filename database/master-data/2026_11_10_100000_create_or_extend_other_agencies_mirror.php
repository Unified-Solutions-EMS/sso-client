<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\OtherAgencies\OtherAgencyMirror;
use Unified\SsoClient\MasterData\Support\MirrorLinkColumns;

/**
 * Turns the app's other-agencies table into a mirror of SSO's "Other
 * agencies" list, or creates the standard `sso_other_agencies` read model
 * for an app with none. Published by
 * `vendor:publish --tag=sso-master-data-other-agencies`.
 *
 * The table comes from the app's bound OtherAgencyProjection (bind it
 * BEFORE migrating if it is not the standard one). Adds
 * `sso_other_agency_id`, `is_active` (existing rows start on),
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
            Schema::create($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->id();
                $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
                $blueprint->uuid('uuid')->nullable();
                $blueprint->string('name', 200);
                $blueprint->string('state', 2)->nullable();
                $blueprint->string('state_agency_id', 50)->nullable();
                $blueprint->string('agency_number', 50)->nullable();
                $blueprint->string('service_type', 10)->nullable();
                $blueprint->timestamps();

                $blueprint->index(['company_id', 'name'], "{$table}_company_name_index");
            });
        }

        MirrorLinkColumns::add($table, OtherAgencyMirror::SSO_ID_COLUMN, $mirror->activeColumn());
    }

    /**
     * Drops only the SSO link columns; the table and its data stay.
     */
    public function down(): void
    {
        MirrorLinkColumns::drop($this->mirror()->table(), OtherAgencyMirror::SSO_ID_COLUMN);
    }

    private function mirror(): OtherAgencyMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror('other_agencies');

        assert($mirror instanceof OtherAgencyMirror);

        return $mirror;
    }
};
