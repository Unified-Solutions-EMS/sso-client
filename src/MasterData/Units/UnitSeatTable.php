<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Units;

/**
 * The crew seats of a unit (Driver, Attendant, Officer; SSO `unit_seats`)
 * in the app's own table. The mirror links each local seat to SSO's seat
 * id (`sso_unit_seat_id`, added by the units mirror migration) and updates
 * it in place; a seat is never deleted and re-created. A linked seat SSO
 * removed is unlinked and stamped `sso_removed_at` unless the definition
 * implements DeclaresChildRowPolicy, so the default never deletes.
 *
 * The qualification a seat needs is translated by the mirror from SSO's
 * id through this app's qualifications links into qualificationColumn()
 * (and back to `qualification_sso_id` on the push).
 *
 * Seat tables carry no company_id; the mirror pins the parent unit to the
 * company before touching a seat (§4a). Parent ids must be integers.
 */
interface UnitSeatTable
{
    public function table(): string;

    /** The column holding the parent unit's local id. */
    public function parentColumn(): string;

    /** The column holding the seat's position (1-12): an unlinked local seat is adopted by it. */
    public function positionColumn(): string;

    /** The column holding the local qualification id, or null. */
    public function qualificationColumn(): ?string;

    /**
     * Local columns for one SSO seat (`{id, uuid, position, label,
     * qualification_id, qualification_name, sort_order}`), other than the
     * parent, link and qualification columns.
     *
     * @param  array<string, mixed>  $seat
     * @return array<string, mixed>
     */
    public function toColumns(array $seat): array;

    /**
     * Local columns the push reads besides id, the parent and the qualification.
     *
     * @return list<string>
     */
    public function pushColumns(): array;

    /**
     * The import fields of one local seat: `position`, `label` (and
     * `uuid`). The mirror adds `local_id` and `qualification_sso_id`.
     *
     * @return array<string, mixed>
     */
    public function toImport(object $row): array;
}
