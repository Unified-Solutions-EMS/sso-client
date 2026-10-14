<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Personnel;

/**
 * How one app stores SSO personnel (NEMSIS dPersonnel): which table holds
 * one row per person per company, how SSO's snapshot lands on it, which
 * child tables carry the repeating groups, and what the push sends up.
 *
 * PersonnelMirror owns everything every app shares: the SSO link
 * (`sso_user_id`, one row per SSO user and company), the stale guard
 * (`sso_updated_at`), pending adoption (`sso_link_pending`), the on/off
 * column (turned off, never deleted: crew history points at these rows),
 * the local user link, the division translation, the in-place reconcile of
 * child rows by SSO's stable child ids, `pending` values this app sent that
 * SSO could not store, the push mapping and the resync loop. The projection
 * owns only the app's shape. It is the design's `PersonnelMirrorWriter`:
 * apply = toColumns() + childTables() + written(); deactivate = the mirror
 * turning activeColumn() off; localSnapshot = pushColumns() + toImport().
 *
 * The package binds StandardPersonnelProjection (a package-owned
 * `sso_personnel` read model for apps that only need to look people up).
 * CloudPCR (`dem_personnel` + licences, practice levels, phones, emails,
 * immunizations) and HR (`personnel_profiles` + phones, emails, licences,
 * immunizations) bind their own in AppServiceProvider::register(), BEFORE
 * running the mirror migration:
 *
 *     $this->app->bind(PersonnelProjection::class, CloudPcrPersonnelProjection::class);
 *
 * tests/Stubs/DemPersonnelProjection.php and tests/Stubs/HrPersonnelProjection.php
 * are the templates.
 *
 * The snapshot is SSO's `PersonnelSnapshot` (`person.updated`, the internal
 * GET/PATCH): `user {id, email, first_name, last_name, phone_number}`,
 * `profile {middle_name, date_of_birth, sex, race[], citizenship, address
 * {street, street2, city_gnis, city_name, state, zip, country},
 * highest_degree, degree_fields[], vehicle_license_types[], languages[],
 * national_registry {number, level, expires_on}|null}`, `phones[]`,
 * `emails[]`, `immunizations[]`, `licenses[]`, `membership {is_member,
 * is_active, is_ems_personnel, division_id, agency_personnel_id,
 * employment_status, employment_status_date, hire_date, primary_job_role,
 * other_job_roles[], total_service_years, total_service_documented_on,
 * practice_levels[], deactivated_at}`, `updated_at`. Codes only, blank is
 * null, never an NV code. Before calling the projection the mirror adds
 * `user.local_id` (this app's users.id, or null) and, when the divisions
 * mirror can translate it, `membership.local_division_id` (absent when it
 * cannot: leave the local value alone).
 *
 * Every method that touches the database gets the local company id the
 * mirror resolved from an authoritative SSO id and must filter by it
 * (DEV_GUIDELINES §4a). Projections never delete the person row.
 */
interface PersonnelProjection
{
    /** The table with one row per person per company; it has `id` and `company_id`. */
    public function table(): string;

    /**
     * The on/off column the mirror writes from `membership.is_member &&
     * membership.is_active`. The migration adds it (existing rows start on)
     * when the table has none.
     */
    public function activeColumn(): string;

    /**
     * The column holding this app's users.id, or null. The mirror fills it
     * from users.sso_id, adopts an unlinked row by it first, and, when the
     * column exists, waits for the user to be provisioned locally before
     * creating a row (acked `user_not_found`; the nightly resync heals).
     */
    public function userColumn(): ?string;

    /**
     * The column holding the person's email as this app matches people
     * (CloudPCR's dpersonnel_10), or null. Adoption tries it after the user
     * link, case-insensitively, exactly one unlinked row. Never by name.
     */
    public function emailColumn(): ?string;

    /**
     * Local columns for a snapshot, other than the SSO link columns, the
     * active column and the user column, which the mirror writes itself.
     * Leave out every column whose snapshot section is absent (an older SSO
     * that does not send it), so a resync never blanks it. Return the same
     * values for the same snapshot (JSON as encoded text, dates
     * 'Y-m-d'), so an unchanged person compares equal. Columns the app owns
     * alone (HR's rank, notes, emergency contact) must never appear here.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function toColumns(int $localCompanyId, array $snapshot): array;

    /**
     * Runs after the row and its child rows are written, for whatever lives
     * elsewhere (CloudPCR: the sign-in email and SMS number as primary
     * contact rows, the `_nv` "Not Recorded" columns). Must be idempotent:
     * it also runs for every person on a full resync.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function written(int $localCompanyId, int $rowId, array $snapshot): void;

    /**
     * Local columns the push reads besides id, the link columns, the active
     * column and the user column.
     *
     * @return list<string>
     */
    public function pushColumns(): array;

    /**
     * The import fields for one local row (SSO's POST .../personnel/import),
     * in the snapshot's own shape: any of `user {email, first_name,
     * last_name, phone_number}`, `profile {...}`, `membership {...}`. Leave
     * out what the app does not have; send raw values SSO may refuse (SSO
     * keeps a refused value as `pending` and reports it). The mirror adds
     * `local_id`, `user_sso_id`, `is_active` and the child lists.
     *
     * @return array<string, mixed>
     */
    public function toImport(int $localCompanyId, object $row): array;

    /**
     * The app's child tables, keyed by the SSO list they hold: `phones`,
     * `emails`, `immunizations`, `licenses`, `practice_levels`. Empty when
     * the app keeps none of them as rows.
     *
     * @return array<'phones'|'emails'|'immunizations'|'licenses'|'practice_levels', PersonnelChildTable>
     */
    public function childTables(): array;
}
