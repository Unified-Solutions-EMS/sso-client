<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Contracts;

use Illuminate\Database\Query\Builder;
use Unified\SsoClient\MasterData\ProjectedMirror;

/**
 * How one app stores the rows of a projected master-data entity (agency,
 * contacts, devices, state configurations): which table, how a row belongs
 * to a company, how SSO's fields land on it, what lives outside it, and how
 * a local row goes back up for the push. The entity's mirror
 * ({@see ProjectedMirror}) owns everything shared: the SSO link column, the
 * `sso_updated_at` stale guard, pending adoption, this app's kept
 * (`pending`) values, turn off never delete, and the push mapping.
 *
 * Each entity has its own interface extending this one (AgencyProjection,
 * ContactProjection, DeviceProjection, StateConfigurationProjection) so an
 * app binds one per entity in AppServiceProvider::register(), BEFORE running
 * the entity's mirror migration. The package binds a Standard* projection
 * on a package-owned `sso_*` read model.
 *
 * Every method that touches the database gets the local company id the
 * mirror resolved from an authoritative SSO id and must filter by it
 * (DEV_GUIDELINES §4a). Projections never delete the row.
 */
interface RecordProjection
{
    public function table(): string;

    /**
     * The local column the push and `--link-by-name` show as the row's name
     * in their reports. Not written by the mirror: fromSso() decides.
     */
    public function nameColumn(): string;

    /**
     * The local columns an unlinked row is adopted by. They are compared,
     * trimmed and ignoring case, with fromSso()'s values for the same
     * columns; exactly one candidate adopts. An empty list never adopts.
     *
     * @return list<string>
     */
    public function matchColumns(): array;

    /**
     * Narrow a query on table() to one company's rows. Every read and write
     * of the mirror goes through it.
     */
    public function scope(Builder $query, int $localCompanyId): Builder;

    /**
     * Columns a newly inserted row needs to belong to the company, other
     * than what fromSso() returns (`company_id` for a table that has one).
     *
     * @return array<string, mixed>
     */
    public function insertColumns(int $localCompanyId): array;

    /**
     * Local columns for one SSO row (the webhook's object, one row of the
     * snapshot or the batch), other than the active flag, sort order and the
     * SSO link columns, which the mirror writes itself. Same values for the
     * same row, so an unchanged row compares equal, and no side effects:
     * adoption calls it to read the match columns.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function fromSso(int $localCompanyId, array $record): array;

    /**
     * Called after the mirror inserted or updated the row from SSO, and for
     * every row on a full resync, for whatever does not fit in the row
     * (CloudPCR's service areas, yearly statistics, custom values). Must be
     * idempotent: compare and write only what differs (Support\ChildRows
     * helps). Not called for a pending (adopted, unconfirmed) row.
     *
     * @param  array<string, mixed>  $record
     */
    public function written(int $localCompanyId, int $localId, array $record): void;

    /**
     * Local columns the push reads besides id and the active flag.
     *
     * @return list<string>
     */
    public function pushColumns(): array;

    /**
     * The import fields of one local row for SSO's POST .../{entity}/import,
     * other than `local_id` and `is_active` (and a device's vehicle, which
     * the mirror adds). Leave out what the app does not have; SSO judges each
     * field with its Settings rules and reports what it refuses.
     *
     * @return array<string, mixed>
     */
    public function toSso(int $localCompanyId, object $row): array;
}
