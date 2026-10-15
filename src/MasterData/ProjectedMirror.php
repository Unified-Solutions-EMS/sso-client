<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Unified\SsoClient\MasterData\Contracts\RecordProjection;
use Unified\SsoClient\MasterData\Contracts\SeedsSso;

/**
 * The shared half of the demographics mirrors (agency, contacts, devices,
 * state configurations): every row's shape comes from the bound
 * RecordProjection, and this class owns what the facility mirror owns for
 * facilities:
 *
 * - linking by SSO id (the subclass's link column) and adopting an unlinked
 *   local row by the projection's match columns (exactly one candidate,
 *   trimmed, ignoring case); a delivery's adoption is pending until the
 *   push, --link-by-name or a resync confirms it (CatalogMirror);
 * - the `sso_updated_at` stale guard and turn off, never delete;
 * - this app's own kept values: what the push sent that SSO's rules refused
 *   comes back in every row as `pending: {field: {app_slug: value}}`;
 *   withOwnPending() puts this app's value back over SSO's before the row is
 *   written, so a resync or delivery never blanks what the app sent;
 * - `{record}.created|updated|deactivated` (full row, all upsert) and
 *   `{record}.batch_updated` (`{<entity>: [rows]}`, applied like a resync
 *   page, nothing turned off);
 * - the push (SeedsSso): `{app_slug, <entity>: [{local_id, is_active,
 *   ...projection->toSso()}]}`, linked by SSO's mapping (MappingLinker).
 *
 * SSO's rows carry no common `name`; CatalogMirror needs one, so each
 * subclass says what names a row (recordName()). The name is only used in
 * reports; the projection decides every column written.
 */
abstract class ProjectedMirror extends CatalogMirror implements SeedsSso
{
    /** Names only appear in reports here; nothing is cut on push. */
    public const MAX_NAME_LENGTH = 255;

    /**
     * Where withName() keeps SSO's own `name` field (a contact's full name,
     * a device's name), so the projection reads what SSO sent, not the
     * report name.
     */
    private const SSO_NAME = '__sso_client_original_name';

    private ?bool $hasSortOrder = null;

    public function __construct(
        LocalTenantResolver $tenants,
        MasterDataClient $client,
        protected readonly RecordProjection $projection,
    ) {
        parent::__construct($tenants, $client);
    }

    /**
     * What names one SSO row in reports (a contact's full name, a device's
     * name or serial). Must not be empty for a usable row.
     *
     * @param  array<string, mixed>  $record
     */
    abstract protected function recordName(array $record): string;

    public function table(): string
    {
        return $this->projection->table();
    }

    public function nameColumn(): string
    {
        return $this->projection->nameColumn();
    }

    public function maxNameLength(): int
    {
        return self::MAX_NAME_LENGTH;
    }

    public function projection(): RecordProjection
    {
        return $this->projection;
    }

    public function isInstalled(): bool
    {
        return Schema::hasTable($this->table()) && Schema::hasColumns($this->table(), $this->requiredColumns());
    }

    /**
     * @return list<string>
     */
    protected function requiredColumns(): array
    {
        return [$this->ssoIdColumn(), $this->activeColumn(), self::SSO_UPDATED_AT_COLUMN, self::LINK_PENDING_COLUMN];
    }

    public function applyWebhook(string $event, int $localCompanyId, array $payload): array
    {
        if ($event === $this->recordKey().'.batch_updated') {
            return $this->applyBatch($localCompanyId, (array) ($payload[$this->entity()] ?? []));
        }

        if (in_array($event, static::events(), true)) {
            return $this->applyUpsertWebhook($event, $localCompanyId, (array) ($payload[$this->recordKey()] ?? []));
        }

        return ['status' => 'ignored', 'reason' => 'unknown_event'];
    }

    /**
     * Writes the row, then lets the projection write what lives outside it
     * once the row is SSO's: created or updated, and on the authoritative
     * resync path also linked or unchanged, because the row comparison
     * cannot see child rows. A pending adoption leaves the agency's data
     * alone.
     */
    public function upsert(int $localCompanyId, array $record, bool $delivery = false): string
    {
        $record = $this->withName(self::withOwnPending($record, (string) config('sso.app_slug')));
        $result = parent::upsert($localCompanyId, $record, $delivery);

        if ($result === 'created' || $result === 'updated' || (! $delivery && in_array($result, ['linked', 'unchanged'], true))) {
            $row = $this->findLinked($localCompanyId, (int) $record['id']);

            if ($row !== null) {
                $this->projection->written($localCompanyId, (int) $row->id, $this->forProjection($record));
            }
        }

        return $result;
    }

    /**
     * The SSO row with this app's kept values in place of SSO's: a field SSO
     * could not store reads as what this app sent; a kept list (phones,
     * emails) is added after SSO's own entries. Rows with nothing kept for
     * the app are returned unchanged; another app's kept values are never
     * applied.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function withOwnPending(array $record, string $appSlug): array
    {
        $pending = $record['pending'] ?? null;

        if (! is_array($pending) || $appSlug === '') {
            return $record;
        }

        foreach ($pending as $field => $byApp) {
            if (! is_string($field) || ! is_array($byApp) || ! array_key_exists($appSlug, $byApp)) {
                continue;
            }

            $kept = $byApp[$appSlug];
            $current = data_get($record, $field);

            data_set($record, $field, is_array($kept) && array_is_list($kept) && is_array($current) && array_is_list($current)
                ? [...$current, ...$kept]
                : $kept);
        }

        return $record;
    }

    public function resync(int $localCompanyId, array $snapshot): array
    {
        return $this->reconcileCatalog($localCompanyId, $this->snapshotRecords($snapshot));
    }

    /**
     * Cutover helper: links unlinked local rows to SSO rows by the
     * projection's match columns where the key is unique on both sides.
     * Never creates, merges or deletes.
     */
    public function linkByName(int $localCompanyId, array $snapshot): array
    {
        $linked = $this->linkedMap($localCompanyId);
        $ssoByKey = [];
        $result = ['linked' => [], 'ambiguous' => [], 'unmatched_local' => [], 'unmatched_sso' => []];

        foreach ($this->snapshotRecords($snapshot) as $record) {
            if (isset($linked[(int) $record['id']])) {
                continue;
            }

            $entry = ['id' => (int) $record['id'], 'name' => trim((string) $record['name'])];
            $key = $this->ssoMatchKey($localCompanyId, $record);

            if ($key === null) {
                $result['unmatched_sso'][] = $entry;
            } else {
                $ssoByKey[$key][] = $entry;
            }
        }

        $localByKey = [];

        foreach ($this->scoped($localCompanyId)->whereNull($this->ssoIdColumn())->orderBy('id')->get() as $row) {
            $entry = ['id' => (int) $row->id, 'name' => (string) ($row->{$this->nameColumn()} ?? '')];
            $key = $this->localMatchKey($row);

            if ($key === null) {
                $result['unmatched_local'][] = $entry;
            } else {
                $localByKey[$key][] = $entry;
            }
        }

        foreach ($ssoByKey as $key => $ssoRows) {
            $localRows = $localByKey[$key] ?? [];
            unset($localByKey[$key]);

            if ($localRows === []) {
                array_push($result['unmatched_sso'], ...$ssoRows);

                continue;
            }

            if (count($ssoRows) > 1 || count($localRows) > 1) {
                $result['ambiguous'][] = [
                    'name' => $ssoRows[0]['name'],
                    'local_ids' => array_column($localRows, 'id'),
                    'sso_ids' => array_column($ssoRows, 'id'),
                ];

                continue;
            }

            $this->confirmLink($localCompanyId, $localRows[0]['id'], $ssoRows[0]['id']);
            $result['linked'][] = ['local_id' => $localRows[0]['id'], 'sso_id' => $ssoRows[0]['id'], 'name' => $localRows[0]['name'] !== '' ? $localRows[0]['name'] : $ssoRows[0]['name']];
        }

        foreach ($localByKey as $localRows) {
            array_push($result['unmatched_local'], ...$localRows);
        }

        return $result;
    }

    /**
     * The company's local rows in the shape of SSO's POST .../{entity}/import.
     */
    public function buildImportPayload(int $localCompanyId): array
    {
        $rows = $this->scoped($localCompanyId)
            ->orderBy('id')
            ->get($this->pushSelect());

        return [
            'payload' => [
                'app_slug' => (string) config('sso.app_slug'),
                $this->entity() => array_map(fn (object $row): array => $this->importRow($localCompanyId, $row), $rows->all()),
            ],
            'skipped_users' => 0,
            'truncated_names' => [],
        ];
    }

    /**
     * @return list<string>
     */
    protected function pushSelect(): array
    {
        return array_values(array_unique([
            'id',
            $this->activeColumn().' as mirror_is_active',
            ...$this->projection->pushColumns(),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function importRow(int $localCompanyId, object $row): array
    {
        return [
            'local_id' => (int) $row->id,
            'is_active' => (bool) $row->mirror_is_active,
        ] + $this->projection->toSso($localCompanyId, $row);
    }

    protected function attributes(array $record): array
    {
        return [
            'is_active' => array_key_exists('is_active', $record) ? (bool) $record['is_active'] : true,
            'sort_order' => isset($record['sort_order']) ? max(0, (int) $record['sort_order']) : null,
            'sso_updated_at' => $this->normalizeTimestamp($record['updated_at'] ?? null),
            'columns' => [],
        ];
    }

    protected function attributesFor(int $localCompanyId, array $record): array
    {
        $record = $this->forProjection($record);

        return ['columns' => $this->projection->fromSso($localCompanyId, $record) + $this->referenceColumns($localCompanyId, $record)] + $this->attributes($record);
    }

    /**
     * Local columns translated through another mirror's links (a device's
     * vehicle). None by default.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function referenceColumns(int $localCompanyId, array $record): array
    {
        return [];
    }

    /**
     * Whether the table has the on/off column the mirror writes.
     */
    protected function hasActiveColumn(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function toColumns(array $attributes): array
    {
        $columns = $attributes['columns'];

        if ($this->hasActiveColumn()) {
            $columns[$this->activeColumn()] = $attributes['is_active'];
        }

        if ($attributes['sort_order'] !== null && $this->hasSortOrder()) {
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

    protected function scoped(int $localCompanyId): Builder
    {
        return $this->projection->scope(DB::table($this->table()), $localCompanyId);
    }

    protected function insert(int $localCompanyId, int $ssoId, array $attributes): void
    {
        $now = now();

        DB::table($this->table())->insert($this->toColumns($attributes) + $this->projection->insertColumns($localCompanyId) + [
            $this->ssoIdColumn() => $ssoId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function findAdoptableFor(int $localCompanyId, array $record): ?object
    {
        $key = $this->ssoMatchKey($localCompanyId, $record);

        if ($key === null) {
            return null;
        }

        $candidates = $this->scoped($localCompanyId)
            ->whereNull($this->ssoIdColumn())
            ->get()
            ->filter(fn (object $row): bool => $this->localMatchKey($row) === $key);

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * Adds the row name CatalogMirror needs before the shared filter.
     */
    protected function snapshotRecords(array $snapshot): array
    {
        $records = $snapshot[$this->entity()] ?? null;

        if (! is_array($records)) {
            throw new InvalidArgumentException("SSO snapshot is missing the {$this->entity()} list.");
        }

        return parent::snapshotRecords([
            $this->entity() => array_map(fn (mixed $record): mixed => is_array($record) ? $this->withName($record) : $record, array_values($records)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function withName(array $record): array
    {
        if (! array_key_exists(self::SSO_NAME, $record)) {
            $record[self::SSO_NAME] = array_key_exists('name', $record) ? ['name' => $record['name']] : [];
        }

        $record['name'] = trim($this->recordName($this->forProjection($record)));

        return $record;
    }

    /**
     * The SSO row as SSO sent it: the report name withName() added is
     * replaced by SSO's own `name` field, or removed when SSO sent none.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function forProjection(array $record): array
    {
        if (! array_key_exists(self::SSO_NAME, $record)) {
            return $record;
        }

        $original = $record[self::SSO_NAME];
        unset($record[self::SSO_NAME], $record['name']);

        return is_array($original) && array_key_exists('name', $original) ? $record + ['name' => $original['name']] : $record;
    }

    /**
     * The match key of an SSO row: the projection's values for its match
     * columns, or null when the projection has none or they are all empty.
     *
     * @param  array<string, mixed>  $record
     */
    protected function ssoMatchKey(int $localCompanyId, array $record): ?string
    {
        $columns = $this->projection->matchColumns();

        return $columns === [] ? null : $this->matchKey($this->projection->fromSso($localCompanyId, $this->forProjection($record)), $columns);
    }

    protected function localMatchKey(object $row): ?string
    {
        $columns = $this->projection->matchColumns();

        return $columns === [] ? null : $this->matchKey((array) $row, $columns);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $columns
     */
    private function matchKey(array $values, array $columns): ?string
    {
        $parts = array_map(fn (string $column): string => $this->normalizeName(is_scalar($values[$column] ?? null) ? (string) $values[$column] : ''), $columns);

        return implode('', $parts) === '' ? null : implode("\x1F", $parts);
    }

    protected function confirmLink(int $localCompanyId, int $localId, int $ssoId): void
    {
        $this->scoped($localCompanyId)
            ->where('id', $localId)
            ->whereNull($this->ssoIdColumn())
            ->update([$this->ssoIdColumn() => $ssoId, self::LINK_PENDING_COLUMN => false, 'updated_at' => now()]);
    }

    private function hasSortOrder(): bool
    {
        return $this->hasSortOrder ??= Schema::hasColumn($this->table(), 'sort_order');
    }
}
