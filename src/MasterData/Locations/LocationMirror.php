<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Locations;

use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\CatalogMirror;
use Unified\SsoClient\MasterData\Contracts\SeedsSso;
use Unified\SsoClient\MasterData\Divisions\DivisionMirror;
use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * Mirrors SSO's locations (an agency list: stations, headquarters, staging
 * posts and other own places; renamed from "stations" on 2026-10-09) into
 * the app's own location table. The table, its columns and how SSO's fields
 * land on them come from the bound LocationProjection; this class owns what
 * every app shares:
 *
 * - linking by SSO id (`sso_location_id`), adopting an unlinked local row by
 *   number first, then by name (pending until the push, --link-by-name or a
 *   resync confirms it), and the `sso_updated_at` stale guard (CatalogMirror);
 * - turn off, never delete: SSO turning a location off, or a resync no longer
 *   listing it, sets the active flag false. Crew's resources and shift
 *   templates hang off locations, CAD units and CloudPCR scenes hold their
 *   ids, so a delete would destroy agency data;
 * - the division: SSO sends its division id, translated to the local
 *   division through the divisions mirror's links. SSO saying "no division"
 *   clears it; a division this app has not linked yet leaves the local value
 *   as it is (a new row gets none) until the divisions mirror catches up;
 * - the push (SeedsSso): the app's rows go up once, before the flag is on,
 *   with each division named by SSO id when linked and always by local id.
 *
 * No person assignment: people are not placed at locations.
 */
class LocationMirror extends CatalogMirror implements SeedsSso
{
    public const SSO_ID_COLUMN = 'sso_location_id';

    /** SSO's location name limit; longer local names are cut on push. */
    public const MAX_NAME_LENGTH = 100;

    /**
     * Local division id keyed by SSO division id, per local company, for
     * this mirror instance (one webhook or one command run).
     *
     * @var array<int, array<int, int>>
     */
    private array $divisionLinks = [];

    public function __construct(
        LocalTenantResolver $tenants,
        MasterDataClient $client,
        protected readonly LocationProjection $projection,
        protected readonly MasterDataRegistry $registry,
    ) {
        parent::__construct($tenants, $client);
    }

    public function entity(): string
    {
        return 'locations';
    }

    public static function events(): array
    {
        return [
            'location.created',
            'location.updated',
            'location.deactivated',
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
        return $this->projection->nameColumn();
    }

    public function maxNameLength(): int
    {
        return self::MAX_NAME_LENGTH;
    }

    protected function recordKey(): string
    {
        return 'location';
    }

    public function isInstalled(): bool
    {
        return Schema::hasTable($this->table())
            && Schema::hasColumns($this->table(), [$this->ssoIdColumn(), $this->activeColumn(), 'sort_order', self::SSO_UPDATED_AT_COLUMN, self::LINK_PENDING_COLUMN]);
    }

    public function applyWebhook(string $event, int $localCompanyId, array $payload): array
    {
        return match ($event) {
            'location.created', 'location.updated', 'location.deactivated' => $this->applyUpsertWebhook($event, $localCompanyId, (array) ($payload['location'] ?? [])),
            default => ['status' => 'ignored', 'reason' => 'unknown_event'],
        };
    }

    /**
     * Writes the row, then lets the projection write what lives outside it
     * (phones) once the row is SSO's: created or updated, and on the
     * authoritative resync path also linked or unchanged, because the row
     * comparison cannot see child rows (right after the push the row's
     * sso_updated_at already equals SSO's). A pending adoption leaves the
     * agency's data alone.
     */
    public function upsert(int $localCompanyId, array $record, bool $delivery = false): string
    {
        $result = parent::upsert($localCompanyId, $record, $delivery);

        if ($result === 'created' || $result === 'updated' || (! $delivery && in_array($result, ['linked', 'unchanged'], true))) {
            $row = $this->findLinked($localCompanyId, (int) $record['id']);

            if ($row !== null) {
                $this->projection->written($localCompanyId, (int) $row->id, $record);
            }
        }

        return $result;
    }

    public function resync(int $localCompanyId, array $snapshot): array
    {
        return $this->reconcileCatalog($localCompanyId, $this->snapshotRecords($snapshot));
    }

    /**
     * Cutover helper: links unlinked local rows by number first (when the
     * app has a number column and the number is unique on both sides), then
     * by name. Never creates, merges or deletes.
     */
    public function linkByName(int $localCompanyId, array $snapshot): array
    {
        $byNumber = $this->linkByNumber($localCompanyId, $this->snapshotRecords($snapshot));
        $result = parent::linkByName($localCompanyId, $snapshot);
        $result['linked'] = [...$byNumber, ...$result['linked']];

        return $result;
    }

    /**
     * The company's local locations in the shape of SSO's
     * POST .../locations/import. Names longer than SSO's 100 characters are
     * cut and reported in truncated_names.
     */
    public function buildImportPayload(int $localCompanyId): array
    {
        $divisionColumn = $this->projection->divisionColumn();
        $columns = [$this->activeColumn().' as mirror_is_active', ...$this->projection->pushColumns()];

        if ($divisionColumn !== null) {
            $columns[] = $divisionColumn.' as mirror_division_id';
        }

        ['rows' => $rows, 'truncated_names' => $truncatedNames] = $this->rowsForPush($localCompanyId, array_values(array_unique($columns)));
        $ssoDivisionByLocal = array_flip($this->divisionLinkMap($localCompanyId, confirmedOnly: true));

        $locations = array_map(function (object $row) use ($localCompanyId, $divisionColumn, $ssoDivisionByLocal): array {
            $localDivisionId = $divisionColumn === null || $row->mirror_division_id === null ? null : (int) $row->mirror_division_id;

            return array_filter([
                'local_id' => (int) $row->id,
                'name' => (string) $row->name,
                'is_active' => (bool) $row->mirror_is_active,
                'division_local_id' => $localDivisionId,
                'division_sso_id' => $localDivisionId === null ? null : ($ssoDivisionByLocal[$localDivisionId] ?? null),
            ], fn (mixed $value): bool => $value !== null) + $this->projection->toImport($localCompanyId, $row);
        }, $rows);

        return [
            'payload' => [
                'app_slug' => (string) config('sso.app_slug'),
                'locations' => $locations,
            ],
            'skipped_users' => 0,
            'truncated_names' => $truncatedNames,
        ];
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
     * Adds the local division when the projection has a division column and
     * the record says something this app can apply (see the class doc).
     */
    protected function attributesFor(int $localCompanyId, array $record): array
    {
        $attributes = $this->attributes($record);
        $column = $this->projection->divisionColumn();

        if ($column === null || ! array_key_exists('division_id', $record)) {
            return $attributes;
        }

        $ssoDivisionId = $record['division_id'] === null ? null : (int) $record['division_id'];

        if ($ssoDivisionId === null) {
            $attributes['division'] = null;
        } elseif (isset($this->divisionLinkMap($localCompanyId)[$ssoDivisionId])) {
            $attributes['division'] = $this->divisionLinkMap($localCompanyId)[$ssoDivisionId];
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
        ] + $attributes['columns'];

        if (array_key_exists('division', $attributes) && $this->projection->divisionColumn() !== null) {
            $columns[$this->projection->divisionColumn()] = $attributes['division'];
        }

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

            if (! self::same($stored, $value)) {
                return false;
            }
        }

        return true;
    }

    protected function findAdoptableFor(int $localCompanyId, array $record): ?object
    {
        $number = $this->normalizeNumber($record['number'] ?? null);
        $column = $this->projection->numberColumn();

        if ($number !== null && $column !== null) {
            $candidates = $this->scoped($localCompanyId)
                ->whereNull($this->ssoIdColumn())
                ->whereNotNull($column)
                ->get()
                ->filter(fn (object $row): bool => $this->normalizeNumber($row->{$column}) === $number);

            if ($candidates->count() === 1) {
                return $candidates->first();
            }
        }

        return parent::findAdoptableFor($localCompanyId, $record);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array{local_id: int, sso_id: int, name: string}>
     */
    private function linkByNumber(int $localCompanyId, array $records): array
    {
        $column = $this->projection->numberColumn();

        if ($column === null) {
            return [];
        }

        $linked = $this->linkedMap($localCompanyId);
        $ssoByNumber = [];

        foreach ($records as $record) {
            $number = $this->normalizeNumber($record['number'] ?? null);

            if ($number !== null && ! isset($linked[(int) $record['id']])) {
                $ssoByNumber[$number][] = (int) $record['id'];
            }
        }

        $localByNumber = [];

        foreach ($this->scoped($localCompanyId)->whereNull($this->ssoIdColumn())->whereNotNull($column)->orderBy('id')->get(['id', $this->nameColumn().' as name', $column.' as number']) as $row) {
            $number = $this->normalizeNumber($row->number);

            if ($number !== null) {
                $localByNumber[$number][] = $row;
            }
        }

        $result = [];

        foreach ($ssoByNumber as $number => $ssoIds) {
            $rows = $localByNumber[$number] ?? [];

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
     * Local division id keyed by SSO division id, from the divisions mirror
     * of this app; empty when the app has no divisions mirror installed.
     *
     * @return array<int, int>
     */
    private function divisionLinkMap(int $localCompanyId, bool $confirmedOnly = false): array
    {
        if (! $this->registry->knows('divisions')) {
            return [];
        }

        $divisions = $this->registry->mirror('divisions');

        if (! $divisions instanceof DivisionMirror || ! $divisions->isInstalled()) {
            return [];
        }

        if ($confirmedOnly) {
            return $divisions->linkedMap($localCompanyId, confirmedOnly: true);
        }

        return $this->divisionLinks[$localCompanyId] ??= $divisions->linkedMap($localCompanyId);
    }

    private function normalizeNumber(mixed $number): ?string
    {
        if (! is_scalar($number)) {
            return null;
        }

        $number = mb_strtolower(trim((string) $number));

        return $number === '' ? null : $number;
    }

    /**
     * Stored and incoming values are equal for the mirror: numbers by value
     * ("44.324700" equals 44.3247), booleans against 0/1, the rest as text.
     */
    private static function same(mixed $stored, mixed $value): bool
    {
        if ($stored === null || $value === null) {
            return $stored === null && $value === null;
        }

        if (is_bool($value)) {
            return (bool) $stored === $value;
        }

        if (is_numeric($stored) && is_numeric($value)) {
            return abs((float) $stored - (float) $value) < 0.0000005;
        }

        return (string) $stored === (string) $value;
    }
}
