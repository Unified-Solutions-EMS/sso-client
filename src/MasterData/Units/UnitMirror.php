<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Units;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Unified\SsoClient\MasterData\CatalogMirror;
use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\ProjectedMirror;
use Unified\SsoClient\MasterData\Support\ChildRowPolicy;
use Unified\SsoClient\MasterData\Support\LinkedChildRows;
use Unified\SsoClient\MasterData\Support\MappingLinker;

/**
 * Mirrors SSO's units (Settings > Agency lists > Units: Medic 1, Engine 3,
 * labelled "Unit/Resource" on screen) into the app's unit table through the
 * bound UnitProjection. ProjectedMirror has the shared rules: link by
 * `sso_unit_id`, adopt an unlinked local unit by the projection's match
 * columns (the Unit/Resource name, ignoring case), stale guard, pending
 * adoption, turn off never delete, `unit.created|updated|deactivated|
 * batch_updated` and the push. This class adds:
 *
 * - the usual vehicle, home location and division: SSO sends its own ids,
 *   translated through this app's vehicles, locations and divisions links.
 *   SSO saying "none" clears the local value; an id this app has not linked
 *   yet leaves it as it is (a new row gets none) until that mirror catches
 *   up. The push sends `*_sso_id` for a confirmed link, else `*_local_id`
 *   (SSO maps it through the links its vehicles / locations / divisions
 *   import recorded), so push those lists before units;
 * - the crew seats, when the projection keeps them (UnitSeatTable): linked
 *   by SSO's seat id (`sso_unit_seat_id`) and updated in place, adopted by
 *   position, never deleted unless the seat table declares it; a seat's
 *   qualification is translated through the qualifications links. The push
 *   sends each seat with its local id, and SSO's `seats` mapping links them;
 * - units this app cannot hold: a unit with no local row, no adoptable row
 *   and that the projection does not accept() is skipped (`skipped`);
 * - local ids that are uuids (CAD's units): linking, the push and the
 *   mapping carry them as strings.
 */
class UnitMirror extends ProjectedMirror
{
    public const SSO_ID_COLUMN = 'sso_unit_id';

    public const SEAT_SSO_ID_COLUMN = 'sso_unit_seat_id';

    /** SSO's Unit/Resource limit; longer local names are cut on push. */
    public const MAX_UNIT_NAME_LENGTH = 100;

    /**
     * The contract field, the import key and this app's mirror for each reference.
     */
    private const REFERENCES = [
        'vehicle' => 'vehicles',
        'location' => 'locations',
        'division' => 'divisions',
    ];

    /**
     * Local id keyed by SSO id, per entity and local company, for this
     * mirror instance (one webhook or one command run).
     *
     * @var array<string, array<int, array<int, int|string>>>
     */
    private array $links = [];

    private int $unlinkedSeats = 0;

    private ?LinkedChildRows $childRows = null;

    public function __construct(
        LocalTenantResolver $tenants,
        MasterDataClient $client,
        UnitProjection $projection,
        protected readonly MasterDataRegistry $registry,
    ) {
        parent::__construct($tenants, $client, $projection);
    }

    public function entity(): string
    {
        return 'units';
    }

    public static function events(): array
    {
        return [
            'unit.created',
            'unit.updated',
            'unit.deactivated',
            'unit.batch_updated',
        ];
    }

    public function ssoIdColumn(): string
    {
        return self::SSO_ID_COLUMN;
    }

    public function maxNameLength(): int
    {
        return self::MAX_UNIT_NAME_LENGTH;
    }

    protected function recordKey(): string
    {
        return 'unit';
    }

    protected function recordName(array $record): string
    {
        return trim((string) ($record['name'] ?? ''));
    }

    public function units(): UnitProjection
    {
        $projection = $this->projection;

        assert($projection instanceof UnitProjection);

        return $projection;
    }

    public function isInstalled(): bool
    {
        if (! parent::isInstalled()) {
            return false;
        }

        $seats = $this->units()->seatTable();

        return $seats === null || (Schema::hasTable($seats->table()) && Schema::hasColumn($seats->table(), self::SEAT_SSO_ID_COLUMN));
    }

    /**
     * Writes the unit (ProjectedMirror), then reconciles its seats once the
     * row is SSO's: created, updated or unchanged (a seat edit need not
     * change the row), and on the authoritative resync path also linked. A
     * pending adoption and a stale delivery leave the seats alone. A unit
     * whose only change was a seat reports `updated`.
     *
     * @return 'created'|'updated'|'linked'|'unchanged'|'stale'|'pending'|'skipped'
     */
    public function upsert(int $localCompanyId, array $record, bool $delivery = false): string
    {
        $ssoId = (int) ($record['id'] ?? 0);

        if ($ssoId > 0
            && $this->findLinked($localCompanyId, $ssoId) === null
            && $this->findAdoptableFor($localCompanyId, $record) === null
            && ! $this->units()->accepts($localCompanyId, $this->forProjection($record))) {
            return 'skipped';
        }

        $result = parent::upsert($localCompanyId, $record, $delivery);

        if (! in_array($result, ['created', 'updated', 'unchanged'], true) && ($delivery || $result !== 'linked')) {
            return $result;
        }

        $row = $this->findLinked($localCompanyId, $ssoId);

        if ($row === null || ! $this->reconcileSeats($localCompanyId, $row->id, $record)) {
            return $result;
        }

        return $result === 'unchanged' ? 'updated' : $result;
    }

    /**
     * @return array<string, int>
     */
    public function resync(int $localCompanyId, array $snapshot): array
    {
        $this->unlinkedSeats = 0;
        $counts = parent::resync($localCompanyId, $snapshot);

        return $this->units()->seatTable() === null ? $counts : $counts + ['unlinked_seats' => $this->unlinkedSeats];
    }

    /**
     * The company's local units in the shape of SSO's POST .../units/import:
     * the rows the projection says are pushable, each with its vehicle,
     * location and division references and its seats. A company with none
     * to send sends nothing.
     */
    public function buildImportPayload(int $localCompanyId): array
    {
        $rows = $this->units()->pushable($localCompanyId, $this->scoped($localCompanyId)->orderBy('id')->get($this->pushSelect())->all());
        $seats = $this->seatsForPush($localCompanyId, array_map(fn (object $row): int|string => $row->id, $rows));
        $truncated = [];
        $units = [];

        foreach ($rows as $row) {
            $unit = $this->importRow($localCompanyId, $row);
            $name = trim((string) ($unit['name'] ?? ''));

            if (mb_strlen($name) > self::MAX_UNIT_NAME_LENGTH) {
                $truncated[] = ['local_id' => $unit['local_id'], 'name' => $name];
                $unit['name'] = mb_substr($name, 0, self::MAX_UNIT_NAME_LENGTH);
            }

            $units[] = $unit + (isset($seats[(string) $row->id]) ? ['seats' => $seats[(string) $row->id]] : []);
        }

        return [
            'payload' => ['app_slug' => (string) config('sso.app_slug')] + ($units === [] ? [] : ['units' => $units]),
            'skipped_users' => 0,
            'truncated_names' => $truncated,
        ];
    }

    /**
     * Links the units (CatalogMirror), then each linked unit's seats to the
     * SSO seat ids in the mapping's `seats` map ({seat_local_id:
     * sso_seat_id}). A seat already linked to another SSO seat, or an SSO
     * seat another seat of the same unit holds, is reported and left alone.
     */
    public function applyImportMapping(int $localCompanyId, array $mapping): array
    {
        $result = parent::applyImportMapping($localCompanyId, $mapping);
        $seats = $this->units()->seatTable();

        if ($seats === null) {
            return $result;
        }

        $refused = array_flip(array_map(strval(...), array_column($result['collisions'], 'local_id')));

        foreach ($mapping as $localId => $entry) {
            if (! is_array($entry) || ! is_array($entry['seats'] ?? null) || isset($refused[(string) $localId])) {
                continue;
            }

            $unit = $this->scoped($localCompanyId)->where('id', $localId)->first(['id']);

            if ($unit !== null) {
                array_push($result['collisions'], ...$this->applySeatMapping($seats, self::parentId($unit->id), $entry['seats']));
            }
        }

        return $result;
    }

    /**
     * The unit's local id as it is (a uuid stays a string).
     */
    protected function written(int $localCompanyId, object $row, array $record): void
    {
        $this->units()->written($localCompanyId, self::localKey($row->id) ?? 0, $record);
    }

    protected function referenceColumns(int $localCompanyId, array $record): array
    {
        $columns = [];

        foreach ($this->referenceColumnsByKey() as $key => $column) {
            $field = "{$key}_id";

            if (! array_key_exists($field, $record)) {
                continue;
            }

            if ($record[$field] === null) {
                $columns[$column] = null;

                continue;
            }

            $local = $this->linkMap(self::REFERENCES[$key], $localCompanyId)[(int) $record[$field]] ?? null;

            if ($local !== null) {
                $columns[$column] = $local;
            }
        }

        return $columns;
    }

    protected function pushSelect(): array
    {
        $select = parent::pushSelect();

        foreach ($this->referenceColumnsByKey() as $key => $column) {
            $select[] = "{$column} as mirror_{$key}_id";
        }

        return $select;
    }

    protected function importRow(int $localCompanyId, object $row): array
    {
        $unit = parent::importRow($localCompanyId, $row);

        foreach (array_keys($this->referenceColumnsByKey()) as $key) {
            $localId = $row->{"mirror_{$key}_id"} ?? null;

            if ($localId === null || $localId === '') {
                continue;
            }

            $ssoId = array_search((string) $localId, array_map(strval(...), $this->linkMap(self::REFERENCES[$key], $localCompanyId, confirmedOnly: true)), true);
            $unit += $ssoId === false ? ["{$key}_local_id" => self::localKey($localId)] : ["{$key}_sso_id" => (int) $ssoId];
        }

        return $unit;
    }

    /**
     * The projection's reference columns that exist, by contract key.
     *
     * @return array<string, string>
     */
    private function referenceColumnsByKey(): array
    {
        return array_filter([
            'vehicle' => $this->units()->vehicleColumn(),
            'location' => $this->units()->locationColumn(),
            'division' => $this->units()->divisionColumn(),
        ], fn (?string $column): bool => $column !== null);
    }

    /**
     * Updates the unit's seats from SSO's list in place (see the class doc).
     * A row whose `seats` SSO left out is not touched.
     *
     * @param  array<string, mixed>  $record
     * @return bool whether any seat changed
     */
    private function reconcileSeats(int $localCompanyId, int|string $unitId, array $record): bool
    {
        $seats = $this->units()->seatTable();

        if ($seats === null || ! is_array($record['seats'] ?? null)) {
            return false;
        }

        $qualifications = $this->linkMap('qualifications', $localCompanyId);
        $qualificationColumn = $seats->qualificationColumn();
        $wanted = [];

        foreach ($record['seats'] as $seat) {
            $ssoSeatId = is_array($seat) ? (int) ($seat['id'] ?? 0) : 0;

            if ($ssoSeatId <= 0) {
                continue;
            }

            $columns = $seats->toColumns($seat);

            if ($qualificationColumn !== null) {
                $columns[$qualificationColumn] = isset($seat['qualification_id']) ? ($qualifications[(int) $seat['qualification_id']] ?? null) : null;
            }

            $wanted[] = ['id' => $ssoSeatId, 'columns' => $columns, 'key' => (string) ($seat['position'] ?? '')];
        }

        $result = $this->childRows()->reconcile(
            $seats->table(),
            $seats->parentColumn(),
            self::parentId($unitId),
            self::SEAT_SSO_ID_COLUMN,
            $wanted,
            fn (object $local): string => (string) $local->{$seats->positionColumn()},
            self::sameValue(...),
            ChildRowPolicy::for($seats),
        );

        if ($result['unlinked'] !== []) {
            $this->unlinkedSeats += count($result['unlinked']);
            Log::info('SSO master data: local unit seats SSO does not list were kept', [
                'table' => $seats->table(),
                'unit_id' => $unitId,
                'seat_ids' => $result['unlinked'],
            ]);
        }

        return $result['changed'];
    }

    /**
     * The seats for the push, by local unit id (as a string). Unit ids come
     * from the company-scoped push rows; seats SSO removed are left out.
     *
     * @param  list<int|string>  $unitIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function seatsForPush(int $localCompanyId, array $unitIds): array
    {
        $seats = $this->units()->seatTable();

        if ($seats === null || $unitIds === []) {
            return [];
        }

        $qualificationColumn = $seats->qualificationColumn();
        $columns = ['id', $seats->parentColumn().' as mirror_parent_id', ...$seats->pushColumns()];

        if ($qualificationColumn !== null) {
            $columns[] = "{$qualificationColumn} as mirror_qualification_id";
        }

        $byUnit = [];
        $qualificationSsoIds = array_flip(array_map(intval(...), $this->linkMap('qualifications', $localCompanyId)));

        foreach (array_chunk($unitIds, 500) as $chunk) {
            $rows = DB::table($seats->table())
                ->whereIn($seats->parentColumn(), $chunk)
                ->when($this->childRows()->hasRemovedMarker($seats->table()), fn (Builder $query) => $query->whereNull(ChildRowPolicy::REMOVED_AT_COLUMN))
                ->orderBy($seats->positionColumn())
                ->orderBy('id')
                ->get(array_values(array_unique($columns)));

            foreach ($rows as $row) {
                $seat = ['local_id' => (int) $row->id] + $seats->toImport($row);
                $qualificationId = $row->mirror_qualification_id ?? null;

                if ($qualificationId !== null && isset($qualificationSsoIds[(int) $qualificationId])) {
                    $seat['qualification_sso_id'] = (int) $qualificationSsoIds[(int) $qualificationId];
                }

                $byUnit[(string) $row->mirror_parent_id][] = $seat;
            }
        }

        return $byUnit;
    }

    /**
     * @param  array<int|string, mixed>  $mapping  {seat_local_id: sso_seat_id}
     * @return list<array{local_id: int, sso_id: int, reason: string, name: null, held_by: int|string|null}>
     */
    private function applySeatMapping(UnitSeatTable $seats, int $unitId, array $mapping): array
    {
        $siblings = fn (): Builder => DB::table($seats->table())->where($seats->parentColumn(), $unitId);
        $linker = new MappingLinker(self::SEAT_SSO_ID_COLUMN, $siblings);
        $collisions = [];

        ksort($mapping);

        foreach ($mapping as $seatLocalId => $ssoSeatId) {
            $seatLocalId = (int) $seatLocalId;
            $ssoSeatId = (int) $ssoSeatId;

            if ($seatLocalId <= 0 || $ssoSeatId <= 0) {
                continue;
            }

            $row = $siblings()->where('id', $seatLocalId)->first();
            $refusal = $linker->refusal($row, $seatLocalId, $ssoSeatId)
                ?? $linker->write($seatLocalId, $ssoSeatId, fn () => $siblings()->where('id', $seatLocalId)->update([self::SEAT_SSO_ID_COLUMN => $ssoSeatId]));

            if ($refusal !== null) {
                $collisions[] = [
                    'local_id' => $seatLocalId,
                    'sso_id' => $ssoSeatId,
                    'reason' => "seat of unit {$unitId}: {$refusal['reason']}",
                    'name' => null,
                    'held_by' => $refusal['held_by'],
                ];
            }
        }

        return $collisions;
    }

    /**
     * Local id keyed by SSO id from another mirror of this app (vehicles,
     * locations, divisions, qualifications); empty when that mirror is not
     * installed here.
     *
     * @return array<int, int|string>
     */
    private function linkMap(string $entity, int $localCompanyId, bool $confirmedOnly = false): array
    {
        if (! $confirmedOnly && isset($this->links[$entity][$localCompanyId])) {
            return $this->links[$entity][$localCompanyId];
        }

        $mirror = $this->registry->knows($entity) ? $this->registry->mirror($entity) : null;
        $map = $mirror instanceof CatalogMirror && $mirror->isInstalled()
            ? $mirror->linkedMap($localCompanyId, confirmedOnly: $confirmedOnly)
            : [];

        return $confirmedOnly ? $map : $this->links[$entity][$localCompanyId] = $map;
    }

    private static function parentId(int|string $unitId): int
    {
        if (! is_int($unitId) && ! ctype_digit($unitId)) {
            throw new LogicException('Unit seats need integer unit ids; a projection over a uuid-keyed table must not declare a seat table.');
        }

        return (int) $unitId;
    }

    private function childRows(): LinkedChildRows
    {
        return $this->childRows ??= new LinkedChildRows;
    }
}
