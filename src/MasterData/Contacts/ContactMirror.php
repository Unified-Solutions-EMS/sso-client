<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Contacts;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\CatalogMirror;
use Unified\SsoClient\MasterData\Contracts\SeedsSso;
use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;

/**
 * Mirrors SSO's agency contacts (the agency's director, medical director,
 * billing contact and the rest; NEMSIS dContact) into the table the bound
 * ContactProjection names (the package's `sso_agency_contacts` by default).
 *
 * - Linking by SSO id (`sso_agency_contact_id`). An SSO contact with no
 *   linked row adopts an unlinked local row of the same type, last name and
 *   first name (ignoring case), exactly one candidate. Never by name alone:
 *   the agency's director and its billing contact can be the same person
 *   with two rows, and two "John Smith"s of different types are two
 *   contacts. Adoption by a delivery is pending until the push,
 *   --link-by-name or a resync confirms it (CatalogMirror).
 * - Turn off, never delete: SSO turning a contact off, or a resync no longer
 *   listing it, sets the active flag false.
 * - `agency_contact.batch_updated` `{ids, agency_contacts: [...]}` is
 *   applied like a resync page (each row upserted, nothing turned off).
 * - The push (SeedsSso): the app's contacts go up once, before the flag is
 *   on; SSO matches by name + type, never overwrites a match, and answers
 *   with the mapping that links them here.
 *
 * The entity key is `agency_contacts` everywhere: the config flag
 * (`sso.master_data.agency_contacts`), the command argument, the SSO path
 * (`/api/internal/companies/{id}/agency_contacts`) and the list key of the
 * snapshot, batch and import bodies.
 *
 * SSO records carry first and last name; CatalogMirror keys on `name`, so a
 * record without one gets "First Last".
 */
class ContactMirror extends CatalogMirror implements SeedsSso
{
    public const SSO_ID_COLUMN = 'sso_agency_contact_id';

    /** Longest display name kept; SSO limits the name parts themselves. */
    public const MAX_NAME_LENGTH = 255;

    /**
     * Platform contact types => NEMSIS dContact.01. `billing` is the
     * platform's own and reports as Other.
     */
    public const TYPES = [
        'director' => '1101003',
        'assistant_director' => '1101005',
        'medical_director' => '1101011',
        'assistant_medical_director' => '1101007',
        'administrative_assistant' => '1101001',
        'it_data' => '1101009',
        'qi_pi' => '1101013',
        'training' => '1101015',
        'billing' => '1101017',
        'other' => '1101017',
    ];

    private ?bool $hasCompanyId = null;

    public function __construct(
        LocalTenantResolver $tenants,
        MasterDataClient $client,
        protected readonly ContactProjection $projection,
    ) {
        parent::__construct($tenants, $client);
    }

    public function entity(): string
    {
        return 'agency_contacts';
    }

    public static function events(): array
    {
        return [
            'agency_contact.created',
            'agency_contact.updated',
            'agency_contact.deactivated',
            'agency_contact.batch_updated',
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
        return 'agency_contact';
    }

    public function isInstalled(): bool
    {
        return Schema::hasTable($this->table())
            && Schema::hasColumns($this->table(), [$this->ssoIdColumn(), $this->activeColumn(), 'sort_order', self::SSO_UPDATED_AT_COLUMN, self::LINK_PENDING_COLUMN]);
    }

    public function applyWebhook(string $event, int $localCompanyId, array $payload): array
    {
        return match ($event) {
            'agency_contact.created', 'agency_contact.updated', 'agency_contact.deactivated' => $this->applyUpsertWebhook($event, $localCompanyId, self::withName((array) ($payload['agency_contact'] ?? []))),
            'agency_contact.batch_updated' => $this->applyBatch($localCompanyId, (array) ($payload['agency_contacts'] ?? [])),
            default => ['status' => 'ignored', 'reason' => 'unknown_event'],
        };
    }

    public function upsert(int $localCompanyId, array $record, bool $delivery = false): string
    {
        return parent::upsert($localCompanyId, self::withName($record), $delivery);
    }

    public function resync(int $localCompanyId, array $snapshot): array
    {
        return $this->reconcileCatalog($localCompanyId, $this->snapshotRecords($snapshot));
    }

    /**
     * Cutover helper: links unlinked local rows to SSO contacts of the same
     * type, last name and first name, when the match is unique on both
     * sides. Never creates, merges or deletes.
     */
    public function linkByName(int $localCompanyId, array $snapshot): array
    {
        $linked = $this->linkedMap($localCompanyId);
        $ssoByKey = [];
        $unkeyedSso = [];

        foreach ($this->snapshotRecords($snapshot) as $record) {
            if (isset($linked[(int) $record['id']])) {
                continue;
            }

            $key = $this->recordKeyFor($localCompanyId, $record);
            $entry = ['id' => (int) $record['id'], 'name' => (string) $record['name']];

            if ($key === null) {
                $unkeyedSso[] = $entry;
            } else {
                $ssoByKey[$key][] = $entry;
            }
        }

        $localByKey = [];
        $unkeyedLocal = [];

        foreach ($this->scoped($localCompanyId)->whereNull($this->ssoIdColumn())->orderBy('id')->get() as $row) {
            $key = $this->rowKey($row);
            $entry = ['id' => (int) $row->id, 'name' => $this->displayName($row)];

            if ($key === null) {
                $unkeyedLocal[] = $entry;
            } else {
                $localByKey[$key][] = $entry;
            }
        }

        $result = ['linked' => [], 'ambiguous' => [], 'unmatched_local' => $unkeyedLocal, 'unmatched_sso' => $unkeyedSso];

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

            $this->scoped($localCompanyId)
                ->where($this->table().'.id', $localRows[0]['id'])
                ->whereNull($this->ssoIdColumn())
                ->update([$this->ssoIdColumn() => $ssoRows[0]['id'], self::LINK_PENDING_COLUMN => false, 'updated_at' => now()]);

            $result['linked'][] = ['local_id' => $localRows[0]['id'], 'sso_id' => $ssoRows[0]['id'], 'name' => $localRows[0]['name']];
        }

        foreach ($localByKey as $localRows) {
            array_push($result['unmatched_local'], ...$localRows);
        }

        return $result;
    }

    /**
     * The company's local contacts in the shape of SSO's
     * POST .../agency_contacts/import.
     */
    public function buildImportPayload(int $localCompanyId): array
    {
        $columns = array_values(array_unique([
            $this->table().'.id',
            $this->table().'.'.$this->activeColumn().' as mirror_is_active',
            ...array_map(fn (string $column): string => $this->table().'.'.$column, $this->projection->pushColumns()),
        ]));

        $contacts = $this->scoped($localCompanyId)
            ->orderBy($this->table().'.id')
            ->get($columns)
            ->map(fn (object $row): array => [
                'local_id' => (int) $row->id,
                'is_active' => (bool) $row->mirror_is_active,
            ] + $this->projection->toImport($localCompanyId, $row))
            ->values()
            ->all();

        return [
            'payload' => [
                'app_slug' => (string) config('sso.app_slug'),
                'agency_contacts' => $contacts,
            ],
            'skipped_users' => 0,
            'truncated_names' => [],
        ];
    }

    /**
     * The record with `name` set: SSO's when it sends one, else "First Last".
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function withName(array $record): array
    {
        if (trim((string) ($record['name'] ?? '')) === '') {
            $record['name'] = trim(trim((string) ($record['first_name'] ?? '')).' '.trim((string) ($record['last_name'] ?? '')));
        }

        $record['name'] = mb_substr((string) $record['name'], 0, self::MAX_NAME_LENGTH);

        return $record;
    }

    protected function snapshotRecords(array $snapshot): array
    {
        if (is_array($snapshot[$this->entity()] ?? null)) {
            $snapshot[$this->entity()] = array_map(
                fn (mixed $record): mixed => is_array($record) ? self::withName($record) : $record,
                $snapshot[$this->entity()],
            );
        }

        return parent::snapshotRecords($snapshot);
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
     * The projection writes the name columns itself (an app may keep only
     * the name parts), so `name` is not written here.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function toColumns(array $attributes): array
    {
        $columns = [$this->activeColumn() => $attributes['is_active']] + $attributes['columns'];

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

    /**
     * The rows the migration's unique index spans: the company's rows when
     * the table has a company_id, else the whole table (see FacilityMirror).
     */
    protected function linkHolders(int $localCompanyId): Builder
    {
        $this->hasCompanyId ??= Schema::hasColumn($this->table(), 'company_id');

        return $this->hasCompanyId
            ? DB::table($this->table())->where('company_id', $localCompanyId)
            : DB::table($this->table());
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
        $key = $this->recordKeyFor($localCompanyId, $record);

        if ($key === null) {
            return null;
        }

        $candidates = $this->scoped($localCompanyId)
            ->whereNull($this->ssoIdColumn())
            ->get()
            ->filter(fn (object $row): bool => $this->rowKey($row) === $key);

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function recordKeyFor(int $localCompanyId, array $record): ?string
    {
        $columns = $this->projection->toColumns($localCompanyId, $record);

        return $this->adoptionKey(
            $columns[$this->projection->typeColumn()] ?? null,
            $columns[$this->projection->lastNameColumn()] ?? null,
            $columns[$this->projection->firstNameColumn()] ?? null,
        );
    }

    private function rowKey(object $row): ?string
    {
        return $this->adoptionKey(
            $row->{$this->projection->typeColumn()} ?? null,
            $row->{$this->projection->lastNameColumn()} ?? null,
            $row->{$this->projection->firstNameColumn()} ?? null,
        );
    }

    /**
     * Type, last name and first name, ignoring case; null when any is
     * missing, so a contact is never adopted by name alone.
     */
    private function adoptionKey(mixed $type, mixed $lastName, mixed $firstName): ?string
    {
        $parts = array_map(fn (mixed $part): string => is_scalar($part) ? $this->normalizeName((string) $part) : '', [$type, $lastName, $firstName]);

        return in_array('', $parts, true) ? null : implode("\u{1F}", $parts);
    }

    private function displayName(object $row): string
    {
        $name = trim((string) ($row->{$this->nameColumn()} ?? ''));

        return $name !== '' ? $name : trim(((string) ($row->{$this->projection->firstNameColumn()} ?? '')).' '.((string) ($row->{$this->projection->lastNameColumn()} ?? '')));
    }
}
