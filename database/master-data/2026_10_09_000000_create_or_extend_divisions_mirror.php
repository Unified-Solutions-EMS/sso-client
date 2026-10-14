<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the app's `divisions` table into a mirror of SSO's divisions, or
 * creates one for an app that has none, and gives the company membership
 * pivot a `division_id` (one division per person per company). Published by
 * `vendor:publish --tag=sso-master-data-divisions`.
 *
 * Crew-Scheduling already has divisions(company_id, name) referenced by
 * locations, resources and shift templates, and company_user.division_id:
 * only the link and list columns are added there, each skipped when present.
 * HR has neither, so both are created. Nothing here deletes or rewrites a
 * row; existing divisions start active.
 */
return new class extends Migration
{
    private const UNIQUE_INDEX = 'divisions_company_sso_division_unique';

    public function up(): void
    {
        if (! Schema::hasTable('divisions')) {
            Schema::create('divisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->timestamps();
            });
        }

        $columns = [
            'sso_division_id' => fn (Blueprint $table) => $table->unsignedBigInteger('sso_division_id')->nullable()->after('company_id'),
            'code' => fn (Blueprint $table) => $table->string('code', 20)->nullable(),
            'is_active' => fn (Blueprint $table) => $table->boolean('is_active')->default(true),
            'sort_order' => fn (Blueprint $table) => $table->unsignedInteger('sort_order')->default(0),
            // SSO's own updated_at (UTC) for the row, so a delayed webhook
            // delivery cannot overwrite a newer one.
            'sso_updated_at' => fn (Blueprint $table) => $table->timestamp('sso_updated_at')->nullable(),
            // A row a webhook or login linked by name before this app pushed
            // its data to SSO. Deliveries leave it and its people alone until
            // the push, --link-by-name or a full resync confirms it.
            'sso_link_pending' => fn (Blueprint $table) => $table->boolean('sso_link_pending')->default(false),
        ];

        $missing = array_filter($columns, fn (string $column): bool => ! Schema::hasColumn('divisions', $column), ARRAY_FILTER_USE_KEY);

        if ($missing !== []) {
            Schema::table('divisions', function (Blueprint $table) use ($missing): void {
                foreach ($missing as $add) {
                    $add($table);
                }
            });
        }

        if (! Schema::hasIndex('divisions', self::UNIQUE_INDEX)) {
            Schema::table('divisions', function (Blueprint $table): void {
                $table->unique(['company_id', 'sso_division_id'], self::UNIQUE_INDEX);
            });
        }

        if (! Schema::hasColumn('company_user', 'division_id')) {
            Schema::table('company_user', function (Blueprint $table): void {
                $table->foreignId('division_id')->nullable()->constrained('divisions')->nullOnDelete();
            });
        }
    }

    /**
     * Drops only the SSO link columns. The list columns (code, is_active,
     * sort_order), a divisions table and a pivot column this migration created
     * stay: the app reads them and people's divisions live in them.
     */
    public function down(): void
    {
        if (Schema::hasIndex('divisions', self::UNIQUE_INDEX)) {
            // MySQL adopts the unique index as the company_id foreign key's
            // index once it exists, and refuses to drop it while the foreign
            // key has no other index to use. Give it one first.
            $companyIdIndexed = collect(Schema::getIndexes('divisions'))->contains(
                fn (array $index): bool => $index['name'] !== self::UNIQUE_INDEX && ($index['columns'][0] ?? null) === 'company_id',
            );

            Schema::table('divisions', function (Blueprint $table) use ($companyIdIndexed): void {
                if (! $companyIdIndexed) {
                    $table->index('company_id', 'divisions_company_id_foreign');
                }

                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }

        $columns = array_values(array_filter(
            ['sso_division_id', 'sso_updated_at', 'sso_link_pending'],
            fn (string $column): bool => Schema::hasColumn('divisions', $column),
        ));

        if ($columns !== []) {
            Schema::table('divisions', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
