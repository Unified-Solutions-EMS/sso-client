<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Personnel\PersonnelMirror;
use Unified\SsoClient\MasterData\Personnel\StandardPersonnelProjection;

/**
 * Turns the app's personnel table into a mirror of SSO's personnel, or
 * creates the package's `sso_personnel` read model for an app that binds no
 * projection. Published by `vendor:publish --tag=sso-master-data-personnel`.
 *
 * The table and child tables come from the app's bound PersonnelProjection,
 * so the same file extends CloudPCR's `dem_personnel` (+ certifications,
 * cert levels, phones, emails, immunizations) and HR's `personnel_profiles`
 * (+ phones, emails, licences, immunizations): bind the projection BEFORE
 * migrating. Only link and on/off columns are added, each skipped when
 * present: `sso_user_id`, the active column (existing rows start on),
 * `sso_updated_at`, `sso_link_pending` on the person table and
 * `sso_license_id` / `sso_practice_level_id` / `sso_phone_id` /
 * `sso_email_id` / `sso_immunization_id` on the child tables. Nothing here
 * deletes or rewrites a row, and no column the app already has is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mirror = $this->mirror();
        $table = $mirror->table();

        if (! Schema::hasTable($table) && $table === StandardPersonnelProjection::TABLE) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
                $blueprint->unsignedBigInteger('user_id')->nullable()->index();
                $blueprint->string('first_name', 50)->nullable();
                $blueprint->string('last_name', 60)->nullable();
                $blueprint->string('middle_name', 50)->nullable();
                $blueprint->string('email')->nullable();
                $blueprint->string('phone_number', 30)->nullable();
                $blueprint->date('date_of_birth')->nullable();
                $blueprint->string('sex', 7)->nullable();
                $blueprint->boolean('is_ems_personnel')->default(true);
                $blueprint->string('agency_personnel_id', 50)->nullable();
                $blueprint->string('employment_status', 7)->nullable();
                $blueprint->date('hire_date')->nullable();
                $blueprint->string('primary_job_role', 7)->nullable();
                $blueprint->unsignedBigInteger('division_id')->nullable();
                $blueprint->text('licenses')->nullable();
                $blueprint->text('practice_levels')->nullable();
                $blueprint->timestamps();
            });
        }

        $this->addMissing($table, [
            PersonnelMirror::SSO_ID_COLUMN => fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger(PersonnelMirror::SSO_ID_COLUMN)->nullable()->after('company_id'),
            $mirror->activeColumn() => fn (Blueprint $blueprint) => $blueprint->boolean($mirror->activeColumn())->default(true),
            // SSO's own updated_at (UTC) for the person, so a delayed
            // delivery cannot overwrite a newer one.
            PersonnelMirror::SSO_UPDATED_AT_COLUMN => fn (Blueprint $blueprint) => $blueprint->timestamp(PersonnelMirror::SSO_UPDATED_AT_COLUMN)->nullable(),
            // A row a delivery linked by user or email before this app pushed
            // its people to SSO; deliveries leave it alone until the push,
            // --link-by-name or a full resync confirms it.
            PersonnelMirror::LINK_PENDING_COLUMN => fn (Blueprint $blueprint) => $blueprint->boolean(PersonnelMirror::LINK_PENDING_COLUMN)->default(false),
        ]);
        $this->addUnique($table, ['company_id', PersonnelMirror::SSO_ID_COLUMN], "{$table}_company_sso_user_unique");

        foreach ($mirror->childTables() as $kind => $child) {
            $column = PersonnelMirror::CHILD_SSO_ID_COLUMNS[$kind];

            $this->addMissing($child->table(), [
                $column => fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger($column)->nullable(),
            ]);
            $this->addUnique($child->table(), [$child->parentColumn(), $column], "{$child->table()}_parent_{$column}_unique");
        }
    }

    /**
     * Drops only the SSO link columns. The active column and a table this
     * migration created stay: the app reads them and crew rows point at them.
     */
    public function down(): void
    {
        $mirror = $this->mirror();
        $table = $mirror->table();

        foreach ($mirror->childTables() as $kind => $child) {
            $column = PersonnelMirror::CHILD_SSO_ID_COLUMNS[$kind];
            $this->dropUnique($child->table(), "{$child->table()}_parent_{$column}_unique", $child->parentColumn());
            $this->dropColumns($child->table(), [$column]);
        }

        $this->dropUnique($table, "{$table}_company_sso_user_unique", 'company_id');
        $this->dropColumns($table, [PersonnelMirror::SSO_ID_COLUMN, PersonnelMirror::SSO_UPDATED_AT_COLUMN, PersonnelMirror::LINK_PENDING_COLUMN]);
    }

    /**
     * @param  array<string, Closure(Blueprint): mixed>  $columns
     */
    private function addMissing(string $table, array $columns): void
    {
        $missing = array_filter($columns, fn (string $column): bool => ! Schema::hasColumn($table, $column), ARRAY_FILTER_USE_KEY);

        if ($missing !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($missing): void {
                foreach ($missing as $add) {
                    $add($blueprint);
                }
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addUnique(string $table, array $columns, string $index): void
    {
        if (! Schema::hasIndex($table, $index)) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $index): void {
                $blueprint->unique($columns, $index);
            });
        }
    }

    private function dropUnique(string $table, string $index, string $leadingColumn): void
    {
        if (! Schema::hasIndex($table, $index)) {
            return;
        }

        // MySQL may have adopted the unique index for the leading column's
        // foreign key; give the key its own index before dropping it.
        $leadingIndexed = collect(Schema::getIndexes($table))->contains(
            fn (array $existing): bool => $existing['name'] !== $index && ($existing['columns'][0] ?? null) === $leadingColumn,
        );

        Schema::table($table, function (Blueprint $blueprint) use ($leadingIndexed, $leadingColumn, $index, $table): void {
            if (! $leadingIndexed) {
                $blueprint->index($leadingColumn, "{$table}_{$leadingColumn}_foreign");
            }

            $blueprint->dropUnique($index);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropColumns(string $table, array $columns): void
    {
        $present = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column)));

        if ($present !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($present): void {
                $blueprint->dropColumn($present);
            });
        }
    }

    private function mirror(): PersonnelMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror('personnel');

        assert($mirror instanceof PersonnelMirror);

        return $mirror;
    }
};
