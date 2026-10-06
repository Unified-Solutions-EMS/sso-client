<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the app's existing `qualifications` table into a mirror of the SSO
 * qualifications catalog. Published by `vendor:publish --tag=sso-master-data`.
 *
 * Every app already carries qualifications(company_id, name, description) from
 * its 2024_08_29_162700 migration, so this only adds the link and catalog
 * columns. Each column is skipped when present so the migration is safe on an
 * app that adopted one of them by hand.
 */
return new class extends Migration
{
    private const UNIQUE_INDEX = 'qualifications_company_sso_qualification_unique';

    public function up(): void
    {
        $addSsoId = ! Schema::hasColumn('qualifications', 'sso_qualification_id');
        $addAppliesTo = ! Schema::hasColumn('qualifications', 'applies_to');
        $addIsActive = ! Schema::hasColumn('qualifications', 'is_active');

        Schema::table('qualifications', function (Blueprint $table) use ($addSsoId, $addAppliesTo, $addIsActive): void {
            if ($addSsoId) {
                $table->unsignedBigInteger('sso_qualification_id')->nullable()->after('company_id');
            }

            if ($addAppliesTo) {
                $table->json('applies_to')->nullable();
            }

            if ($addIsActive) {
                $table->boolean('is_active')->default(true);
            }
        });

        if (! Schema::hasIndex('qualifications', self::UNIQUE_INDEX)) {
            Schema::table('qualifications', function (Blueprint $table): void {
                $table->unique(['company_id', 'sso_qualification_id'], self::UNIQUE_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('qualifications', self::UNIQUE_INDEX)) {
            Schema::table('qualifications', function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }

        $columns = array_values(array_filter(
            ['sso_qualification_id', 'applies_to', 'is_active'],
            fn (string $column): bool => Schema::hasColumn('qualifications', $column),
        ));

        if ($columns !== []) {
            Schema::table('qualifications', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
