<?php

namespace Unified\SsoClient\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Which SSO users this app has as members of one SSO company: the answer
 * SSO's nightly roster reconcile compares with its own membership list, so
 * it re-sends only the people an outage left out (2026-11: Digital Forms
 * and LMS each lost hundreds of user.created deliveries and nothing healed
 * them).
 *
 * Read straight from the tables the webhook handlers write (company_user,
 * users.sso_id, companies.sso_company_id), without model scopes: a local
 * user an app soft-deleted still counts as present, so SSO never tries to
 * re-create someone the app removed on purpose. Ids only, no names.
 */
class RosterMembersController extends Controller
{
    public function __invoke(string $ssoCompanyId): JsonResponse
    {
        $companyTable = $this->table('App\\Models\\Company', 'companies');
        $userTable = $this->table('App\\Models\\User', 'users');

        $companyId = DB::table($companyTable)->where('sso_company_id', $ssoCompanyId)->value('id');

        if ($companyId === null) {
            return response()->json(['provisioned' => false, 'sso_user_ids' => []]);
        }

        $ssoUserIds = DB::table('company_user')
            ->join($userTable, "{$userTable}.id", '=', 'company_user.user_id')
            ->where('company_user.company_id', $companyId)
            ->whereNotNull("{$userTable}.sso_id")
            ->pluck("{$userTable}.sso_id")
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return response()->json(['provisioned' => true, 'sso_user_ids' => $ssoUserIds]);
    }

    private function table(string $modelClass, string $fallback): string
    {
        if (class_exists($modelClass) && is_subclass_of($modelClass, Model::class)) {
            return (new $modelClass)->getTable();
        }

        return $fallback;
    }
}
