<?php

namespace Unified\SsoClient\Assistant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\Contracts\AssistantToolProvider;
use Unified\SsoClient\Security\SecurityEvents;

/**
 * Turns the SSO identity on an internal assistant request into the local
 * actor a tool runs as, and establishes the tenant the way the app's login
 * does. The sso ids are authoritative: they came in on a CORE_APP_API_KEY
 * request from SSO, which verified membership and the Admin role already.
 * This side only has to find the local rows and refuse a pairing the app
 * itself does not know about.
 */
class AssistantActorResolver
{
    public function __construct(private SecurityEvents $security) {}

    /**
     * Null when this app has no local company or user for those ids (not
     * provisioned, or the user never signed in here). Throws
     * NotAMemberException when both exist but the user does not belong to
     * the company, which is recorded as a security event.
     */
    public function resolve(int $ssoUserId, int $ssoCompanyId, bool $impersonating): ?AssistantActor
    {
        $company = $this->findBySsoId((string) config('metrics.company_model'), (string) config('metrics.company_sso_id_column', 'sso_company_id'), $ssoCompanyId);
        $user = $this->findBySsoId((string) config('metrics.user_model'), (string) config('metrics.user_sso_id_column', 'sso_id'), $ssoUserId);

        if ($company === null || ! $user instanceof Authenticatable) {
            return null;
        }

        if (! $this->isMember($user, $company)) {
            $this->security->warning('assistant.tool_denied', [
                'sso_user_id' => $ssoUserId,
                'sso_company_id' => $ssoCompanyId,
                'reason' => 'not_a_member',
            ]);

            throw new NotAMemberException;
        }

        return new AssistantActor($ssoUserId, $ssoCompanyId, $user, $company, $impersonating);
    }

    /**
     * Sign the user in for this request, hydrate the company's roles and
     * select the tenant, then give the provider its say.
     */
    public function prepare(AssistantActor $actor, ?AssistantToolProvider $provider): void
    {
        Auth::setUser($actor->user);

        if (method_exists($actor->user, 'loadRolesForCompany')) {
            $actor->user->loadRolesForCompany((int) $actor->company->getKey());
        }

        session(['selected_company_id' => $actor->company->getKey()]);

        if ($provider instanceof PreparesAssistantActor) {
            $provider->prepare($actor);
        }
    }

    private function findBySsoId(string $modelClass, string $column, int $ssoId): ?Model
    {
        if (! class_exists($modelClass)) {
            return null;
        }

        /** @var Model|null $model */
        $model = $modelClass::query()->withoutGlobalScopes()->where($column, $ssoId)->first();

        return $model;
    }

    /**
     * The app's `companies()` relation when the user model has one, else the
     * conventional company_user pivot. An app with neither is treated as
     * single-tenant membership (every local user belongs to the company).
     */
    private function isMember(Model $user, Model $company): bool
    {
        if (method_exists($user, 'companies')) {
            return $user->companies()->whereKey($company->getKey())->exists();
        }

        if (Schema::hasTable('company_user')) {
            return DB::table('company_user')
                ->where('user_id', $user->getKey())
                ->where('company_id', $company->getKey())
                ->exists();
        }

        return true;
    }
}
