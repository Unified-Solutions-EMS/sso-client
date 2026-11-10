<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\OtherAgencies;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;
use Unified\SsoClient\MasterData\ProjectedMirror;

/**
 * Mirrors SSO's "Other agencies" list (the agencies an agency's crews work
 * with: mutual aid, intercepts, the fire department on scene) into the
 * app's table through the bound OtherAgencyProjection. ProjectedMirror has
 * the rules: link by `sso_other_agency_id`, adopt an unlinked local row by
 * name and state (SSO's import key, ignoring case), stale guard, turn off
 * never delete, `other_agency.*` events and the push
 * (`POST .../other_agencies/import`, 500 rows a request).
 *
 * pushRow() is the one-row form of the push, for a row the app creates
 * itself (a chart picks an agency from the state list before SSO has it).
 */
class OtherAgencyMirror extends ProjectedMirror
{
    public const SSO_ID_COLUMN = 'sso_other_agency_id';

    /** A user is waiting on the chart while pushRow() runs. */
    private const PUSH_ROW_TIMEOUT = 15;

    public function __construct(LocalTenantResolver $tenants, MasterDataClient $client, OtherAgencyProjection $projection)
    {
        parent::__construct($tenants, $client, $projection);
    }

    public function entity(): string
    {
        return 'other_agencies';
    }

    public static function events(): array
    {
        return [
            'other_agency.created',
            'other_agency.updated',
            'other_agency.deactivated',
            'other_agency.batch_updated',
        ];
    }

    public function publishTag(): string
    {
        return 'sso-master-data-other-agencies';
    }

    public function ssoIdColumn(): string
    {
        return self::SSO_ID_COLUMN;
    }

    protected function recordKey(): string
    {
        return 'other_agency';
    }

    protected function recordName(array $record): string
    {
        return trim((string) ($record['name'] ?? ''));
    }

    /**
     * Sends one local row up through SSO's import (idempotent by name and
     * state there) and links it to the SSO id SSO answers with. Returns that
     * SSO id, the existing one when the row is already linked, or null when
     * the row is not this company's, the company is not linked to SSO, SSO
     * cannot be reached or the link is refused. Never throws: the row stays
     * unlinked and the next resync adopts it by name and state.
     */
    public function pushRow(int $localCompanyId, int $localId): ?int
    {
        $row = $this->scoped($localCompanyId)
            ->where('id', $localId)
            ->first([...$this->pushSelect(), $this->ssoIdColumn().' as mirror_sso_id']);

        if ($row === null) {
            return null;
        }

        if ($row->mirror_sso_id !== null) {
            return (int) $row->mirror_sso_id;
        }

        $context = ['entity' => $this->entity(), 'company_id' => $localCompanyId, 'local_id' => $localId];

        // The local company id comes from the app's own row; its SSO id is
        // the authoritative company for SSO's import.
        $ssoCompanyId = DB::table('companies')->where('id', $localCompanyId)->value('sso_company_id');

        if ($ssoCompanyId === null || $ssoCompanyId === '') {
            Log::warning('sso-client: other agency not pushed, company is not linked to SSO', $context);

            return null;
        }

        try {
            $response = $this->client->import($this->entity(), $ssoCompanyId, [
                'app_slug' => (string) config('sso.app_slug'),
                $this->entity() => [$this->importRow($localCompanyId, $row)],
            ], self::PUSH_ROW_TIMEOUT);

            $entry = is_array($response['mapping'] ?? null) ? ($response['mapping'][$localId] ?? null) : null;
            $ssoId = (int) (is_array($entry) ? ($entry['sso_id'] ?? 0) : $entry);

            if ($ssoId <= 0) {
                Log::warning('sso-client: SSO did not map the pushed other agency', $context);

                return null;
            }

            $result = DB::transaction(fn (): array => $this->applyImportMapping($localCompanyId, [$localId => $entry]));
        } catch (\Throwable $e) {
            Log::warning('sso-client: other agency push failed; the next resync links it', $context + ['error' => $e->getMessage()]);

            return null;
        }

        if ($result['applied'] !== 1) {
            Log::warning('sso-client: pushed other agency not linked', $context + ['sso_id' => $ssoId, 'collisions' => $result['collisions']]);

            return null;
        }

        return $ssoId;
    }
}
