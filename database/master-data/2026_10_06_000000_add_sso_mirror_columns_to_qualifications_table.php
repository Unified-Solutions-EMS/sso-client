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
        $addSsoUpdatedAt = ! Schema::hasColumn('qualifications', 'sso_updated_at');
        $addLinkPending = ! Schema::hasColumn('qualifications', 'sso_link_pending');

        Schema::table('qualifications', function (Blueprint $table) use ($addSsoId, $addAppliesTo, $addIsActive, $addSsoUpdatedAt, $addLinkPending): void {
            if ($addSsoId) {
                $table->unsignedBigInteger('sso_qualification_id')->nullable()->after('company_id');
            }

            if ($addAppliesTo) {
                $table->json('applies_to')->nullable();
            }

            if ($addIsActive) {
                $table->boolean('is_active')->default(true);
            }

            // SSO's own updated_at (UTC) for the row, so a delayed webhook
            // delivery cannot overwrite a newer one.
            if ($addSsoUpdatedAt) {
                $table->timestamp('sso_updated_at')->nullable();
            }

            // A row a webhook or login linked by name before this app pushed
            // its data to SSO. Deliveries leave it and its assignments alone
            // until the push, --link-by-name or a full resync confirms it.
            if ($addLinkPending) {
                $table->boolean('sso_link_pending')->default(false);
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
            // MySQL adopts the unique index as the company_id foreign key's
            // index once it exists, and refuses to drop it while the foreign
            // key has no other index to use. Give it one first.
            $companyIdIndexed = collect(Schema::getIndexes('qualifications'))->contains(
                fn (array $index): bool => $index['name'] !== self::UNIQUE_INDEX && ($index['columns'][0] ?? null) === 'company_id',
            );

            Schema::table('qualifications', function (Blueprint $table) use ($companyIdIndexed): void {
                if (! $companyIdIndexed) {
                    $table->index('company_id', 'qualifications_company_id_foreign');
                }

                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }

        $columns = array_values(array_filter(
            ['sso_qualification_id', 'applies_to', 'is_active', 'sso_updated_at', 'sso_link_pending'],
            fn (string $column): bool => Schema::hasColumn('qualifications', $column),
        ));

        if ($columns !== []) {
            Schema::table('qualifications', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
