<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Contacts;

use Illuminate\Database\Query\Builder;

/**
 * How one app stores SSO's agency contacts (director, medical director,
 * billing contact, ...; NEMSIS dContact): which table and columns, how a row
 * belongs to a company, how SSO's fields land on it and how a local row goes
 * up on the push. ContactMirror owns everything shared (SSO link, stale
 * guard, pending adoption by type + last name + first name, turn off never
 * delete, the push mapping); the projection owns only the app's shape.
 *
 * The package binds StandardContactProjection (its own `sso_agency_contacts`
 * table, created by the mirror migration). An app that keeps contacts in a
 * table of its own binds a projection in AppServiceProvider::register(),
 * BEFORE running the mirror migration:
 *
 *     $this->app->bind(ContactProjection::class, DemContactProjection::class);
 *
 * CloudPCR's `dem_contacts` (dContact.01 type code, .02/.03/.04 names, ...)
 * is the case this exists for; that projection lives in CloudPCR.
 *
 * Every method that touches the database gets the local company id the
 * mirror resolved from an authoritative SSO id and must filter by it
 * (DEV_GUIDELINES §4a). Projections never delete a contact row.
 */
interface ContactProjection
{
    public function table(): string;

    /** The column readers sort and show by (the standard table's display `name`). */
    public function nameColumn(): string;

    /** The local columns adoption compares (the contact type and both names). */
    public function typeColumn(): string;

    public function lastNameColumn(): string;

    public function firstNameColumn(): string;

    /**
     * Narrow a query on table() to one company's rows. Every read and write
     * of the mirror goes through it.
     */
    public function scope(Builder $query, int $localCompanyId): Builder;

    /**
     * Columns a newly inserted row needs to belong to the company, other
     * than what toColumns() returns.
     *
     * @return array<string, mixed>
     */
    public function insertColumns(int $localCompanyId): array;

    /**
     * Local columns for an SSO contact (the `agency_contact` object of the
     * webhook, or one row of the snapshot; `name` is always set, from SSO or
     * built from the first and last name), other than the active flag, sort
     * order and the SSO link columns, which the mirror writes itself. It must
     * include typeColumn(), lastNameColumn() and firstNameColumn(), return
     * the same values for the same contact so an unchanged contact compares
     * equal, and change nothing in the database: adoption and
     * --link-by-name call it to compare.
     *
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>
     */
    public function toColumns(int $localCompanyId, array $contact): array;

    /**
     * Local columns the push reads besides id and the active flag.
     *
     * @return list<string>
     */
    public function pushColumns(): array;

    /**
     * The import fields for one local row (SSO's POST
     * .../agency_contacts/import), other than `local_id` and `is_active`:
     * `type` (platform code, ContactMirror::TYPES), `last_name`,
     * `first_name`, and any of `is_primary`, `middle_name`, `title`,
     * `phones` [{type, number}], `emails` [{type, address}], `address`
     * {street, street2, city_gnis, city_name, state, zip, county, country},
     * `website`, `md_degree`, `md_board_certifications`, `md_compensation`,
     * `md_board_certified`. Leave out what the app does not have.
     *
     * @return array<string, mixed>
     */
    public function toImport(int $localCompanyId, object $row): array;
}
