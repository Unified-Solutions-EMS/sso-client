<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Facilities;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\CatalogMirror;
use Unified\SsoClient\MasterData\Contracts\SeedsSso;
use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;

/**
 * Mirrors SSO's facilities (an agency list of destinations: hospitals,
 * nursing homes and other receiving facilities, NEMSIS dFacility) into the
 * app's own facility table. The table, how a row belongs to a company, its
 * columns and its child rows come from the bound FacilityProjection; this
 * class owns what every app shares:
 *
 * - linking by SSO id (`sso_facility_id`), adopting an unlinked local row the
 *   way SSO's import matches: by facility code (ignoring case) first, then
 *   by exact name (ignoring case) with the same ZIP code, exactly one
 *   candidate each. Adoption by a delivery is pending until the push,
 *   --link-by-name or a resync confirms it (CatalogMirror). No name-only
 *   adoption: two "Mercy Hospital"s in different towns are two facilities;
 * - turn off, never delete: SSO turning a facility off, or a resync no
 *   longer listing it, sets the active flag false. CAD incidents, Billing
 *   claims, CloudPCR dispositions and mobile bundles hold facility ids;
 * - the batch event: SSO's "Add from state list" sends
 *   `facility.batch_updated` with up to 200 rows, applied like a resync page
 *   (each row upserted, nothing turned off);
 * - the push (SeedsSso): the app's rows go up once, before the flag is on;
 *   SSO matches by its own link, then code, then name + ZIP, never
 *   overwrites a match, fills only its empty fields, and answers with the
 *   mapping that links them here;
 * - this app's own kept values: what the push sent that SSO's rules refused
 *   (a bad NPI, an address with no ZIP) comes back in every row as
 *   `pending: {field: {app_slug: value}}` until the agency fixes or
 *   dismisses it. withOwnPending() puts this app's value back over SSO's
 *   before the row is written, so a resync or delivery never blanks what
 *   the app sent. Another app's kept values are never applied here.
 */
class FacilityMirror extends CatalogMirror implements SeedsSso
{
    public const SSO_ID_COLUMN = 'sso_facility_id';

    /** SSO's facility name limit (dFacility.02); longer local names are cut on push. */
    public const MAX_NAME_LENGTH = 100;

    /** Fields SSO can keep a refused value for, as named in `pending`. */
    public const PENDING_FIELDS = ['type', 'location_code', 'designations', 'npis', 'fax', 'address', 'gps', 'phones'];

    private const ADDRESS_KEYS = ['suite', 'street', 'street2', 'city_gnis', 'city_name', 'state', 'zip', 'county', 'country'];

    public function __construct(
        LocalTenantResolver $tenants,
        MasterDataClient $client,
        protected readonly FacilityProjection $projection,
    ) {
        parent::__construct($tenants, $client);
    }

    public function entity(): string
    {
        return 'facilities';
    }

    public static function events(): array
    {
        return [
            'facility.created',
            'facility.updated',
            'facility.deactivated',
            'facility.batch_updated',
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
        return 'facility';
    }

    public function isInstalled(): bool
    {
        return Schema::hasTable($this->table())
            && Schema::hasColumns($this->table(), [$this->ssoIdColumn(), $this->activeColumn(), 'sort_order', self::SSO_UPDATED_AT_COLUMN, self::LINK_PENDING_COLUMN]);
    }

    public function applyWebhook(string $event, int $localCompanyId, array $payload): array
    {
        return match ($event) {
            'facility.created', 'facility.updated', 'facility.deactivated' => $this->applyUpsertWebhook($event, $localCompanyId, (array) ($payload['facility'] ?? [])),
            'facility.batch_updated' => $this->applyBatch($localCompanyId, (array) ($payload['facilities'] ?? [])),
            default => ['status' => 'ignored', 'reason' => 'unknown_event'],
        };
    }

    /**
     * Writes the row, then lets the projection write what lives outside it
     * (phones) once the row is SSO's: created or updated, and on the
     * authoritative resync path also linked or unchanged, because the row
     * comparison cannot see child rows. A pending adoption leaves the
     * agency's data alone.
     */
    public function upsert(int $localCompanyId, array $record, bool $delivery = false): string
    {
        $record = self::withOwnPending($record, (string) config('sso.app_slug'));
        $result = parent::upsert($localCompanyId, $record, $delivery);

        if ($result === 'created' || $result === 'updated' || (! $delivery && in_array($result, ['linked', 'unchanged'], true))) {
            $row = $this->findLinked($localCompanyId, (int) $record['id']);

            if ($row !== null) {
                $this->projection->written($localCompanyId, (int) $row->id, $record);
            }
        }

        return $result;
    }

    /**
     * The SSO row with this app's kept values in place of SSO's: the field
     * SSO could not store reads as what this app sent, and refused phones
     * are added after SSO's. Rows with nothing kept for the app are
     * returned unchanged.
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

        foreach (self::PENDING_FIELDS as $field) {
            if (! is_array($pending[$field] ?? null) || ! array_key_exists($appSlug, $pending[$field])) {
                continue;
            }

            $value = $pending[$field][$appSlug];

            match ($field) {
                'address' => $record['address'] = array_replace(array_fill_keys(self::ADDRESS_KEYS, null), array_intersect_key(is_array($value) ? $value : [], array_flip(self::ADDRESS_KEYS))),
                'gps' => [$record['latitude'], $record['longitude']] = [is_array($value) ? ($value['latitude'] ?? null) : null, is_array($value) ? ($value['longitude'] ?? null) : null],
                'phones' => $record['phones'] = [...array_values((array) ($record['phones'] ?? [])), ...array_values(array_filter((array) $value, 'is_array'))],
                default => $record[$field] = $value,
            };
        }

        return $record;
    }

    public function resync(int $localCompanyId, array $snapshot): array
    {
        return $this->reconcileCatalog($localCompanyId, $this->snapshotRecords($snapshot));
    }

    /**
     * Cutover helper: links unlinked local rows by facility code first (when
     * the code is unique on both sides), then by name. Never creates, merges
     * or deletes.
     */
    public function linkByName(int $localCompanyId, array $snapshot): array
    {
        $byCode = $this->linkByCode($localCompanyId, $this->snapshotRecords($snapshot));
        $result = parent::linkByName($localCompanyId, $snapshot);
        $result['linked'] = [...$byCode, ...$result['linked']];

        return $result;
    }

    /**
     * The company's local facilities in the shape of SSO's
     * POST .../facilities/import. Names longer than SSO's 100 characters are
     * cut and reported in truncated_names.
     */
    public function buildImportPayload(int $localCompanyId): array
    {
        $columns = [$this->activeColumn().' as mirror_is_active', ...$this->projection->pushColumns()];

        ['rows' => $rows, 'truncated_names' => $truncatedNames] = $this->rowsForPush($localCompanyId, array_values(array_unique($columns)));

        $facilities = array_map(fn (object $row): array => [
            'local_id' => (int) $row->id,
            'name' => (string) $row->name,
            'is_active' => (bool) $row->mirror_is_active,
        ] + $this->projection->toImport($localCompanyId, $row), $rows);

        return [
            'payload' => [
                'app_slug' => (string) config('sso.app_slug'),
                'facilities' => $facilities,
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
            'columns' => [],
        ];
    }

    protected function attributesFor(int $localCompanyId, array $record): array
    {
        return ['columns' => $this->projection->toColumns($localCompanyId, $record)] + $this->attributes($record);
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
        $unlinked = $this->scoped($localCompanyId)->whereNull($this->ssoIdColumn())->get();
        $code = $this->normalizeName((string) ($record['location_code'] ?? ''));
        $codeColumn = $this->projection->codeColumn();

        if ($code !== '' && $codeColumn !== null) {
            $byCode = $unlinked->filter(fn (object $row): bool => $this->normalizeName((string) $row->{$codeColumn}) === $code);

            if ($byCode->count() === 1) {
                return $byCode->first();
            }
        }

        $name = $this->normalizeName((string) ($record['name'] ?? ''));
        $zip = self::normalizeZip($record['address']['zip'] ?? null);
        $zipColumn = $this->projection->zipColumn();

        if ($name === '' || $zip === null || $zipColumn === null) {
            return null;
        }

        $byNameAndZip = $unlinked->filter(fn (object $row): bool => $this->normalizeName((string) $row->{$this->nameColumn()}) === $name
            && self::normalizeZip($row->{$zipColumn}) === $zip);

        return $byNameAndZip->count() === 1 ? $byNameAndZip->first() : null;
    }

    /**
     * SSO's "Add from state list" batch: each row upserted like a delivery
     * (stale and pending guards apply), nothing turned off.
     *
     * @param  array<int|string, mixed>  $records
     * @return array<string, mixed>
     */
    private function applyBatch(int $localCompanyId, array $records): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'linked' => 0, 'unchanged' => 0, 'stale' => 0, 'pending' => 0];

        foreach ($records as $record) {
            if (! is_array($record) || (int) ($record['id'] ?? 0) <= 0 || trim((string) ($record['name'] ?? '')) === '') {
                continue;
            }

            $counts[$this->upsert($localCompanyId, $record, delivery: true)]++;
        }

        return ['status' => 'ok', 'counts' => $counts];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array{local_id: int, sso_id: int, name: string}>
     */
    private function linkByCode(int $localCompanyId, array $records): array
    {
        $column = $this->projection->codeColumn();

        if ($column === null) {
            return [];
        }

        $linked = $this->linkedMap($localCompanyId);
        $ssoByCode = [];

        foreach ($records as $record) {
            $code = $this->normalizeName((string) ($record['location_code'] ?? ''));

            if ($code !== '' && ! isset($linked[(int) $record['id']])) {
                $ssoByCode[$code][] = (int) $record['id'];
            }
        }

        $localByCode = [];

        foreach ($this->scoped($localCompanyId)->whereNull($this->ssoIdColumn())->whereNotNull($column)->orderBy('id')->get(['id', $this->nameColumn().' as name', $column.' as code']) as $row) {
            $code = $this->normalizeName((string) $row->code);

            if ($code !== '') {
                $localByCode[$code][] = $row;
            }
        }

        $result = [];

        foreach ($ssoByCode as $code => $ssoIds) {
            $rows = $localByCode[$code] ?? [];

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

    private static function normalizeZip(mixed $zip): ?string
    {
        if (! is_scalar($zip)) {
            return null;
        }

        $zip = strtoupper(trim((string) $zip));

        return $zip === '' ? null : $zip;
    }
}
