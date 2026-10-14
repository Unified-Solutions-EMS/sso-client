<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Vehicles;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Unified\SsoClient\MasterData\CatalogMirror;
use Unified\SsoClient\MasterData\Contracts\SeedsSso;
use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Support\ChildRowPolicy;
use Unified\SsoClient\MasterData\Support\LinkedChildRows;
use Unified\SsoClient\MasterData\Support\MappingLinker;

/**
 * Mirrors SSO's vehicles (an agency list: the units it runs, NEMSIS
 * dVehicle) into the app's own vehicle table. The table, its columns, and
 * its crew-level / yearly-usage child tables come from the bound
 * VehicleProjection; this class owns what every app shares:
 *
 * - linking by SSO id (`sso_vehicle_id`), adopting an unlinked local row by
 *   VIN first, then unit number, then call sign, all normalized the way SSO
 *   matches (trim, uppercase, no spaces or hyphens); a unit number or call
 *   sign candidate whose VIN differs from SSO's is never adopted (a replaced
 *   rig that kept its unit number is another vehicle). Adoption by a
 *   delivery is pending until the push, --link-by-name or a resync confirms
 *   it (CatalogMirror);
 * - turn off, never delete: SSO turning a vehicle off, or a resync no longer
 *   listing it, sets the active flag false. PCRs, checks and shifts hold
 *   vehicle ids;
 * - the home location and division: SSO sends its own ids, translated
 *   through the locations and divisions mirrors' links. SSO saying "none"
 *   clears the local value; an id this app has not linked yet leaves it as
 *   it is (a new row gets none) until that mirror catches up;
 * - child rows (crew levels, yearly usage) are reconciled IN PLACE by SSO's
 *   stable child id: an edited child keeps its local row id (CloudPCR derives
 *   NEMSIS group UUIDs from it), a new one is inserted, and a linked child is
 *   handled by the child table's ChildRowPolicy when SSO no longer lists its
 *   id (unlink unless the table declares deactivate or delete; a row stamped
 *   `sso_removed_at` is left out of the push). A local child SSO never
 *   knew (pre-cutover, not taken by the push because SSO already had
 *   children) is adopted by level / year when exactly one fits, otherwise
 *   kept and counted as `unlinked_children` on a resync;
 * - the push (SeedsSso): the app's rows go up once, before the flag is on,
 *   children included with their local ids, and SSO's mapping links both.
 */
class VehicleMirror extends CatalogMirror implements SeedsSso
{
    public const SSO_ID_COLUMN = 'sso_vehicle_id';

    /** SSO's unit number limit; longer local unit numbers are cut on push. */
    public const MAX_UNIT_NUMBER_LENGTH = 25;

    /**
     * The SSO link column the migration adds to each child table, by the
     * SSO list the table holds.
     */
    public const CHILD_SSO_ID_COLUMNS = [
        'crew_levels' => 'sso_crew_level_id',
        'yearly_usage' => 'sso_yearly_usage_id',
    ];

    /**
     * The SSO child field an unlinked local child is adopted by.
     */
    private const CHILD_KEYS = [
        'crew_levels' => 'level',
        'yearly_usage' => 'year',
    ];

    /**
     * Local id keyed by SSO id, per entity and local company, for this
     * mirror instance (one webhook or one command run).
     *
     * @var array<string, array<int, array<int, int>>>
     */
    private array $links = [];

    private int $unlinkedChildren = 0;

    private ?LinkedChildRows $childRows = null;

    public function __construct(
        LocalTenantResolver $tenants,
        MasterDataClient $client,
        protected readonly VehicleProjection $projection,
        protected readonly MasterDataRegistry $registry,
    ) {
        parent::__construct($tenants, $client);
    }

    public function entity(): string
    {
        return 'vehicles';
    }

    public static function events(): array
    {
        return [
            'vehicle.created',
            'vehicle.updated',
            'vehicle.deactivated',
            'vehicle.batch_updated',
        ];
    }

    public function table(): string
    {
        return $this->projection->table();
    }

    public function ssoIdColumn(): string
    {
        return self::SSO_ID_COLUMN;
    }

    public function nameColumn(): string
    {
        return $this->projection->unitNumberColumn();
    }

    public function maxNameLength(): int
    {
        return self::MAX_UNIT_NUMBER_LENGTH;
    }

    protected function recordKey(): string
    {
        return 'vehicle';
    }

    /**
     * @return array<'crew_levels'|'yearly_usage', VehicleChildTable>
     */
    public function childTables(): array
    {
        return $this->projection->childTables();
    }

    public function isInstalled(): bool
    {
        if (! Schema::hasTable($this->table())
            || ! Schema::hasColumns($this->table(), [$this->ssoIdColumn(), $this->activeColumn(), 'sort_order', self::SSO_UPDATED_AT_COLUMN, self::LINK_PENDING_COLUMN])) {
            return false;
        }

        foreach ($this->childTables() as $kind => $child) {
            if (! Schema::hasTable($child->table()) || ! Schema::hasColumn($child->table(), self::CHILD_SSO_ID_COLUMNS[$kind])) {
                return false;
            }
        }

        return true;
    }

    public function applyWebhook(string $event, int $localCompanyId, array $payload): array
    {
        return match ($event) {
            'vehicle.created', 'vehicle.updated', 'vehicle.deactivated' => $this->applyUpsertWebhook($event, $localCompanyId, (array) ($payload['vehicle'] ?? [])),
            'vehicle.batch_updated' => $this->applyBatch($localCompanyId, (array) ($payload['vehicles'] ?? [])),
            default => ['status' => 'ignored', 'reason' => 'unknown_event'],
        };
    }

    /**
     * Writes the row, then reconciles its child rows once the row is SSO's:
     * created, updated or unchanged (a child edit need not change the row),
     * and on the authoritative resync path also linked. A pending adoption
     * and a stale delivery leave the children alone. A row whose only change
     * was a child reports `updated`.
     */
    public function upsert(int $localCompanyId, array $record, bool $delivery = false): string
    {
        $record = $this->withName($record);
        $result = parent::upsert($localCompanyId, $record, $delivery);

        if (! in_array($result, ['created', 'updated', 'unchanged'], true) && ($delivery || $result !== 'linked')) {
            return $result;
        }

        $row = $this->findLinked($localCompanyId, (int) $record['id']);

        if ($row === null || ! $this->reconcileChildren((int) $row->id, $record)) {
            return $result;
        }

        return $result === 'unchanged' ? 'updated' : $result;
    }

    /**
     * @return array<string, int>
     */
    public function resync(int $localCompanyId, array $snapshot): array
    {
        $this->unlinkedChildren = 0;
        $counts = $this->reconcileCatalog($localCompanyId, $this->snapshotRecords($snapshot));

        return $this->childTables() === [] ? $counts : $counts + ['unlinked_children' => $this->unlinkedChildren];
    }

    /**
     * Cutover helper: links unlinked local rows by VIN first (unique on both
     * sides), then by unit number. Never creates, merges or deletes.
     */
    public function linkByName(int $localCompanyId, array $snapshot): array
    {
        $byVin = $this->linkByVin($localCompanyId, $this->snapshotRecords($snapshot));
        $result = parent::linkByName($localCompanyId, $snapshot);
        $result['linked'] = [...$byVin, ...$result['linked']];

        return $result;
    }

    public function importBatchSize(): int
    {
        return 1000;
    }

    /**
     * The company's local vehicles in the shape of SSO's
     * POST .../vehicles/import, children included with their local ids. A
     * home location or division goes up by SSO id when this app's link to it
     * is confirmed, otherwise by local id (SSO maps that through the links
     * its own locations / divisions import recorded). Unit numbers longer
     * than SSO's 25 characters are cut and reported in truncated_names.
     */
    public function buildImportPayload(int $localCompanyId): array
    {
        $references = array_filter([
            'home_location' => [$this->projection->homeLocationColumn(), 'locations'],
            'division' => [$this->projection->divisionColumn(), 'divisions'],
        ], fn (array $reference): bool => $reference[0] !== null);

        $columns = [$this->activeColumn().' as mirror_is_active', ...$this->projection->pushColumns()];
        foreach ($references as $key => [$column]) {
            $columns[] = "{$column} as mirror_{$key}_id";
        }

        ['rows' => $rows, 'truncated_names' => $truncatedNames] = $this->rowsForPush($localCompanyId, array_values(array_unique($columns)));

        $ssoByLocal = [];
        foreach ($references as $key => [, $entity]) {
            $ssoByLocal[$key] = array_flip($this->linkMap($entity, $localCompanyId, confirmedOnly: true));
        }

        $children = $this->childrenForPush(array_map(fn (object $row): int => (int) $row->id, $rows));

        $vehicles = array_map(function (object $row) use ($localCompanyId, $references, $ssoByLocal, $children): array {
            $vehicle = [
                'local_id' => (int) $row->id,
                'unit_number' => (string) $row->name,
                'is_active' => (bool) $row->mirror_is_active,
            ];

            foreach (array_keys($references) as $key) {
                $localId = $row->{"mirror_{$key}_id"} === null ? null : (int) $row->{"mirror_{$key}_id"};

                if ($localId !== null) {
                    $vehicle += isset($ssoByLocal[$key][$localId])
                        ? ["{$key}_sso_id" => $ssoByLocal[$key][$localId]]
                        : ["{$key}_local_id" => $localId];
                }
            }

            return $vehicle + $this->projection->toImport($localCompanyId, $row) + ($children[(int) $row->id] ?? []);
        }, $rows);

        return [
            'payload' => [
                'app_slug' => (string) config('sso.app_slug'),
                'vehicles' => $vehicles,
            ],
            'skipped_users' => 0,
            'truncated_names' => $truncatedNames,
        ];
    }

    /**
     * Links the vehicles (CatalogMirror), then each linked vehicle's child
     * rows to the SSO child ids in the mapping's `crew_levels` /
     * `yearly_usage` maps ({child_local_id: sso_child_id}). A child already
     * linked to another SSO id, or an SSO child id another child of the same
     * vehicle holds, is reported and left alone.
     */
    public function applyImportMapping(int $localCompanyId, array $mapping): array
    {
        $result = parent::applyImportMapping($localCompanyId, $mapping);
        $refused = array_flip(array_column($result['collisions'], 'local_id'));

        foreach ($mapping as $localId => $entry) {
            if (! is_array($entry) || (int) $localId <= 0 || isset($refused[(int) $localId])) {
                continue;
            }

            foreach ($this->childTables() as $kind => $child) {
                if (is_array($entry[$kind] ?? null)) {
                    array_push($result['collisions'], ...$this->applyChildMapping($child, $kind, (int) $localId, $entry[$kind]));
                }
            }
        }

        return $result;
    }

    protected function attributes(array $record): array
    {
        return [
            'is_active' => array_key_exists('is_active', $record) ? (bool) $record['is_active'] : true,
            'sort_order' => isset($record['sort_order']) ? max(0, (int) $record['sort_order']) : null,
            'sso_updated_at' => $this->normalizeTimestamp($record['updated_at'] ?? null),
            'columns' => $this->projection->toColumns($record),
        ];
    }

    /**
     * Adds the local home location and division when the projection has the
     * column and the row says something this app can apply (see the class
     * doc).
     */
    protected function attributesFor(int $localCompanyId, array $record): array
    {
        $attributes = $this->attributes($record);

        foreach ([
            'home_location' => [$this->projection->homeLocationColumn(), 'home_location_id', 'locations'],
            'division' => [$this->projection->divisionColumn(), 'division_id', 'divisions'],
        ] as $key => [$column, $field, $entity]) {
            if ($column === null || ! array_key_exists($field, $record)) {
                continue;
            }

            if ($record[$field] === null) {
                $attributes['references'][$column] = null;
            } elseif (isset($this->linkMap($entity, $localCompanyId)[(int) $record[$field]])) {
                $attributes['references'][$column] = $this->linkMap($entity, $localCompanyId)[(int) $record[$field]];
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function toColumns(array $attributes): array
    {
        $columns = [
            $this->nameColumn() => $attributes['name'],
            $this->activeColumn() => $attributes['is_active'],
        ] + ($attributes['references'] ?? []) + $attributes['columns'];

        if ($attributes['sort_order'] !== null) {
            $columns['sort_order'] = $attributes['sort_order'];
        }

        if ($attributes['sso_updated_at'] !== null) {
            $columns[self::SSO_UPDATED_AT_COLUMN] = $attributes['sso_updated_at'];
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function matches(object $row, array $attributes): bool
    {
        foreach ($this->toColumns($attributes) as $column => $value) {
            $stored = $row->{$column} ?? null;
            $stored = $column === self::SSO_UPDATED_AT_COLUMN ? $this->normalizeTimestamp($stored) : $stored;

            if (! self::sameValue($stored, $value)) {
                return false;
            }
        }

        return true;
    }

    protected function findAdoptableFor(int $localCompanyId, array $record): ?object
    {
        $unlinked = $this->scoped($localCompanyId)->whereNull($this->ssoIdColumn())->get();
        $vin = $this->normalizeName((string) ($record['vin'] ?? ''));
        $vinColumn = $this->projection->vinColumn();

        if ($vin !== '' && $vinColumn !== null) {
            $byVin = $unlinked->filter(fn (object $row): bool => $this->normalizeName((string) $row->{$vinColumn}) === $vin);

            if ($byVin->count() === 1) {
                return $byVin->first();
            }
        }

        foreach ([[$this->nameColumn(), 'unit_number'], [$this->projection->callSignColumn(), 'call_sign']] as [$column, $field]) {
            $key = $this->normalizeName((string) ($record[$field] ?? ''));

            if ($column === null || $key === '') {
                continue;
            }

            $candidates = $unlinked->filter(fn (object $row): bool => $this->normalizeName((string) $row->{$column}) === $key
                && ! $this->vinDiffers($row, $vin));

            if ($candidates->count() === 1) {
                return $candidates->first();
            }
        }

        return null;
    }

    /**
     * SSO calls the vehicle's name its unit number; the shared catalog code
     * reads `name`.
     */
    protected function snapshotRecords(array $snapshot): array
    {
        $records = $snapshot[$this->entity()] ?? null;

        if (! is_array($records)) {
            throw new InvalidArgumentException("SSO snapshot is missing the {$this->entity()} list.");
        }

        return parent::snapshotRecords([
            $this->entity() => array_map(fn (mixed $record): mixed => is_array($record) ? $this->withName($record) : $record, $records),
        ]);
    }

    /**
     * SSO's matching key: trimmed, uppercase, no spaces or hyphens, so
     * "m-1", "M 1" and "M1" are one unit (and one VIN).
     */
    protected function normalizeName(string $name): string
    {
        return str_replace([' ', '-'], '', mb_strtoupper(trim($name)));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function withName(array $record): array
    {
        $record['name'] = trim((string) ($record['unit_number'] ?? ''));

        return $record;
    }

    private function vinDiffers(object $row, string $ssoVin): bool
    {
        $column = $this->projection->vinColumn();

        if ($column === null || $ssoVin === '') {
            return false;
        }

        $local = $this->normalizeName((string) $row->{$column});

        return $local !== '' && $local !== $ssoVin;
    }

    /**
     * Updates the vehicle's child rows from SSO's lists in place (see the
     * class doc). A list SSO left out of the row is not touched; a child SSO
     * removed is handled by the child table's ChildRowPolicy.
     *
     * @param  array<string, mixed>  $record
     * @return bool whether any child row changed
     */
    private function reconcileChildren(int $vehicleId, array $record): bool
    {
        $changed = false;

        foreach ($this->childTables() as $kind => $child) {
            if (! is_array($record[$kind] ?? null)) {
                continue;
            }

            $wanted = [];

            foreach ($record[$kind] as $ssoChild) {
                $ssoChildId = is_array($ssoChild) ? (int) ($ssoChild['id'] ?? 0) : 0;

                if ($ssoChildId > 0) {
                    $wanted[] = ['id' => $ssoChildId, 'columns' => $child->toColumns($ssoChild), 'key' => (string) ($ssoChild[self::CHILD_KEYS[$kind]] ?? '')];
                }
            }

            $result = $this->childRows()->reconcile(
                $child->table(),
                $child->parentColumn(),
                $vehicleId,
                self::CHILD_SSO_ID_COLUMNS[$kind],
                $wanted,
                fn (object $local): string => (string) $local->{$child->keyColumn()},
                self::sameValue(...),
                ChildRowPolicy::for($child),
            );
            $changed = $result['changed'] || $changed;

            if ($result['unlinked'] !== []) {
                $this->unlinkedChildren += count($result['unlinked']);
                Log::info('SSO master data: local vehicle child rows SSO does not list were kept', [
                    'table' => $child->table(),
                    'vehicle_id' => $vehicleId,
                    'child_ids' => $result['unlinked'],
                ]);
            }
        }

        return $changed;
    }

    /**
     * @param  array<int|string, mixed>  $mapping  {child_local_id: sso_child_id}
     * @return list<array{local_id: int, sso_id: int, reason: string, name: null, held_by: int|null}>
     */
    private function applyChildMapping(VehicleChildTable $child, string $kind, int $vehicleId, array $mapping): array
    {
        $ssoColumn = self::CHILD_SSO_ID_COLUMNS[$kind];
        $siblings = fn (): Builder => DB::table($child->table())->where($child->parentColumn(), $vehicleId);
        $linker = new MappingLinker($ssoColumn, $siblings);
        $collisions = [];

        ksort($mapping);

        foreach ($mapping as $childLocalId => $ssoChildId) {
            $childLocalId = (int) $childLocalId;
            $ssoChildId = (int) $ssoChildId;

            if ($childLocalId <= 0 || $ssoChildId <= 0) {
                continue;
            }

            $row = $siblings()->where('id', $childLocalId)->first();
            $refusal = $linker->refusal($row, $childLocalId, $ssoChildId)
                ?? $linker->write($childLocalId, $ssoChildId, fn () => $siblings()->where('id', $childLocalId)->update([$ssoColumn => $ssoChildId]));

            if ($refusal !== null) {
                $collisions[] = [
                    'local_id' => $childLocalId,
                    'sso_id' => $ssoChildId,
                    'reason' => "{$kind} of vehicle {$vehicleId}: {$refusal['reason']}",
                    'name' => null,
                    'held_by' => $refusal['held_by'],
                ];
            }
        }

        return $collisions;
    }

    /**
     * The child lists for the push, by local vehicle id. Vehicle ids come
     * from rowsForPush, already pinned to the company.
     *
     * @param  list<int>  $vehicleIds
     * @return array<int, array<string, list<array<string, mixed>>>>
     */
    private function childrenForPush(array $vehicleIds): array
    {
        $children = [];

        foreach ($this->childTables() as $kind => $child) {
            foreach (array_chunk($vehicleIds, 500) as $chunk) {
                $rows = DB::table($child->table())
                    ->whereIn($child->parentColumn(), $chunk)
                    ->when($this->childRows()->hasRemovedMarker($child->table()), fn ($query) => $query->whereNull(ChildRowPolicy::REMOVED_AT_COLUMN))
                    ->orderBy('id')
                    ->get(array_values(array_unique(['id', $child->parentColumn().' as mirror_parent_id', ...$child->pushColumns()])));

                foreach ($rows as $row) {
                    $children[(int) $row->mirror_parent_id][$kind][] = ['local_id' => (int) $row->id] + $child->toImport($row);
                }
            }
        }

        return $children;
    }

    /**
     * Cutover helper half: links by VIN where exactly one SSO row and one
     * unlinked local row share it.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array{local_id: int, sso_id: int, name: string}>
     */
    private function linkByVin(int $localCompanyId, array $records): array
    {
        $column = $this->projection->vinColumn();

        if ($column === null) {
            return [];
        }

        $linked = $this->linkedMap($localCompanyId);
        $ssoByVin = [];

        foreach ($records as $record) {
            $vin = $this->normalizeName((string) ($record['vin'] ?? ''));

            if ($vin !== '' && ! isset($linked[(int) $record['id']])) {
                $ssoByVin[$vin][] = (int) $record['id'];
            }
        }

        $localByVin = [];

        foreach ($this->scoped($localCompanyId)->whereNull($this->ssoIdColumn())->whereNotNull($column)->orderBy('id')->get(['id', $this->nameColumn().' as name', $column.' as vin']) as $row) {
            $vin = $this->normalizeName((string) $row->vin);

            if ($vin !== '') {
                $localByVin[$vin][] = $row;
            }
        }

        $result = [];

        foreach ($ssoByVin as $vin => $ssoIds) {
            $rows = $localByVin[$vin] ?? [];

            if (count($ssoIds) !== 1 || count($rows) !== 1) {
                continue;
            }

            $this->scoped($localCompanyId)
                ->where('id', $rows[0]->id)
                ->whereNull($this->ssoIdColumn())
                ->update([$this->ssoIdColumn() => $ssoIds[0], self::LINK_PENDING_COLUMN => false, 'updated_at' => now()]);

            $result[] = ['local_id' => (int) $rows[0]->id, 'sso_id' => $ssoIds[0], 'name' => (string) $rows[0]->name];
        }

        return $result;
    }

    /**
     * Local id keyed by SSO id from another mirror of this app (locations,
     * divisions); empty when that mirror is not installed here.
     *
     * @return array<int, int>
     */
    private function linkMap(string $entity, int $localCompanyId, bool $confirmedOnly = false): array
    {
        if (! $confirmedOnly && isset($this->links[$entity][$localCompanyId])) {
            return $this->links[$entity][$localCompanyId];
        }

        $mirror = $this->registry->mirror($entity);
        $map = $mirror instanceof CatalogMirror && $mirror->isInstalled()
            ? $mirror->linkedMap($localCompanyId, confirmedOnly: $confirmedOnly)
            : [];

        return $confirmedOnly ? $map : $this->links[$entity][$localCompanyId] = $map;
    }

    private function childRows(): LinkedChildRows
    {
        return $this->childRows ??= new LinkedChildRows;
    }
}
