<?php

namespace Unified\SsoClient\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Unified\SsoClient\Concerns\PrunesStaleCompanyMemberships;
use Unified\SsoClient\Http\Concerns\VerifiesSsoWebhookSignature;
use Unified\SsoClient\MasterData\MasterDataWebhookHandler;
use Unified\SsoClient\Models\SsoSessionAction;
use Unified\SsoClient\Security\SecurityEvents;
use Unified\SsoClient\Trial\AppTrialJobs;
use Unified\SsoClient\Trial\Jobs\PurgeTrialThenCleanMirrors;
use Unified\SsoClient\Trial\Jobs\SeedTrialAfterMasterDataSync;
use Unified\SsoClient\Trial\TrialContext;
use Unified\SsoClient\Trial\TrialMasterDataSync;
use Unified\SsoClient\TrialPurgeVerifier;

class SsoWebhookController extends Controller
{
    use PrunesStaleCompanyMemberships;
    use VerifiesSsoWebhookSignature;

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
                'user.app_role_changed' => $this->handleUserAppRoleChanged($request),
                'user.staff_role_changed' => $this->handleUserStaffRoleChanged($request),
                'user.impersonation.started' => $this->handleImpersonationStarted($request),
                'user.impersonation.ended' => $this->handleImpersonationEnded($request),
                'user.logged_out' => $this->handleUserLoggedOut($request),
                'device.revoked' => $this->handleDeviceRevoked($request),
                'trial.seed_data' => $this->handleTrialSeedData($request),
                'trial.purge_data' => $this->handleTrialPurgeData($request),
                'cad.migrate_data' => $this->handleCadMigrateData($request),
                'qualification.created',
                'qualification.updated',
                'qualification.deleted',
                'user.qualifications_changed',
                'division.created',
                'division.updated',
                'division.deactivated',
                'division.batch_updated',
                'user.division_changed',
                'location.created',
                'location.updated',
                'location.deactivated',
                'location.batch_updated',
                'vehicle.created',
                'vehicle.updated',
                'vehicle.deactivated',
                'vehicle.batch_updated',
                'facility.created',
                'facility.updated',
                'facility.deactivated',
                'facility.batch_updated',
                'medication.created',
                'medication.updated',
                'medication.deactivated',
                'medication.batch_updated',
                'medication.review_completed',
                'procedure.created',
                'procedure.updated',
                'procedure.deactivated',
                'procedure.batch_updated',
                'procedure.review_completed',
                'person.updated',
                'person.deactivated',
                'agency.created',
                'agency.updated',
                'agency.batch_updated',
                'contact.created',
                'contact.updated',
                'contact.deactivated',
                'contact.batch_updated',
                'device.created',
                'device.updated',
                'device.deactivated',
                'device.batch_updated',
                'state_configuration.created',
                'state_configuration.updated',
                'state_configuration.deactivated',
                'state_configuration.batch_updated',
                'unit.created',
                'unit.updated',
                'unit.deactivated',
                'unit.batch_updated',
                'rotation.created',
                'rotation.updated',
                'rotation.deactivated',
                'rotation.batch_updated' => app(MasterDataWebhookHandler::class)->handle($event, $request->all()),
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

    /**
     * @return array<string, mixed>
     */
    protected function handleUserCreated(Request $request): array
    {
        $userData = $request->input('user', []);
        $companies = $request->input('companies');

        $user = $this->upsertUser($userData);

        if ($user && is_array($companies)) {
            $this->syncUserCompanyMemberships($user, $companies);
        }

        return ['status' => 'ok', 'action' => 'user.created'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleUserUpdated(Request $request): array
    {
        $userData = $request->input('user', []);
        $companies = $request->input('companies');

        $user = $this->upsertUser($userData);

        if ($user && is_array($companies)) {
            $this->syncUserCompanyMemberships($user, $companies);
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

            // SSO owns the company timezone; mirror it locally for apps that
            // have adopted the column. Others simply ignore the field.
            if (isset($companyData['timezone'])
                && Schema::hasColumn($company->getTable(), 'timezone')
                && ($company->timezone ?? null) !== $companyData['timezone']
            ) {
                $updates['timezone'] = $companyData['timezone'];
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
            $this->applyRoleChange($request, $user, $company, $roles);
        }

        return ['status' => 'ok', 'action' => 'user.company_role_changed'];
    }

    /**
     * A user's role for a specific application within a company changed. SSO
     * dispatches this only to the affected app, but we still guard on the
     * app slug so a misrouted payload can't rewrite another app's roles.
     *
     * @return array<string, mixed>
     */
    protected function handleUserAppRoleChanged(Request $request): array
    {
        $appSlug = $request->input('app_slug');

        if ($appSlug !== null && $appSlug !== config('sso.app_slug')) {
            return ['status' => 'ignored', 'action' => 'user.app_role_changed', 'reason' => 'app_slug mismatch'];
        }

        $userData = $request->input('user', []);
        $companyData = $request->input('company', []);
        $roles = $request->input('roles', ['User']);

        $user = $this->findUserBySsoId($userData['id'] ?? null, $userData['email'] ?? null);
        $company = $this->findCompanyBySsoId($companyData['id'] ?? null, $companyData['legacy_tenant_id'] ?? null);

        if ($user && $company) {
            $this->applyRoleChange($request, $user, $company, $roles);
        }

        return ['status' => 'ok', 'action' => 'user.app_role_changed'];
    }

    /**
     * A user's global platform staff roles changed.
     *
     * @return array<string, mixed>
     */
    protected function handleUserStaffRoleChanged(Request $request): array
    {
        $userData = $request->input('user', []);
        $staffRoles = $request->input('staff_roles', []);

        $user = $this->findUserBySsoId($userData['id'] ?? null, $userData['email'] ?? null);

        if ($user && Schema::hasColumn($user->getTable(), 'staff_roles')) {
            $slugs = array_values(array_unique(array_filter($staffRoles)));
            $value = $user->hasCast('staff_roles') ? $slugs : json_encode($slugs);
            $user->forceFill(['staff_roles' => $value])->save();
        }

        return ['status' => 'ok', 'action' => 'user.staff_role_changed'];
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

        $this->queueForceLogout($user, 'sso_logout');

        return ['status' => 'ok', 'action' => 'user.logged_out'];
    }

    /**
     * An agency admin revoked a device in SSO. The payload names the user the
     * device belonged to, so this app ends that user's sessions: rows are
     * deleted outright on the database session driver, and a force_logout
     * action is queued for every other driver (and for any request already
     * past the session read). The OAuth tokens live inside the session
     * payload, so deleting the row discards them with it. SSO's refresh
     * results are cached by refresh-token hash for 60 seconds and cannot be
     * mapped back to a user; they expire on their own.
     *
     * Fail-silent: SSO must record the delivery whatever happens here, so an
     * unknown device, unknown user or storage error is still a 200.
     *
     * @return array<string, mixed>
     */
    protected function handleDeviceRevoked(Request $request): array
    {
        $userData = (array) $request->input('user', []);

        try {
            $user = $this->findUserBySsoId($userData['id'] ?? null, $userData['email'] ?? null);

            if (! $user) {
                return ['status' => 'ok', 'action' => 'device.revoked', 'skipped' => true];
            }

            $this->deleteDatabaseSessions($user->id);
            $this->queueForceLogout($user, 'device_revoked');
        } catch (\Throwable $e) {
            Log::warning('SSO webhook: device.revoked handling failed', ['error' => $e->getMessage()]);
        }

        return ['status' => 'ok', 'action' => 'device.revoked'];
    }

    protected function deleteDatabaseSessions(int|string $userId): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $table = (string) config('session.table', 'sessions');
        $connection = config('session.connection');

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        DB::connection($connection)->table($table)->where('user_id', $userId)->delete();
    }

    protected function queueForceLogout(mixed $user, string $reason): void
    {
        SsoSessionAction::query()
            ->where('user_id', $user->id)
            ->delete();

        SsoSessionAction::create([
            'user_id' => $user->id,
            'action' => SsoSessionAction::ACTION_FORCE_LOGOUT,
            'payload' => ['reason' => $reason],
            'expires_at' => now()->addHours(8),
        ]);
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

        if (! class_exists(AppTrialJobs::SEEDER)) {
            Log::info('SSO webhook: no TrialDataSeeder found, skipping trial.seed_data');

            return ['status' => 'ok', 'action' => 'trial.seed_data', 'skipped' => true];
        }

        // An SSO that seeds the shared lists says so with `master_data`; an
        // older one omits it and the app seeds exactly as before. Mirrors are
        // resynced first only when this app has any turned on.
        $masterData = $request->input('master_data');

        if (is_array($masterData) && app(TrialMasterDataSync::class)->mirrors() !== []) {
            SeedTrialAfterMasterDataSync::dispatch($company, $userSsoIds, $adminSsoId, $masterData);

            return ['status' => 'ok', 'action' => 'trial.seed_data', 'master_data' => 'syncing'];
        }

        app(AppTrialJobs::class)->seed($company, $userSsoIds, $adminSsoId, is_array($masterData) ? TrialContext::fromPayload($masterData) : null);

        return ['status' => 'ok', 'action' => 'trial.seed_data'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleTrialPurgeData(Request $request): array
    {
        $companyData = $request->input('company', []);
        $ssoCompanyId = $companyData['id'] ?? null;
        $company = $this->findCompanyBySsoId($ssoCompanyId, null);

        if (! $company) {
            return ['status' => 'ok', 'action' => 'trial.purge_data', 'reason' => 'company_not_found'];
        }

        if (! class_exists(AppTrialJobs::PURGER)) {
            Log::info('SSO webhook: no TrialDataPurger found, skipping trial.purge_data');

            return ['status' => 'ok', 'action' => 'trial.purge_data', 'skipped' => true];
        }

        $verification = app(TrialPurgeVerifier::class)->verify($ssoCompanyId);

        if ($verification !== TrialPurgeVerifier::PURGEABLE) {
            Log::warning('SSO webhook: trial.purge_data blocked, SSO did not confirm the company as purgeable', [
                'sso_company_id' => $ssoCompanyId,
                'local_company_id' => $company->getKey(),
                'reason' => $verification,
            ]);

            app(SecurityEvents::class)->warning('trial.purge_blocked', [
                'sso_company_id' => (string) $ssoCompanyId,
                'local_company_id' => $company->getKey(),
                'reason' => $verification,
            ]);

            return ['status' => 'ok', 'action' => 'trial.purge_data', 'skipped' => true, 'reason' => $verification];
        }

        $adminSsoId = $request->input('admin_user_id', '');
        $seeded = $request->input('master_data.seeded');

        if (is_array($seeded) && $seeded !== []) {
            PurgeTrialThenCleanMirrors::dispatch($company, $adminSsoId, $seeded);

            return ['status' => 'ok', 'action' => 'trial.purge_data', 'master_data' => 'cleaning'];
        }

        app(AppTrialJobs::class)->purge($company, $adminSsoId);

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

    /**
     * SSO says "removed" with an explicit empty role list (it sends
     * `user.app_role_changed` with `roles: []` to every app when a person is
     * taken out of a company). That must detach the membership, not fall
     * through to the User default that a payload with no roles key gets.
     */
    protected function applyRoleChange(Request $request, $user, $company, array $roles): void
    {
        $explicitlyNone = $request->has('roles') && array_values(array_filter($roles)) === [];

        if ($explicitlyNone) {
            $this->detachCompanyMembership($user, $company);

            return;
        }

        $this->syncRoles($user, $company, $roles);
    }

    protected function detachCompanyMembership($user, $company): void
    {
        DB::table('company_user_roles')
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->delete();

        DB::table('company_user')
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->delete();
    }

    protected function syncRoles($user, $company, array $roles): void
    {
        $roleModel = $this->getRoleModelClass();

        $roleNames = array_values(array_unique(array_filter($roles)));

        if ($roleNames === []) {
            $roleNames = ['User'];
        }

        $roleIds = [];

        foreach ($roleNames as $roleName) {
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
        if ($userSsoIds === []) {
            return;
        }

        $userModel = $this->getUserModelClass();
        $users = $userModel::query()
            ->whereIn('sso_id', array_map('strval', $userSsoIds))
            ->get()
            ->keyBy(fn ($u) => (string) $u->sso_id);

        if ($users->isEmpty()) {
            return;
        }

        $userIds = $users->pluck('id')->all();

        $existingAttachments = DB::table('company_user')
            ->where('company_id', $company->id)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->all();

        $missingUserIds = array_values(array_diff($userIds, $existingAttachments));
        if ($missingUserIds !== []) {
            $now = now();
            DB::table('company_user')->insert(array_map(fn ($uid) => [
                'user_id' => $uid,
                'company_id' => $company->id,
                'created_at' => $now,
                'updated_at' => $now,
            ], $missingUserIds));
        }

        $userIdToRole = [];
        foreach ($users as $ssoId => $user) {
            $userIdToRole[$user->id] = ($ssoId === $adminSsoId) ? 'Admin' : 'User';
        }

        $this->syncRolesAcrossUsersForCompany($company->id, $userIdToRole);
    }

    /**
     * Bulk-resolve every company in the webhook payload, bulk-attach any
     * missing company_user pivot rows, bulk-sync per-company roles, then
     * prune memberships in SSO-linked companies the payload no longer
     * grants. The payload carries the user's FULL company list, so an
     * empty list means "member of nothing" and prunes everything
     * SSO-linked; entries that carry no usable id are treated as a
     * malformed payload and leave memberships untouched.
     *
     * @param  array<int, array<string, mixed>>  $companies
     */
    protected function syncUserCompanyMemberships($user, array $companies): void
    {
        $ssoIds = array_values(array_filter(array_map(
            fn ($c) => isset($c['id']) ? (string) $c['id'] : null,
            $companies,
        )));
        $legacyIds = array_values(array_filter(array_map(
            fn ($c) => $c['legacyTenantId'] ?? $c['legacy_tenant_id'] ?? null,
            $companies,
        )));

        if ($companies !== [] && $ssoIds === [] && $legacyIds === []) {
            return;
        }

        $rolesByCompanyId = [];

        if ($ssoIds !== [] || $legacyIds !== []) {
            $companyModel = $this->getCompanyModelClass();
            $resolved = $companyModel::query()
                ->where(function ($q) use ($ssoIds, $legacyIds): void {
                    if ($ssoIds !== []) {
                        $q->whereIn('sso_company_id', $ssoIds);
                    }
                    if ($legacyIds !== []) {
                        $q->orWhereIn('core_tenant_id', array_map('strval', $legacyIds));
                    }
                })
                ->get();

            $bySsoId = $resolved->keyBy(fn ($c) => (string) ($c->sso_company_id ?? ''));
            $byLegacyId = $resolved->keyBy(fn ($c) => (string) ($c->core_tenant_id ?? ''));

            foreach ($companies as $companyData) {
                $ssoId = isset($companyData['id']) ? (string) $companyData['id'] : '';
                $legacyId = $companyData['legacyTenantId'] ?? $companyData['legacy_tenant_id'] ?? null;

                $company = $ssoId !== '' ? $bySsoId->get($ssoId) : null;
                if (! $company && $legacyId !== null) {
                    $company = $byLegacyId->get((string) $legacyId);
                }
                if (! $company) {
                    continue;
                }

                $rolesByCompanyId[$company->id] = $companyData['roles'] ?? ['User'];
            }
        }

        $companyIds = array_keys($rolesByCompanyId);

        if ($companyIds !== []) {
            $existingAttachments = DB::table('company_user')
                ->where('user_id', $user->id)
                ->whereIn('company_id', $companyIds)
                ->pluck('company_id')
                ->all();

            $missingCompanyIds = array_values(array_diff($companyIds, $existingAttachments));
            if ($missingCompanyIds !== []) {
                $now = now();
                DB::table('company_user')->insert(array_map(fn ($cid) => [
                    'user_id' => $user->id,
                    'company_id' => $cid,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $missingCompanyIds));
            }

            $this->syncRolesAcrossCompaniesForUser($user->id, $rolesByCompanyId);
        }

        $this->pruneStaleCompanyMemberships($user, $companyIds);
    }

    /**
     * Sync one user's roles across many companies in a bounded query count.
     * Used by the user.created/updated webhook paths.
     *
     * @param  array<int, array<int, string>>  $rolesByCompanyId
     */
    protected function syncRolesAcrossCompaniesForUser(int $userId, array $rolesByCompanyId): void
    {
        $normalized = [];
        $roleNameUniverse = [];
        foreach ($rolesByCompanyId as $companyId => $roles) {
            $names = array_values(array_unique(array_filter($roles)));
            $normalized[$companyId] = $names !== [] ? $names : ['User'];
            $roleNameUniverse = array_merge($roleNameUniverse, $normalized[$companyId]);
        }

        $rolesByCompany = $this->resolveRolesForCompanies(
            array_keys($normalized),
            array_values(array_unique($roleNameUniverse)),
        );

        $desired = [];
        foreach ($normalized as $companyId => $roleNames) {
            foreach ($roleNames as $roleName) {
                $roleId = $rolesByCompany[$companyId][$roleName] ?? null;
                if ($roleId !== null) {
                    $desired["{$companyId}-{$roleId}"] = [
                        'company_id' => $companyId,
                        'role_id' => $roleId,
                    ];
                }
            }
        }

        $this->reconcileCompanyUserRoles($userId, array_keys($normalized), $desired);
    }

    /**
     * Sync many users' roles within one company in a bounded query count.
     * Used by the trial.seed_data path.
     *
     * @param  array<int, string>  $userIdToRole
     */
    protected function syncRolesAcrossUsersForCompany(int $companyId, array $userIdToRole): void
    {
        if ($userIdToRole === []) {
            return;
        }

        $roleNameUniverse = array_values(array_unique(array_filter($userIdToRole)));

        if ($roleNameUniverse === []) {
            return;
        }

        $rolesByCompany = $this->resolveRolesForCompanies([$companyId], $roleNameUniverse);
        $roleNameToId = $rolesByCompany[$companyId] ?? [];

        $now = now();
        $userIds = array_keys($userIdToRole);
        $existingRows = DB::table('company_user_roles')
            ->where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'role_id']);

        $existingByUser = [];
        foreach ($existingRows as $row) {
            $existingByUser[(int) $row->user_id][] = (int) $row->role_id;
        }

        $rowsToInsert = [];
        $deletionsByUser = [];
        foreach ($userIdToRole as $userId => $roleName) {
            $roleId = $roleNameToId[$roleName] ?? null;
            if ($roleId === null) {
                continue;
            }
            $existingRoleIds = $existingByUser[$userId] ?? [];
            if (! in_array($roleId, $existingRoleIds, true)) {
                $rowsToInsert[] = [
                    'company_id' => $companyId,
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            $extras = array_diff($existingRoleIds, [$roleId]);
            if ($extras !== []) {
                $deletionsByUser[$userId] = array_values($extras);
            }
        }

        if ($rowsToInsert !== []) {
            DB::table('company_user_roles')->insert($rowsToInsert);
        }

        foreach ($deletionsByUser as $userId => $roleIds) {
            DB::table('company_user_roles')
                ->where('company_id', $companyId)
                ->where('user_id', $userId)
                ->whereIn('role_id', $roleIds)
                ->delete();
        }
    }

    /**
     * Resolve (or create) the given roles for every company id in one pass.
     * Returns [company_id => [role_name => role_id]].
     *
     * @param  array<int, int>  $companyIds
     * @param  array<int, string>  $roleNames
     * @return array<int, array<string, int>>
     */
    protected function resolveRolesForCompanies(array $companyIds, array $roleNames): array
    {
        if ($companyIds === [] || $roleNames === []) {
            return [];
        }

        $roleModel = $this->getRoleModelClass();
        $rolesTable = (new $roleModel)->getTable();

        $existing = $roleModel::query()
            ->whereIn('company_id', $companyIds)
            ->whereIn('name', $roleNames)
            ->get(['id', 'name', 'company_id']);

        $byCompany = [];
        foreach ($existing as $role) {
            $byCompany[(int) $role->company_id][(string) $role->name] = (int) $role->id;
        }

        $now = now();
        $toCreate = [];
        foreach ($companyIds as $companyId) {
            foreach ($roleNames as $roleName) {
                if (isset($byCompany[$companyId][$roleName])) {
                    continue;
                }
                $toCreate[] = [
                    'name' => $roleName,
                    'company_id' => $companyId,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($toCreate !== []) {
            DB::table($rolesTable)->insert($toCreate);

            $newCompanyIds = array_values(array_unique(array_column($toCreate, 'company_id')));
            $newNames = array_values(array_unique(array_column($toCreate, 'name')));
            $newlyCreated = $roleModel::query()
                ->whereIn('company_id', $newCompanyIds)
                ->whereIn('name', $newNames)
                ->get(['id', 'name', 'company_id']);
            foreach ($newlyCreated as $role) {
                $byCompany[(int) $role->company_id][(string) $role->name] = (int) $role->id;
            }
        }

        return $byCompany;
    }

    /**
     * Reconcile the company_user_roles pivot for one user across many companies:
     * insert any desired rows that don't exist, delete any rows that exist
     * within the scoped companies but aren't desired.
     *
     * @param  array<int, int>  $companyIds
     * @param  array<string, array{company_id: int, role_id: int}>  $desired  keyed "companyId-roleId"
     */
    protected function reconcileCompanyUserRoles(int $userId, array $companyIds, array $desired): void
    {
        if ($companyIds === []) {
            return;
        }

        $existing = DB::table('company_user_roles')
            ->where('user_id', $userId)
            ->whereIn('company_id', $companyIds)
            ->get(['company_id', 'role_id']);

        $existingKeys = [];
        foreach ($existing as $row) {
            $existingKeys["{$row->company_id}-{$row->role_id}"] = true;
        }

        $now = now();
        $rowsToInsert = [];
        foreach ($desired as $key => $row) {
            if (isset($existingKeys[$key])) {
                continue;
            }
            $rowsToInsert[] = [
                'company_id' => $row['company_id'],
                'user_id' => $userId,
                'role_id' => $row['role_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rowsToInsert !== []) {
            DB::table('company_user_roles')->insert($rowsToInsert);
        }

        $keepRoleIdsByCompany = [];
        foreach ($desired as $row) {
            $keepRoleIdsByCompany[$row['company_id']][] = $row['role_id'];
        }

        DB::table('company_user_roles')
            ->where('user_id', $userId)
            ->where(function ($q) use ($companyIds, $keepRoleIdsByCompany): void {
                foreach ($companyIds as $companyId) {
                    $q->orWhere(function ($inner) use ($companyId, $keepRoleIdsByCompany): void {
                        $inner->where('company_id', $companyId);
                        $keepIds = $keepRoleIdsByCompany[$companyId] ?? [];
                        if ($keepIds !== []) {
                            $inner->whereNotIn('role_id', $keepIds);
                        }
                    });
                }
            })
            ->delete();
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
