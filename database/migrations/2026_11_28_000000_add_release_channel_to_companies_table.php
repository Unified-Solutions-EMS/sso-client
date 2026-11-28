<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which release (stable or beta) each agency runs in this app. Set by SSO
 * through the sign-in sync and `company.updated`. Purely additive with a
 * default, so an older release still running against the same database
 * keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies') || Schema::hasColumn('companies', 'release_channel')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->string('release_channel', 16)->default('stable');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasColumn('companies', 'release_channel')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('release_channel');
        });
    }
};
