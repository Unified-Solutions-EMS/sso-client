<?php

namespace Unified\SsoClient\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes company memberships that SSO no longer grants.
 *
 * SSO payloads (the login /api/user fetch and the user.created /
 * user.updated webhooks) carry the user's FULL company list, so a local
 * membership in an SSO-linked company that is absent from the payload was
 * revoked on the SSO side and must be detached here. Local-only companies
 * (no sso_company_id and no core_tenant_id) are never touched — SSO does
 * not manage them.
 */
trait PrunesStaleCompanyMemberships
{
    /** @var array<string, array<int, string>> */
    protected static array $pruneLinkColumns = [];

    /**
     * @param  array<int, int|string>  $keepCompanyIds  local company ids the payload still grants
     */
    protected function pruneStaleCompanyMemberships($user, array $keepCompanyIds): void
    {
        $companyModel = $this->getCompanyModelClass();
        $companyTable = (new $companyModel)->getTable();

        $linkColumns = self::$pruneLinkColumns[$companyTable] ??= array_values(array_filter(
            ['sso_company_id', 'core_tenant_id'],
            static fn (string $column): bool => Schema::hasColumn($companyTable, $column),
        ));

        if ($linkColumns === []) {
            return;
        }

        $staleCompanyIds = DB::table('company_user')
            ->join($companyTable, "{$companyTable}.id", '=', 'company_user.company_id')
            ->where('company_user.user_id', $user->id)
            ->when($keepCompanyIds !== [], fn ($query) => $query->whereNotIn('company_user.company_id', $keepCompanyIds))
            ->where(function ($query) use ($companyTable, $linkColumns): void {
                foreach ($linkColumns as $column) {
                    $query->orWhereNotNull("{$companyTable}.{$column}");
                }
            })
            ->pluck('company_user.company_id')
            ->all();

        if ($staleCompanyIds === []) {
            return;
        }

        DB::table('company_user_roles')
            ->where('user_id', $user->id)
            ->whereIn('company_id', $staleCompanyIds)
            ->delete();

        DB::table('company_user')
            ->where('user_id', $user->id)
            ->whereIn('company_id', $staleCompanyIds)
            ->delete();
    }
}
