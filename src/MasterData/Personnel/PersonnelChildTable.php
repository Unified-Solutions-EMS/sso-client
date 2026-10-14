<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Personnel;

/**
 * One child table of a person (licences, practice levels, phones, emails,
 * immunizations) in the app's own shape. The mirror links each local child
 * row to SSO's child id (`sso_license_id`, `sso_practice_level_id`,
 * `sso_phone_id`, `sso_email_id`, `sso_immunization_id`, added by the
 * mirror migration) and updates it in place: never deleted and re-created,
 * because CloudPCR derives NEMSIS group ids from the local child row id. A
 * linked row is deleted only when SSO no longer lists its child id. A local
 * child SSO never knew is adopted when exactly one unlinked row has the same
 * key, otherwise kept.
 *
 * Child tables carry no company_id; the mirror pins the parent person row
 * to the company before touching any child row (§4a).
 */
interface PersonnelChildTable
{
    public function table(): string;

    /** The column holding the parent person row's id. */
    public function parentColumn(): string;

    /**
     * The local columns that identify a child within one person: licence
     * state, practice level, immunization type + year, phone number, email
     * address. Compared trimmed, case-insensitive, without spaces, hyphens,
     * dots or parentheses, as toColumns() writes them.
     *
     * @return list<string>
     */
    public function keyColumns(): array;

    /**
     * Local columns for one SSO child, other than the parent and link
     * columns (and `sort_order`, which the mirror sets from SSO's order when
     * the table has it). Same values for the same child.
     *
     * @param  array<string, mixed>  $child
     * @return array<string, mixed>
     */
    public function toColumns(array $child): array;

    /**
     * Local columns the push reads besides id.
     *
     * @return list<string>
     */
    public function pushColumns(): array;

    /**
     * The import fields for one local child row in SSO's shape, other than
     * `local_id`: licences `state, number, level, current_since,
     * first_issued_on, expires_on`; practice levels `level, since`; phones
     * `number, type, is_primary`; emails `address, type, is_primary`;
     * immunizations `type, year`. Return [] to leave the row out of the
     * push (HR's driver's licences share its licence table but are not EMS
     * licences); such a row stays local and unlinked, and is never deleted.
     *
     * @return array<string, mixed>
     */
    public function toImport(object $row): array;
}
