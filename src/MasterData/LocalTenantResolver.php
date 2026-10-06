<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData;

use Illuminate\Support\Facades\DB;

/**
 * Translates SSO company and user ids into this app's local primary keys.
 *
 * Reads go through the query builder, so no tenant global scope applies, and
 * each lookup is pinned to the single SSO id it was given. The ids always come
 * from an authoritative source (the HMAC-verified webhook payload, the SSO
 * internal API, or the operator's --company option), which is the DEV_GUIDELINES
 * §4a condition for an unscoped read.
 */
class LocalTenantResolver
{
    public function companyId(int|string|null $ssoCompanyId): ?int
    {
        if ($ssoCompanyId === null || $ssoCompanyId === '') {
            return null;
        }

        $id = DB::table('companies')->where('sso_company_id', $ssoCompanyId)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function userId(int|string|null $ssoUserId): ?int
    {
        if ($ssoUserId === null || $ssoUserId === '') {
            return null;
        }

        $id = DB::table('users')->where('sso_id', (string) $ssoUserId)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  array<int, int|string>  $ssoUserIds
     * @return array<string, int> local user id keyed by SSO user id
     */
    public function userIds(array $ssoUserIds): array
    {
        $ssoUserIds = array_values(array_unique(array_map('strval', $ssoUserIds)));

        if ($ssoUserIds === []) {
            return [];
        }

        $map = [];
        foreach (array_chunk($ssoUserIds, 500) as $chunk) {
            foreach (DB::table('users')->whereIn('sso_id', $chunk)->get(['id', 'sso_id']) as $row) {
                $map[(string) $row->sso_id] = (int) $row->id;
            }
        }

        return $map;
    }

    /**
     * Every local company linked to SSO.
     *
     * @return array<int, int|string> SSO company id keyed by local company id
     */
    public function linkedCompanies(): array
    {
        return DB::table('companies')
            ->whereNotNull('sso_company_id')
            ->orderBy('id')
            ->pluck('sso_company_id', 'id')
            ->all();
    }
}
