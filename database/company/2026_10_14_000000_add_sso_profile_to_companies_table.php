<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The local copy of SSO's agency information on `companies`: `sso_profile`
 * (JSON, SSO's keys) and `sso_profile_updated_at` (SSO's own timestamp, so
 * a delayed `agency_profile.updated` cannot overwrite a newer one).
 * Published by `vendor:publish --tag=sso-company-profile`, never loaded
 * automatically: an app opts in by migrating. Each column is skipped when
 * present, and nothing else on the table changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'sso_profile' => fn (Blueprint $table) => $table->json('sso_profile')->nullable(),
            'sso_profile_updated_at' => fn (Blueprint $table) => $table->timestamp('sso_profile_updated_at')->nullable(),
        ];

        $missing = array_filter($columns, fn (string $column): bool => ! Schema::hasColumn('companies', $column), ARRAY_FILTER_USE_KEY);

        if ($missing !== []) {
            Schema::table('companies', function (Blueprint $table) use ($missing): void {
                foreach ($missing as $add) {
                    $add($table);
                }
            });
        }
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['sso_profile', 'sso_profile_updated_at'],
            fn (string $column): bool => Schema::hasColumn('companies', $column),
        ));

        if ($columns !== []) {
            Schema::table('companies', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
