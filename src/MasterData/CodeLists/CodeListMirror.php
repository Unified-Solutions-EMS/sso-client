<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\CodeLists;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\CatalogMirror;
use Unified\SsoClient\MasterData\Contracts\ContinuesPush;
use Unified\SsoClient\MasterData\Contracts\SeedsSso;
use Unified\SsoClient\MasterData\Exceptions\MasterDataSyncException;
use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;

/**
 * The shared engine of the two coded agency lists, medications and
 * procedures (SSO decision S19: two lists, one engine). One mirror row per
 * code in a package-owned table (`sso_medications` / `sso_procedures`),
 * linked by SSO id, with the code kept exactly as SSO sends it (never
 * normalised or swapped), the certification levels permitted, "Only report
 * in" states, and the agency's name for it. The bound CodeListProjection
 * carries the list into the app's own structure and back for the push.
 *
 * What it shares with every catalog (CatalogMirror): the SSO link, the
 * `sso_updated_at` stale guard, pending adoption (here by code), turn off
 * never delete. What is particular to code lists:
 *
 * - **A row without levels is still on the list.** For a medication that is
 *   "stock only" (Drug Tracking keeps it, crews cannot chart it);
 *   `is_chartable` says which. Projections decide what to show.
 * - **The projection runs after every change.** A webhook that created or
 *   updated a row, a batch, and every resync end with one
 *   `project($company, $items)` call with the company's whole list.
 * - **Batches.** SSO's state-list import and a finished review send
 *   `{entity}.batch_updated` with up to 200 rows, applied like a resync page
 *   (each row upserted, nothing turned off).
 * - **The push may wait for the agency.** The first app to send its list to
 *   an empty agency list has its codes added at once (`created`). A later
 *   app's codes that SSO does not have yet wait in the agency's review
 *   (`in_review`, `review_id`). When the agency finishes it, SSO sends
 *   `{entity}.review_completed` with `{source: [{local_id, sso_id|null}]}`;
 *   the mirror applies this app's part even while the entity is still off
 *   (ContinuesPush), because the push runs before the flag is turned on. A
 *   null sso_id is a row the agency left out. `sso:push-master-data
 *   {entity} --review=ID` pulls the same mapping if the webhook was missed.
 * - **Linking needs no local row.** The app's list has no row per code, so a
 *   mapped entry links the mirror row with that code, or creates the mirror
 *   row from the app's entry (with no sso_updated_at, so SSO's next delivery
 *   or the resync replaces it). The projection is not called: until the
 *   flag is on, the app's own editor still owns its list.
 */
abstract class CodeListMirror extends CatalogMirror implements ContinuesPush, SeedsSso
{
    /** SSO's import takes at most this many rows per push. */
    public const MAX_ROWS = 500;

    /** SSO's limit on an incoming label; longer ones are cut on push. */
    public const MAX_LABEL_LENGTH = 255;

    /** Columns holding lists, stored as JSON text so stored values compare as sent. */
    protected const LIST_COLUMNS = ['levels', 'member_levels', 'only_states'];

    public function __construct(
        LocalTenantResolver $tenants,
        MasterDataClient $client,
        protected readonly CodeListProjection $projection,
    ) {
        parent::__construct($tenants, $client);
    }

    /** `medication` or `procedure`: the event prefix and the webhook record key. */
    abstract protected static function prefix(): string;

    /**
     * The list's own columns for an SSO record (medications: ingredient,
     * controlled, schedule, blood product).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function extraColumns(array $record): array
    {
        return [];
    }

    /**
     * The list's own fields for one entry of the push, from the app's entry.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function extraPushFields(array $item): array
    {
        return [];
    }

    /**
     * The list's own fields of a mirror row, decoded, for project() and
     * readers.
     *
     * @return array<string, mixed>
     */
    protected function extraItemFields(object $row): array
    {
        return [];
    }

    public static function events(): array
    {
        return array_map(fn (string $action): string => static::prefix().'.'.$action, ['created', 'updated', 'deactivated', 'batch_updated', 'review_completed']);
    }

    public static function pushEvents(): array
    {
        return [static::prefix().'.review_completed'];
    }

    public function maxNameLength(): int
    {
        return self::MAX_LABEL_LENGTH;
    }

    protected function recordKey(): string
    {
        return static::prefix();
    }

    public function isInstalled(): bool
    {
        return Schema::hasTable($this->table())
            && Schema::hasColumns($this->table(), [$this->ssoIdColumn(), 'code', 'levels', $this->activeColumn(), 'sort_order', self::SSO_UPDATED_AT_COLUMN, self::LINK_PENDING_COLUMN]);
    }

    public function applyWebhook(string $event, int $localCompanyId, array $payload): array
    {
        $prefix = static::prefix();

        return match ($event) {
            "{$prefix}.created", "{$prefix}.updated", "{$prefix}.deactivated" => $this->applyRow($event, $localCompanyId, (array) ($payload[$prefix] ?? [])),
            "{$prefix}.batch_updated" => $this->applyBatch($localCompanyId, (array) ($payload[$this->entity()] ?? [])),
            "{$prefix}.review_completed" => $this->applyReviewWebhook($localCompanyId, (array) ($payload['review'] ?? [])),
            default => ['status' => 'ignored', 'reason' => 'unknown_event'],
        };
    }

    /**
     * @return array<string, int>
     */
    public function resync(int $localCompanyId, array $snapshot): array
    {
        $counts = $this->reconcileCatalog($localCompanyId, $this->snapshotRecords($snapshot));
        $this->project($localCompanyId);

        return $counts;
    }

    /**
     * Cutover helper: links unlinked mirror rows by code (unique on both
     * sides), then by name. Never creates, merges or deletes.
     */
    public function linkByName(int $localCompanyId, array $snapshot): array
    {
        $byCode = $this->linkByCode($localCompanyId, $this->snapshotRecords($snapshot));
        $result = parent::linkByName($localCompanyId, $snapshot);
        $result['linked'] = [...$byCode, ...$result['linked']];

        return $result;
    }

    /**
     * The app's list in the shape of SSO's POST .../{entity}/import, one
     * entry per key (entries the projection repeats are merged: levels and
     * states joined, controlled if any says so). SSO takes the whole list in
     * one request, because a second request from the same app replaces the
     * first in the agency's review.
     *
     * @throws MasterDataSyncException when the list is longer than SSO takes
     */
    public function buildImportPayload(int $localCompanyId): array
    {
        $entries = [];
        $truncated = [];

        foreach ($this->projection->localItems($localCompanyId) as $item) {
            $code = self::text($item['code'] ?? null);
            $label = self::text($item['label'] ?? null) ?? $code;

            if ($label === null) {
                continue;
            }

            $key = self::text($item['key'] ?? null) ?? $code ?? 'label:'.mb_strtolower($label);

            if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
                $truncated[] = ['local_id' => $key, 'name' => $label];
                $label = mb_substr($label, 0, self::MAX_LABEL_LENGTH);
            }

            $entry = array_filter([
                'local_id' => $key,
                'code' => $code,
                'label' => $label,
                'levels' => self::list($item['levels'] ?? []),
                'is_active' => array_key_exists('is_active', $item) ? (bool) $item['is_active'] : null,
                'only_states' => self::list($item['only_states'] ?? []) ?: null,
            ] + $this->extraPushFields($item), fn (mixed $value): bool => $value !== null);

            $entries[$key] = isset($entries[$key]) ? $this->merge($entries[$key], $entry) : $entry;
        }

        if (count($entries) > self::MAX_ROWS) {
            throw new MasterDataSyncException(sprintf('This list has %d %s; SSO takes at most %d in one push. Nothing was sent.', count($entries), $this->entity(), self::MAX_ROWS));
        }

        return [
            'payload' => [
                'app_slug' => (string) config('sso.app_slug'),
                $this->entity() => array_values($entries),
            ],
            'skipped_users' => 0,
            'truncated_names' => $truncated,
        ];
    }

    /**
     * Links the app's entries to the SSO ids in the import mapping
     * (`{key: {sso_id, updated_at}}`). See the class doc: no local row is
     * needed, and the projection is not called.
     */
    public function applyImportMapping(int $localCompanyId, array $mapping): array
    {
        $links = [];
        foreach ($mapping as $key => $entry) {
            $ssoId = (int) (is_array($entry) ? ($entry['sso_id'] ?? 0) : $entry);

            if ($ssoId > 0) {
                $links[(string) $key] = $ssoId;
            }
        }

        return $this->linkLocalKeys($localCompanyId, $links);
    }

    /**
     * Links this app's part of a finished review (`[{local_id, sso_id|null}]`).
     * A null sso_id is an entry the agency left out of the list: reported,
     * not linked.
     *
     * @param  array<int|string, mixed>  $entries
     * @return array{applied: int, collisions: list<array{local_id: int|string, sso_id: int, reason: string}>, left_out: list<string>}
     */
    public function applyReviewMapping(int $localCompanyId, array $entries): array
    {
        $links = [];
        $leftOut = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_scalar($entry['local_id'] ?? null)) {
                continue;
            }

            $ssoId = (int) ($entry['sso_id'] ?? 0);
            $ssoId > 0 ? $links[(string) $entry['local_id']] = $ssoId : $leftOut[] = (string) $entry['local_id'];
        }

        return $this->linkLocalKeys($localCompanyId, $links) + ['left_out' => $leftOut];
    }

    /**
     * The company's mirror rows in SSO's order, decoded (the shape
     * CodeListProjection::project() documents).
     *
     * @return list<array<string, mixed>>
     */
    public function items(int $localCompanyId): array
    {
        return $this->scoped($localCompanyId)
            ->whereNotNull($this->ssoIdColumn())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'sso_id' => (int) $row->{$this->ssoIdColumn()},
                'code' => $row->code,
                'code_system' => $row->code_system,
                'code_type' => $row->code_type,
                'name' => (string) $row->name,
                'display_name' => $row->display_name,
                'label' => (string) ($row->label ?? $row->name),
                'levels' => self::decode($row->levels),
                'member_levels' => self::decode($row->member_levels),
                'is_chartable' => self::decode($row->levels) !== [],
                'only_states' => self::decode($row->only_states),
                'is_active' => (bool) $row->{$this->activeColumn()},
                'sort_order' => (int) $row->sort_order,
            ] + $this->extraItemFields($row))
            ->values()
            ->all();
    }

    /**
     * Hand the company's whole list to the projection.
     */
    public function project(int $localCompanyId): void
    {
        $this->projection->project($localCompanyId, $this->items($localCompanyId));
    }

    protected function attributes(array $record): array
    {
        $levels = self::list($record['levels'] ?? []);

        return [
            'is_active' => array_key_exists('is_active', $record) ? (bool) $record['is_active'] : true,
            'sort_order' => isset($record['sort_order']) ? max(0, (int) $record['sort_order']) : null,
            'sso_updated_at' => $this->normalizeTimestamp($record['updated_at'] ?? null),
            'columns' => [
                'code' => self::text($record['code'] ?? null),
                'code_system' => self::text($record['code_system'] ?? null),
                'code_type' => self::text($record['code_type'] ?? null),
                'display_name' => self::text($record['display_name'] ?? null),
                'label' => self::text($record['label'] ?? null) ?? self::text($record['display_name'] ?? null) ?? trim((string) ($record['name'] ?? '')),
                'levels' => self::encode($levels),
                'member_levels' => self::encode(self::list($record['member_levels'] ?? [])),
                'only_states' => self::encode(self::list($record['only_states'] ?? [])),
                'source' => self::text($record['source'] ?? null),
            ] + $this->extraColumns($record),
        ];
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

    /**
     * An unlinked mirror row with the same code (SSO's own match key).
     */
    protected function findAdoptableFor(int $localCompanyId, array $record): ?object
    {
        $code = self::text($record['code'] ?? null);

        if ($code === null) {
            return null;
        }

        $candidates = $this->scoped($localCompanyId)->whereNull($this->ssoIdColumn())->where('code', $code)->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function applyRow(string $event, int $localCompanyId, array $record): array
    {
        $result = $this->applyUpsertWebhook($event, $localCompanyId, $record);

        if (in_array($result['result'] ?? null, ['created', 'updated'], true)) {
            $this->project($localCompanyId);
        }

        return $result;
    }

    /**
     * The shared batch, then the projection once for the whole batch.
     *
     * @param  array<int|string, mixed>  $records
     * @return array{status: string, counts: array<string, int>}
     */
    #[\Override]
    protected function applyBatch(int $localCompanyId, array $records): array
    {
        $result = parent::applyBatch($localCompanyId, $records);

        if ($result['counts']['created'] + $result['counts']['updated'] > 0) {
            $this->project($localCompanyId);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>
     */
    private function applyReviewWebhook(int $localCompanyId, array $review): array
    {
        $slug = (string) config('sso.app_slug');
        $entries = $review['mapping'][$slug] ?? null;

        if (! is_array($entries)) {
            return ['status' => 'ok', 'result' => 'not_in_review'];
        }

        $result = DB::transaction(fn (): array => $this->applyReviewMapping($localCompanyId, $entries));

        if ($result['collisions'] !== [] || $result['left_out'] !== []) {
            Log::info("SSO master data: {$this->entity()} review applied with entries not linked", [
                'company_id' => $localCompanyId,
                'review_id' => $review['id'] ?? null,
                'collisions' => $result['collisions'],
                'left_out' => $result['left_out'],
            ]);
        }

        return ['status' => 'ok', 'result' => 'linked', 'applied' => $result['applied'], 'collisions' => count($result['collisions']), 'left_out' => count($result['left_out'])];
    }

    /**
     * @param  array<string, int>  $links  SSO id keyed by the app's entry key
     * @return array{applied: int, collisions: list<array{local_id: int|string, sso_id: int, reason: string}>}
     */
    private function linkLocalKeys(int $localCompanyId, array $links): array
    {
        $items = [];
        foreach ($this->projection->localItems($localCompanyId) as $item) {
            $code = self::text($item['code'] ?? null);
            $label = self::text($item['label'] ?? null) ?? $code;

            if ($label !== null) {
                $items[self::text($item['key'] ?? null) ?? $code ?? 'label:'.mb_strtolower($label)] ??= $item + ['label' => $label];
            }
        }

        $applied = 0;
        $collisions = [];

        foreach ($links as $key => $ssoId) {
            $key = (string) $key;

            if ($this->findLinked($localCompanyId, $ssoId) !== null) {
                $applied++;

                continue;
            }

            $item = $items[$key] ?? null;

            if ($item === null) {
                $collisions[] = ['local_id' => $key, 'sso_id' => $ssoId, 'reason' => 'local_entry_missing'];

                continue;
            }

            $code = self::text($item['code'] ?? null);
            $sameCode = $code === null ? collect() : $this->scoped($localCompanyId)->where('code', $code)->get();
            $unlinked = $sameCode->first(fn (object $row): bool => $row->{$this->ssoIdColumn()} === null);

            if ($unlinked !== null) {
                $this->scoped($localCompanyId)->where('id', $unlinked->id)->update([$this->ssoIdColumn() => $ssoId, self::LINK_PENDING_COLUMN => false, 'updated_at' => now()]);
                $applied++;

                continue;
            }

            if ($sameCode->isNotEmpty()) {
                $collisions[] = ['local_id' => $key, 'sso_id' => $ssoId, 'reason' => 'code_linked_to_another_sso_id'];

                continue;
            }

            $now = now();
            DB::table($this->table())->insert([
                'company_id' => $localCompanyId,
                $this->ssoIdColumn() => $ssoId,
                'code' => $code,
                'name' => mb_substr((string) $item['label'], 0, self::MAX_LABEL_LENGTH),
                'label' => mb_substr((string) $item['label'], 0, self::MAX_LABEL_LENGTH),
                'levels' => self::encode(self::list($item['levels'] ?? [])),
                'member_levels' => self::encode([]),
                'only_states' => self::encode(self::list($item['only_states'] ?? [])),
                'source' => 'import',
                $this->activeColumn() => array_key_exists('is_active', $item) ? (bool) $item['is_active'] : true,
                self::LINK_PENDING_COLUMN => false,
                'created_at' => $now,
                'updated_at' => $now,
            ] + $this->localExtraColumns($item));
            $applied++;
        }

        return ['applied' => $applied, 'collisions' => $collisions];
    }

    /**
     * The list's own columns for a mirror row created from the app's entry.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function localExtraColumns(array $item): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     * @return array<string, mixed>
     */
    protected function merge(array $first, array $second): array
    {
        $first['levels'] = array_values(array_unique([...$first['levels'], ...$second['levels']]));

        if (! isset($first['only_states']) || ! isset($second['only_states'])) {
            unset($first['only_states']);
        } else {
            $first['only_states'] = array_values(array_unique([...$first['only_states'], ...$second['only_states']]));
        }

        if (($second['is_active'] ?? true) === true) {
            unset($first['is_active']);
        }

        return $first;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array{local_id: int, sso_id: int, name: string}>
     */
    private function linkByCode(int $localCompanyId, array $records): array
    {
        $linked = $this->linkedMap($localCompanyId);
        $ssoByCode = [];

        foreach ($records as $record) {
            $code = self::text($record['code'] ?? null);

            if ($code !== null && ! isset($linked[(int) $record['id']])) {
                $ssoByCode[$code][] = (int) $record['id'];
            }
        }

        $localByCode = $this->scoped($localCompanyId)->whereNull($this->ssoIdColumn())->whereNotNull('code')->orderBy('id')->get(['id', 'name', 'code'])->groupBy('code');
        $result = [];

        foreach ($ssoByCode as $code => $ssoIds) {
            $rows = $localByCode->get((string) $code);

            if (count($ssoIds) !== 1 || $rows === null || $rows->count() !== 1) {
                continue;
            }

            $row = $rows->first();
            $this->scoped($localCompanyId)->where('id', $row->id)->whereNull($this->ssoIdColumn())->update([$this->ssoIdColumn() => $ssoIds[0], self::LINK_PENDING_COLUMN => false, 'updated_at' => now()]);
            $result[] = ['local_id' => (int) $row->id, 'sso_id' => $ssoIds[0], 'name' => (string) $row->name];
        }

        return $result;
    }

    protected static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @return list<string>
     */
    protected static function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(fn (mixed $entry): ?string => self::text($entry), $value), fn (?string $entry): bool => $entry !== null)));
    }

    /**
     * @param  list<string>  $values
     */
    protected static function encode(array $values): string
    {
        return (string) json_encode(array_values($values));
    }

    /**
     * @return list<string>
     */
    protected static function decode(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return self::list($decoded);
    }
}
