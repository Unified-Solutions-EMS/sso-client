<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Facilities;

use Illuminate\Database\Query\Builder;

/**
 * How one app stores SSO facilities (destinations: hospitals and other
 * receiving facilities, NEMSIS dFacility): which table and columns, how a
 * row belongs to a company, how SSO's fields land on it, and what lives
 * outside the row (phones, and designations or NPIs when the app keeps them
 * as child rows). FacilityMirror owns everything shared (SSO link, stale
 * guard, pending adoption by code then name + ZIP, turn off never delete,
 * the push mapping); the projection owns only the app's shape.
 *
 * The package binds StandardFacilityProjection (a `facilities` table with
 * company_id and the contract's own column names). An app with another
 * shape binds its own in AppServiceProvider::register(), BEFORE running the
 * mirror migration:
 *
 *     $this->app->bind(FacilityProjection::class, DemFacilityProjection::class);
 *
 * CloudPCR is the case this exists for: one `dem_facility_entries` row per
 * facility (dfacility_02 name, 03 code, 04 designations JSON, 05 NPIs JSON,
 * 06..14 address and GPS) under a per-company type group in
 * `dem_facilities` (company_id, dfacility_01 type), phones in
 * `dem_facility_entry_phones`. The entry has no company_id: scope() reaches
 * the company through its group, and toColumns() names the group for the
 * SSO type (find or create), so a type change moves the entry to another
 * group while its id stays. tests/Stubs/DemFacilityProjection.php is the
 * template.
 *
 * Every method that touches the database gets the local company id the
 * mirror resolved from an authoritative SSO id and must filter by it
 * (DEV_GUIDELINES §4a). Projections never delete the facility row; entry
 * ids are held by CAD incidents, Billing claims and mobile bundles.
 */
interface FacilityProjection
{
    public function table(): string;

    /** The local column holding the facility name (SSO `name`, dFacility.02). */
    public function nameColumn(): string;

    /** The local facility code column (SSO `location_code`, dFacility.03), or null. Adoption tries it first. */
    public function codeColumn(): ?string;

    /** The local ZIP column, or null. Adoption by name needs the same ZIP. */
    public function zipColumn(): ?string;

    /**
     * Narrow a query on table() to one company's rows. Every read and write
     * of the mirror goes through it.
     */
    public function scope(Builder $query, int $localCompanyId): Builder;

    /**
     * Columns a newly inserted row needs to belong to the company, other
     * than what toColumns() returns (`company_id` for a table that has one;
     * nothing for CloudPCR, whose toColumns() names the type group).
     *
     * @return array<string, mixed>
     */
    public function insertColumns(int $localCompanyId): array;

    /**
     * Local columns for an SSO facility (the `facility` object of the
     * webhook, or one row of the snapshot), other than the name, the active
     * flag, sort order and the SSO link columns, which the mirror writes
     * itself. Same values for the same facility, so an unchanged facility
     * compares equal. May create the company's type group (idempotently).
     *
     * @param  array<string, mixed>  $facility
     * @return array<string, mixed>
     */
    public function toColumns(int $localCompanyId, array $facility): array;

    /**
     * Called after the mirror inserted or updated the row from SSO, and for
     * every row on a full resync, for whatever does not fit in the row
     * (phones; designations or NPIs kept as child rows). Must be idempotent:
     * compare and write only what differs (Support\ChildRows helps). Not
     * called for a pending (adopted, unconfirmed) row.
     *
     * @param  array<string, mixed>  $facility
     */
    public function written(int $localCompanyId, int $localId, array $facility): void;

    /**
     * Local columns the push reads besides id, name and active flag.
     *
     * @return list<string>
     */
    public function pushColumns(): array;

    /**
     * The import fields for one local row (SSO's POST .../facilities/import),
     * other than `local_id`, `name` and `is_active`: any of `type` (NEMSIS
     * dFacility.01 code), `location_code`, `designations` (list of codes),
     * `npis` (list), `fax`, `address` {suite, street, street2, city_gnis,
     * city_name, state (FIPS), zip, county (FIPS), country}, `latitude`,
     * `longitude`, `phones` [{number, type?}]. Leave out what the app does
     * not have. SSO judges each field with its Settings rules and reports
     * what it refuses.
     *
     * @return array<string, mixed>
     */
    public function toImport(int $localCompanyId, object $row): array;
}
