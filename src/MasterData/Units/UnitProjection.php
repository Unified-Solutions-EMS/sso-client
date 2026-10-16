<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Units;

use Unified\SsoClient\MasterData\Contracts\RecordProjection;

/**
 * How one app stores SSO's units (Settings > Agency lists > Units: a staffed
 * position such as Medic 1 or Engine 3, labelled "Unit/Resource" on
 * screen). UnitMirror owns the link (`sso_unit_id`), stale guard, pending
 * adoption, turn off never delete, the vehicle / location / division
 * translation, the seat rows and the push mapping; the projection owns the
 * app's shape.
 *
 * fromSso() reads one unit: `name` (the Unit/Resource, unique per company
 * ignoring case), `display_name`, `unit_type` (SSO's fixed list: ambulance,
 * engine, ladder, rescue, supervisor, wheelchair_van, command, support,
 * other) and `unit_type_name`, `level_of_care` (bls, als, als2, cct, sct,
 * pi), `capabilities` (CAD's equipment codes), `crew_size`, `seats`, and
 * SSO's own `vehicle_id` / `location_id` / `division_id` with the
 * denormalized `vehicle {id, unit_number, call_sign}`, `location {id, name}`
 * and `division {id, name}` blocks. It must not write the columns named by
 * vehicleColumn() / locationColumn() / divisionColumn(): the mirror
 * translates SSO's ids through this app's vehicles, locations and divisions
 * links into them. An app that keeps the usual vehicle another way (Fire
 * stores SSO's vehicle id itself, CAD a Fleet id) returns null there and
 * maps it in fromSso() or written().
 *
 * toSso() sends `name` and any of `display_name`, `unit_type`,
 * `level_of_care`, `capabilities`, `crew_size`, `uuid` (and
 * `vehicle_sso_id` / `location_sso_id` / `division_sso_id` for an app that
 * maps them itself) for `POST .../units/import`. The mirror adds the
 * references for the columns above as `*_sso_id` when this app's link is
 * confirmed, else `*_local_id` (SSO maps those through the links the
 * vehicles, locations and divisions pushes recorded), and the seats.
 *
 * The package binds StandardUnitProjection (`sso_units` + `sso_unit_seats`).
 * An app binds its own over its unit table (CAD `units`, Crew `resources`,
 * Fire `neris_station_units`) in AppServiceProvider::register(), BEFORE
 * running the units mirror migration. Local ids may be integers or uuids.
 */
interface UnitProjection extends RecordProjection
{
    /**
     * RecordProjection::written() with the unit's local id as the table
     * keeps it: an integer, or the uuid string (CAD).
     *
     * @param  array<string, mixed>  $record
     */
    public function written(int $localCompanyId, int|string $localId, array $record): void;

    /** The local column holding the usual vehicle's local id (translated through the vehicles mirror), or null. */
    public function vehicleColumn(): ?string;

    /** The local column holding the home location's local id (translated through the locations mirror), or null. */
    public function locationColumn(): ?string;

    /** The local column holding the division's local id (translated through the divisions mirror), or null. */
    public function divisionColumn(): ?string;

    /** Where the unit's crew seats live, or null when the app keeps none. */
    public function seatTable(): ?UnitSeatTable;

    /**
     * Whether this app can hold an SSO unit it has no row for yet (Fire
     * keeps units under a station, so a unit based where Fire has no
     * station is not created). A unit already linked is always updated.
     *
     * @param  array<string, mixed>  $record
     */
    public function accepts(int $localCompanyId, array $record): bool;

    /**
     * The local rows the push sends, from the company's rows in scope
     * (CAD leaves out the untouched trial sample). Rows read with id, the
     * active flag and pushColumns().
     *
     * @param  list<object>  $rows
     * @return list<object>
     */
    public function pushable(int $localCompanyId, array $rows): array;
}
