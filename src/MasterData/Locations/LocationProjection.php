<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Locations;

/**
 * How one app stores SSO locations: which table and columns, and how SSO's
 * fields land on them. LocationMirror owns everything shared (SSO link,
 * stale guard, pending adoption, turn off never delete, division
 * translation, the push mapping); the projection owns only the app's shape.
 *
 * The package binds TextAddressLocationProjection (Crew-Scheduling's
 * `locations`: name, phone_number, one free-text address, division_id). An
 * app with another shape binds its own in AppServiceProvider::register():
 *
 *     $this->app->bind(LocationProjection::class, DemLocationProjection::class);
 *
 * CloudPCR's `dem_locations` is the case this exists for: NEMSIS columns
 * (dlocation_01 type, dlocation_02 name, dlocation_03 number, ...), `_nv`
 * columns, phones in a child table, soft deletes.
 *
 * Every method that touches the database gets the local company id the
 * mirror resolved from an authoritative SSO id and must filter by it
 * (DEV_GUIDELINES §4a). Projections never delete the location row.
 */
interface LocationProjection
{
    public function table(): string;

    /** The local column holding the location's name. */
    public function nameColumn(): string;

    /** The local column holding the location number, or null. Linking tries it before the name. */
    public function numberColumn(): ?string;

    /** The local column holding the local division id, or null when locations have no division here. */
    public function divisionColumn(): ?string;

    /**
     * Local columns for an SSO location record (the `location` object of
     * the webhook, or one row of the snapshot), other than the name, the
     * active flag, the division and the SSO link columns, which the mirror
     * writes itself. Return the same values for the same record, so an
     * unchanged record compares equal.
     *
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    public function toColumns(array $location): array;

    /**
     * Called after the mirror inserted or updated the row from SSO, and for
     * every row on a full resync, for anything that does not fit in the row
     * itself (phones in a child table). Must be idempotent: compare and
     * write only what differs. Not called for a pending (adopted,
     * unconfirmed) row.
     *
     * @param  array<string, mixed>  $location
     */
    public function written(int $localCompanyId, int $localId, array $location): void;

    /**
     * Local columns the push reads besides id, name, active flag and
     * division.
     *
     * @return list<string>
     */
    public function pushColumns(): array;

    /**
     * The import fields for one local row (SSO's POST .../locations/import):
     * any of `number`, `location_type` (NEMSIS dLocation.01 code), `address`
     * {street, street2, city_gnis, city_name, state (FIPS), zip, county
     * (FIPS), country}, `latitude`, `longitude`, `phones` [{number, type?}].
     * Leave out what the app does not have. SSO judges each field with its
     * Settings rules and reports what it refuses.
     *
     * @return array<string, mixed>
     */
    public function toImport(int $localCompanyId, object $row): array;
}
