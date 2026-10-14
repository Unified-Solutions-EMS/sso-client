<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\Procedures\ProcedureMirror;

/**
 * The procedures mirror table, one row per procedure code of SSO's agency list.
 * Published by `vendor:publish --tag=sso-master-data-procedures`. The table is
 * the package's own (`sso_procedures`): no app keeps this list flat, so the
 * bound projection carries it into the app's structure (CodeListProjection).
 * Lists are JSON text, not JSON columns, so a stored list reads back exactly
 * as SSO sent it and an unchanged row compares equal. Nothing is ever
 * deleted from it; SSO turning a row off sets is_active false.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(ProcedureMirror::TABLE)) {
            return;
        }

        Schema::create(ProcedureMirror::TABLE, function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
            $blueprint->unsignedBigInteger(ProcedureMirror::SSO_ID_COLUMN)->nullable();
            $blueprint->string('code', 20)->nullable();
            $blueprint->string('code_system', 10)->nullable();
            $blueprint->string('code_type', 10)->nullable();
            $blueprint->string('name');
            $blueprint->string('display_name', 100)->nullable();
            $blueprint->string('label')->nullable();
            $blueprint->text('levels')->nullable();
            $blueprint->text('member_levels')->nullable();
            $blueprint->text('only_states')->nullable();
            $blueprint->string('source', 20)->nullable();
            $blueprint->boolean('is_active')->default(true);
            $blueprint->unsignedInteger('sort_order')->default(0);
            // SSO's own updated_at (UTC), so a delayed delivery cannot
            // overwrite a newer one.
            $blueprint->timestamp(ProcedureMirror::SSO_UPDATED_AT_COLUMN)->nullable();
            $blueprint->boolean(ProcedureMirror::LINK_PENDING_COLUMN)->default(false);
            $blueprint->timestamps();

            $blueprint->unique(['company_id', ProcedureMirror::SSO_ID_COLUMN], 'sso_procedures_company_sso_unique');
            $blueprint->index(['company_id', 'code'], 'sso_procedures_company_code_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(ProcedureMirror::TABLE);
    }
};
