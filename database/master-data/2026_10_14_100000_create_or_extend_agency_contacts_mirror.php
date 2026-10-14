<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\Contacts\ContactMirror;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * Creates the package's `sso_agency_contacts` mirror of SSO's agency
 * contacts, or adds the link columns to the app's own contact table when it
 * bound a ContactProjection first. Published by
 * `vendor:publish --tag=sso-master-data-agency-contacts`.
 *
 * Only link and list columns are added to an existing table, each skipped
 * when present: `sso_agency_contact_id`, `is_active` (existing rows start
 * on), `sort_order`, `sso_updated_at`, `sso_link_pending`. The SSO link is
 * unique per company where the table has a company_id, otherwise on its own.
 * Nothing here deletes or rewrites a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mirror = $this->mirror();
        $table = $mirror->table();

        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $blueprint) use ($mirror): void {
                $blueprint->id();
                $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
                $blueprint->string($mirror->nameColumn());
                $blueprint->string('type', 40);
                $blueprint->boolean('is_primary')->default(false);
                $blueprint->string('last_name', 100);
                $blueprint->string('first_name', 100);
                $blueprint->string('middle_name', 100)->nullable();
                $blueprint->string('title')->nullable();
                $blueprint->text('phones')->nullable();
                $blueprint->text('emails')->nullable();
                $blueprint->string('address_street')->nullable();
                $blueprint->string('address_street2')->nullable();
                $blueprint->string('address_city_gnis', 20)->nullable();
                $blueprint->string('address_city_name', 100)->nullable();
                $blueprint->string('address_state', 2)->nullable();
                $blueprint->string('address_zip', 10)->nullable();
                $blueprint->string('address_county', 5)->nullable();
                $blueprint->string('address_country', 2)->nullable();
                $blueprint->string('website')->nullable();
                $blueprint->string('md_degree', 20)->nullable();
                $blueprint->text('md_board_certifications')->nullable();
                $blueprint->string('md_compensation', 20)->nullable();
                $blueprint->boolean('md_board_certified')->nullable();
                $blueprint->string('sso_user_id')->nullable();
                $blueprint->timestamps();
            });
        }

        $columns = [
            ContactMirror::SSO_ID_COLUMN => fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger(ContactMirror::SSO_ID_COLUMN)->nullable(),
            $mirror->activeColumn() => fn (Blueprint $blueprint) => $blueprint->boolean($mirror->activeColumn())->default(true),
            'sort_order' => fn (Blueprint $blueprint) => $blueprint->unsignedInteger('sort_order')->default(0),
            // SSO's own updated_at (UTC) for the row, so a delayed webhook
            // delivery cannot overwrite a newer one.
            ContactMirror::SSO_UPDATED_AT_COLUMN => fn (Blueprint $blueprint) => $blueprint->timestamp(ContactMirror::SSO_UPDATED_AT_COLUMN)->nullable(),
            // A row a webhook linked by type and name before this app pushed
            // its contacts to SSO; deliveries leave it alone until the push,
            // --link-by-name or a full resync confirms it.
            ContactMirror::LINK_PENDING_COLUMN => fn (Blueprint $blueprint) => $blueprint->boolean(ContactMirror::LINK_PENDING_COLUMN)->default(false),
        ];

        $missing = array_filter($columns, fn (string $column): bool => ! Schema::hasColumn($table, $column), ARRAY_FILTER_USE_KEY);

        if ($missing !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($missing): void {
                foreach ($missing as $add) {
                    $add($blueprint);
                }
            });
        }

        $index = $this->uniqueIndex($table);

        if (! Schema::hasIndex($table, $index)) {
            $unique = Schema::hasColumn($table, 'company_id') ? ['company_id', ContactMirror::SSO_ID_COLUMN] : [ContactMirror::SSO_ID_COLUMN];

            Schema::table($table, function (Blueprint $blueprint) use ($unique, $index): void {
                $blueprint->unique($unique, $index);
            });
        }
    }

    /**
     * Drops only the SSO link columns; the list columns and a table this
     * migration created stay.
     */
    public function down(): void
    {
        $table = $this->mirror()->table();
        $index = $this->uniqueIndex($table);

        if (Schema::hasIndex($table, $index)) {
            // MySQL may have adopted the unique index for the company_id
            // foreign key; give the key its own index before dropping it.
            $needsCompanyIndex = Schema::hasColumn($table, 'company_id') && ! collect(Schema::getIndexes($table))->contains(
                fn (array $existing): bool => $existing['name'] !== $index && ($existing['columns'][0] ?? null) === 'company_id',
            );

            Schema::table($table, function (Blueprint $blueprint) use ($needsCompanyIndex, $index, $table): void {
                if ($needsCompanyIndex) {
                    $blueprint->index('company_id', "{$table}_company_id_foreign");
                }

                $blueprint->dropUnique($index);
            });
        }

        $columns = array_values(array_filter(
            [ContactMirror::SSO_ID_COLUMN, ContactMirror::SSO_UPDATED_AT_COLUMN, ContactMirror::LINK_PENDING_COLUMN],
            fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($columns !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                $blueprint->dropColumn($columns);
            });
        }
    }

    private function mirror(): ContactMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror('agency_contacts');

        assert($mirror instanceof ContactMirror);

        return $mirror;
    }

    private function uniqueIndex(string $table): string
    {
        return "{$table}_sso_agency_contact_unique";
    }
};
