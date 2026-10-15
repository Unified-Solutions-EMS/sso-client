<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\Contacts\ContactMirror;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Support\MirrorLinkColumns;

/**
 * Turns the app's contact table into a mirror of SSO's agency contacts, or
 * creates the standard `sso_agency_contacts` read model for an app with
 * none. Published by `vendor:publish --tag=sso-master-data-contacts`.
 *
 * The table comes from the app's bound ContactProjection (bind it BEFORE
 * migrating; CloudPCR: dem_contacts). Adds `sso_contact_id`, `is_active`
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
                $blueprint->string('type', 50)->nullable();
                $blueprint->string('nemsis_type', 20)->nullable();
                $blueprint->boolean('is_primary')->default(false);
                $blueprint->string('first_name', 100)->nullable();
                $blueprint->string('middle_name', 100)->nullable();
                $blueprint->string('last_name', 100)->nullable();
                $blueprint->string('title')->nullable();
                $blueprint->text('phones')->nullable();
                $blueprint->text('emails')->nullable();
                $blueprint->text('address')->nullable();
                $blueprint->string('website')->nullable();
                $blueprint->text('medical_director')->nullable();
                $blueprint->string('fellowship_status', 20)->nullable();
                $blueprint->unsignedBigInteger('sso_user_id')->nullable();
                $blueprint->text('not_values')->nullable();
                $blueprint->timestamps();
            });
        }

        MirrorLinkColumns::add($table, ContactMirror::SSO_ID_COLUMN, $mirror->activeColumn());
    }

    /**
     * Drops only the SSO link columns; the table and its data stay.
     */
    public function down(): void
    {
        MirrorLinkColumns::drop($this->mirror()->table(), ContactMirror::SSO_ID_COLUMN);
    }

    private function mirror(): ContactMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror('contacts');

        assert($mirror instanceof ContactMirror);

        return $mirror;
    }
};
