<?php

namespace Unified\SsoClient\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Unified\SsoClient\Models\SsoSessionAction;

class SsoWebhookController extends Controller
{
    /**
     * Handle incoming webhook from the SSO server.
     *
     * Verifies the HMAC signature, then dispatches to the appropriate handler
     * based on the event type.
     */
    public function handle(Request $request): JsonResponse
    {
        if (! $this->verifySignature($request)) {
            Log::warning('SSO webhook: invalid signature', [
                'ip' => $request->ip(),
                'event' => $request->input('event'),
            ]);

            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $event = $request->input('event');

        if (! $event) {
            return response()->json(['error' => 'Missing event'], 422);
        }

        try {
            $result = match ($event) {
                'user.created' => $this->handleUserCreated($request),
                'user.updated' => $this->handleUserUpdated($request),
                'user.deleted' => $this->handleUserDeleted($request),
                'company.updated' => $this->handleCompanyUpdated($request),
                'company.activated' => $this->handleCompanyActivated($request),
                'user.company_role_changed' => $this->handleUserRoleChanged($request),
                'user.impersonation.started' => $this->handleImpersonationStarted($request),
                'user.impersonation.ended' => $this->handleImpersonationEnded($request),
                'user.logged_out' => $this->handleUserLoggedOut($request),
                'trial.seed_data' => $this->handleTrialSeedData($request),
                'trial.purge_data' => $this->handleTrialPurgeData($request),
                'cad.migrate_data' => $this->handleCadMigrateData($request),
                default => $this->handleUnknownEvent($event),
            };

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('SSO webhook handler failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Handler failed'], 500);
        }
    }

    protected function verifySignature(Request $request): bool
    {
        $secret = config('sso.webhook_secret');

        if (! $secret) {
            Log::warning('SSO webhook: no webhook_secret configured, rejecting request');

            return false;
        }

        $signature = $request->header('X-SSO-Signature');

        if (! $signature) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleUserCreated(Request $request): array
    {
        $userData = $request->input('user', []);
        $companies = $request->input('companies', []);

        $user = $this->upsertUser($userData);

        if ($user) {
            foreach ($companies as $companyData) {
                $company = $this->findCompanyBySsoId($companyData['id'] ?? null, $companyData['legacyTenantId'] ?? $companyData['legacy_tenant_id'] ?? null);
                if ($company) {
                    $this->ensureUserAttachedToCompany($user, $company);
                    $this->syncRoles($user, $company, $companyData['roles'] ?? ['User']);
                }
            }
        }

        return ['status' => 'ok', 'action' => 'user.created'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleUserUpdated(Request $request): array
    {
        $userData = $request->input('user', []);
        $companies = $request->input('companies', []);

        $user = $this->upsertUser($userData);

        if ($user) {
            foreach ($companies as $companyData) {
                $company = $this->findCompanyBySsoId($companyData['id'] ?? null, $companyData['legacyTenantId'] ?? $companyData['legacy_tenant_id'] ?? null);
                if ($company) {
                    $this->ensureUserAttachedToCompany($user, $company);
                    $this->syncRoles($user, $company, $companyData['roles'] ?? ['User']);
                }
            }
        }

        return ['status' => 'ok', 'action' => 'user.updated'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleUserDeleted(Request $request): array
    {
        $userData = $request->input('user', []);
        $ssoUserId = $userData['id'] ?? null;
        $email = $userData['email'] ?? null;

        $userModel = $this->getUserModelClass();
        $user = null;

        if ($ssoUserId) {
            $user = $userModel::where('sso_id', (string) $ssoUserId)->first();
        }

        if (! $user && $email) {
            $user = $userModel::where('email', $email)->first();
        }

        if ($user) {
            $user->delete();
        }

        return ['status' => 'ok', 'action' => 'user.deleted'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleCompanyUpdated(Request $request): array
    {
        $companyData = $request->input('company', []);
        $ssoCompanyId = $companyData['id'] ?? null;
        $legacyTenantId = $companyData['legacy_tenant_id'] ?? null;

        $company = $this->findCompanyBySsoId($ssoCompanyId, $legacyTenantId);

        if ($company) {
            $updates = [];

            if (isset($companyData['name']) && $company->name !== $companyData['name']) {
                $updates['name'] = $companyData['name'];
            }

            if ($updates !== []) {
                $company->forceFill($updates)->save();
            }
        }

        return ['status' => 'ok', 'action' => 'company.updated'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleCompanyActivated(Request $request): array
    {
        $companyData = $request->input('company', []);
        $ssoCompanyId = $companyData['id'] ?? null;
        $legacyTenantId = $companyData['legacy_tenant_id'] ?? null;

        $company = $this->findCompanyBySsoId($ssoCompanyId, $legacyTenantId);

        if ($company) {
            $company->forceFill(['status' => 'active'])->save();
        }

        return ['status' => 'ok', 'action' => 'company.activated'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleUserRoleChanged(Request $request): array
    {
        $userData = $request->input('user', []);
        $companyData = $request->input('company', []);
        $roles = $request->input('roles', ['User']);

        $user = $this->findUserBySsoId($userData['id'] ?? null, $userData['email'] ?? null);
        $company = $this->findCompanyBySsoId($companyData['id'] ?? null, $companyData['legacy_tenant_id'] ?? null);

        if ($user && $company) {
            $this->syncRoles($user, $company, $roles);
        }

        return ['status' => 'ok', 'action' => 'user.company_role_changed'];
    }

    /**
     * Impersonation started in SSO. We don't try to impersonate the user
     * here — the OAuth flow handles identity. We just queue a set_company
     * action so when this user makes their next request to this app, the
     * middleware drops them into the right tenant.
     *
     * @return array<string, mixed>
     */
    protected function handleImpersonationStarted(Request $request): array
    {
        $impersonatedData = $request->input('impersonated', []);
        $companyData = $request->input('company', []);

        $user = $this->findUserBySsoId($impersonatedData['id'] ?? null, $impersonatedData['email'] ?? null);
        $company = $this->findCompanyBySsoId($companyData['id'] ?? null, $companyData['legacy_tenant_id'] ?? null);

        if (! $user || ! $company) {
            return ['status' => 'ok', 'action' => 'user.impersonation.started', 'skipped' => true];
        }

        SsoSessionAction::query()
            ->where('user_id', $user->id)
            ->where('action', SsoSessionAction::ACTION_SET_COMPANY)
            ->delete();

        SsoSessionAction::create([
            'user_id' => $user->id,
            'action' => SsoSessionAction::ACTION_SET_COMPANY,
            'payload' => ['company_id' => $company->id],
            'expires_at' => now()->addHours(8),
        ]);

        return ['status' => 'ok', 'action' => 'user.impersonation.started'];
    }

    /**
     * Impersonation ended in SSO. Force-logout the impersonated user
     * across this app — the next request will bounce through SSO and
     * land them back as themselves (or as the original admin if their
     * browser was the impersonator's).
     *
     * @return array<string, mixed>
     */
    protected function handleImpersonationEnded(Request $request): array
    {
        $impersonatedData = $request->input('impersonated', []);
        $user = $this->findUserBySsoId($impersonatedData['id'] ?? null, $impersonatedData['email'] ?? null);

        if (! $user) {
            return ['status' => 'ok', 'action' => 'user.impersonation.ended', 'skipped' => true];
        }

        SsoSessionAction::query()
            ->where('user_id', $user->id)
            ->delete();

        SsoSessionAction::create([
            'user_id' => $user->id,
            'action' => SsoSessionAction::ACTION_FORCE_LOGOUT,
            'payload' => ['reason' => 'impersonation_ended'],
            'expires_at' => now()->addHours(8),
        ]);

        return ['status' => 'ok', 'action' => 'user.impersonation.ended'];
    }

    /**
     * User logged out at the SSO hub (or via any downstream app's
     * logout button, which redirects through SSO). Force-logout this
     * user's local session.
     *
     * @return array<string, mixed>
     */
    protected function handleUserLoggedOut(Request $request): array
    {
        $userData = $request->input('user', []);
        $user = $this->findUserBySsoId($userData['id'] ?? null, $userData['email'] ?? null);

        if (! $user) {
            return ['status' => 'ok', 'action' => 'user.logged_out', 'skipped' => true];
        }

        SsoSessionAction::query()
            ->where('user_id', $user->id)
            ->delete();

        SsoSessionAction::create([
            'user_id' => $user->id,
            'action' => SsoSessionAction::ACTION_FORCE_LOGOUT,
            'payload' => ['reason' => 'sso_logout'],
            'expires_at' => now()->addHours(8),
        ]);

        return ['status' => 'ok', 'action' => 'user.logged_out'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleTrialSeedData(Request $request): array
    {
        $companyData = $request->input('company', []);
        $company = $this->findCompanyBySsoId($companyData['id'] ?? null, null);

        $userSsoIds = $request->input('users', []);
        $adminSsoId = $request->input('admin_user_id', '');

        if (! $company) {
            $company = $this->createCompanyFromWebhook($companyData, $adminSsoId);
        }

        if (! $company) {
            return ['status' => 'error', 'action' => 'trial.seed_data', 'reason' => 'company_not_found'];
        }

        $this->ensureUsersAttachedToCompany($company, $userSsoIds, $adminSsoId);

        $seederClass = 'App\\Services\\TrialDataSeeder';

        if (! class_exists($seederClass)) {
            Log::info('SSO webhook: no TrialDataSeeder found, skipping trial.seed_data');

            return ['status' => 'ok', 'action' => 'trial.seed_data', 'skipped' => true];
        }

        $jobClass = 'App\\Jobs\\RunTrialSeeder';

        if (class_exists($jobClass)) {
            $jobClass::dispatch($company, $userSsoIds, $adminSsoId);
        } else {
            app($seederClass)->seed($company, $userSsoIds, $adminSsoId);
        }

        return ['status' => 'ok', 'action' => 'trial.seed_data'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleTrialPurgeData(Request $request): array
    {
        $companyData = $request->input('company', []);
        $company = $this->findCompanyBySsoId($companyData['id'] ?? null, null);

        if (! $company) {
            return ['status' => 'ok', 'action' => 'trial.purge_data', 'reason' => 'company_not_found'];
        }

        $purgerClass = 'App\\Services\\TrialDataPurger';

        if (! class_exists($purgerClass)) {
            Log::info('SSO webhook: no TrialDataPurger found, skipping trial.purge_data');

            return ['status' => 'ok', 'action' => 'trial.purge_data', 'skipped' => true];
        }

        $jobClass = 'App\\Jobs\\RunTrialPurger';
        $adminSsoId = $request->input('admin_user_id', '');

        if (class_exists($jobClass)) {
            $jobClass::dispatch($company, $adminSsoId);
        } else {
            app($purgerClass)->purge($company, $adminSsoId);
        }

        return ['status' => 'ok', 'action' => 'trial.purge_data'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleCadMigrateData(Request $request): array
    {
        $companyData = $request->input('company', []);
        $company = $this->findCompanyBySsoId(
            $companyData['id'] ?? null,
            $companyData['legacy_tenant_id'] ?? null,
        );

        if (! $company) {
            return ['status' => 'error', 'action' => 'cad.migrate_data', 'reason' => 'company_not_found'];
        }

        $legacyTenantId = $companyData['legacy_tenant_id'] ?? $company->core_tenant_id ?? null;

        if (! $legacyTenantId) {
            return ['status' => 'error', 'action' => 'cad.migrate_data', 'reason' => 'no_legacy_tenant_id'];
        }

        $jobClass = 'App\\Jobs\\RunLegacyCadMigration';

        if (class_exists($jobClass)) {
            $jobClass::dispatch($company, $legacyTenantId);
        } else {
            Log::info('SSO webhook: RunLegacyCadMigration job not found, skipping cad.migrate_data');
        }

        return ['status' => 'ok', 'action' => 'cad.migrate_data'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleUnknownEvent(string $event): array
    {
        Log::info('SSO webhook: unhandled event type', ['event' => $event]);

        return ['status' => 'ok', 'action' => 'ignored'];
    }

    protected function upsertUser(array $userData): mixed
    {
        $userModel = $this->getUserModelClass();
        $ssoUserId = $userData['id'] ?? null;
        $legacySsoId = $userData['legacy_sso_id'] ?? null;
        $email = $userData['email'] ?? null;

        $user = $this->findUserBySsoId($ssoUserId, $email);

        if ($user) {
            $updates = ['last_login_at' => now()];

            if (isset($userData['first_name']) && isset($userData['last_name'])) {
                $name = trim($userData['first_name'].' '.$userData['last_name']);
                if ($user->name !== $name) {
                    $updates['name'] = $name;
                }
            }

            if ($email && $user->email !== $email) {
                $updates['email'] = $email;
            }

            if ($ssoUserId && $user->sso_id !== (string) $ssoUserId) {
                $updates['sso_id'] = (string) $ssoUserId;
            }

            $user->forceFill($updates)->save();

            return $user;
        }

        $name = trim(($userData['first_name'] ?? '').' '.($userData['last_name'] ?? ''));

        if ($name === '') {
            $name = $email ?? 'SSO User';
        }

        $user = new $userModel;
        $user->forceFill([
            'name' => $name,
            'email' => $email ?? Str::uuid().'@sso.local',
            'password' => bcrypt(Str::random(40)),
            'sso_id' => $ssoUserId ? (string) $ssoUserId : null,
            'email_verified_at' => now(),
        ]);
        $user->save();

        return $user;
    }

    protected function findUserBySsoId(int|string|null $ssoUserId, ?string $email): mixed
    {
        $userModel = $this->getUserModelClass();
        $user = null;

        if ($ssoUserId) {
            $user = $userModel::where('sso_id', (string) $ssoUserId)->first();
        }

        if (! $user && $email) {
            $user = $userModel::where('email', $email)->first();
        }

        return $user;
    }

    protected function findCompanyBySsoId(int|string|null $ssoCompanyId, ?string $legacyTenantId): mixed
    {
        $companyModel = $this->getCompanyModelClass();
        $company = null;

        if ($ssoCompanyId) {
            $company = $companyModel::where('sso_company_id', $ssoCompanyId)->first();
        }

        if (! $company && $legacyTenantId) {
            $company = $companyModel::where('core_tenant_id', $legacyTenantId)->first();
        }

        return $company;
    }

    protected function ensureUserAttachedToCompany($user, $company): void
    {
        $exists = DB::table('company_user')
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $exists) {
            $user->companies()->attach($company->id, [
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function syncRoles($user, $company, array $roles): void
    {
        $roleModel = $this->getRoleModelClass();
        $allowedRoles = array_intersect($roles, ['Admin', 'User']);

        if (empty($allowedRoles)) {
            $allowedRoles = ['User'];
        }

        $roleIds = [];

        foreach ($allowedRoles as $roleName) {
            $role = $roleModel::firstOrCreate(
                ['name' => $roleName, 'company_id' => $company->id],
                ['guard_name' => 'web']
            );
            $roleIds[] = $role->id;

            DB::table('company_user_roles')->upsert([
                [
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ], ['company_id', 'user_id', 'role_id'], ['updated_at']);
        }

        DB::table('company_user_roles')
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->whereNotIn('role_id', $roleIds)
            ->delete();
    }

    protected function createCompanyFromWebhook(array $companyData, string $adminSsoId = ''): mixed
    {
        $companyModel = $this->getCompanyModelClass();
        $ssoCompanyId = $companyData['id'] ?? null;

        if (! $ssoCompanyId) {
            return null;
        }

        $userModel = $this->getUserModelClass();
        $adminUser = $adminSsoId ? $userModel::where('sso_id', (string) $adminSsoId)->first() : null;

        if (! $adminUser) {
            $adminUser = $adminSsoId ? $userModel::where('email', 'like', '%')->orderBy('id', 'desc')->first() : null;
        }

        $company = new $companyModel;
        $company->forceFill([
            'name' => $companyData['name'] ?? 'Trial Company',
            'sso_company_id' => (string) $ssoCompanyId,
            'is_live' => false,
            'owner_id' => $adminUser?->id ?? 1,
        ]);
        $company->save();

        return $company;
    }

    protected function ensureUsersAttachedToCompany($company, array $userSsoIds, string $adminSsoId): void
    {
        $userModel = $this->getUserModelClass();

        foreach ($userSsoIds as $ssoId) {
            $user = $userModel::where('sso_id', (string) $ssoId)->first();

            if (! $user) {
                continue;
            }

            $this->ensureUserAttachedToCompany($user, $company);

            $roleName = ((string) $ssoId === $adminSsoId) ? 'Admin' : 'User';
            $this->syncRoles($user, $company, [$roleName]);
        }
    }

    protected function getUserModelClass(): string
    {
        return 'App\\Models\\User';
    }

    protected function getCompanyModelClass(): string
    {
        return 'App\\Models\\Company';
    }

    protected function getRoleModelClass(): string
    {
        return 'App\\Models\\Role';
    }
}
