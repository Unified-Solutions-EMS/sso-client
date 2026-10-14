<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Vehicles;

/**
 * One child table of a vehicle (crew certification levels or yearly usage)
 * in the app's own shape. The mirror links each local child row to SSO's
 * child id (`sso_crew_level_id` / `sso_yearly_usage_id`, added by the mirror
 * migration) and updates it in place: a child row is never deleted and
 * re-created, because CloudPCR derives NEMSIS group UUIDs from the local
 * child row id. A linked row whose child SSO removed is unlinked,
 * deactivated or deleted as the definition's ChildRowPolicy says (implement
 * DeclaresChildRowPolicy; unlink when it declares nothing). A table that
 * keeps the app's own data on its rows must not choose delete.
 *
 * Child tables carry no company_id; the mirror pins the parent vehicle to
 * the company before touching any child row (§4a).
 */
interface VehicleChildTable
{
    public function table(): string;

    /** The column holding the parent vehicle's local id. */
    public function parentColumn(): string;

    /**
     * The local column holding the child's level (crew levels) or year
     * (yearly usage). An unlinked local child with the same value as an SSO
     * child is adopted in place when exactly one has it.
     */
    public function keyColumn(): string;

    /**
     * Local columns for one SSO child, other than the parent and link
     * columns. Same values for the same child, so an unchanged child
     * compares equal.
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
     * The import fields for one local child row, other than `local_id`:
     * crew levels `level`, `on_911_ambulance`, `on_911_non_transport`,
     * `on_non_911_transport` (and `uuid`); yearly usage `year`, `hours`,
     * `distance`, `distance_unit` (and `uuid`).
     *
     * @return array<string, mixed>
     */
    public function toImport(object $row): array;
}
