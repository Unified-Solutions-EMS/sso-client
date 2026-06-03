<?php

namespace Unified\SsoClient;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Unified\SsoClient\Contracts\SsoUserSynchronizerContract;

class SsoUserSynchronizer implements SsoUserSynchronizerContract
{
    /**
     * Synchronize the SSO user payload into local database records.
     *
     * Expects the normalized payload from the SSO /api/user endpoint:
     * {
     *   "user": { "id", "email", "displayName", "firstName", "lastName", "username", "legacySsoId" },
     *   "companies": [ { "id", "name", "legacyTenantId", "roles": [...] } ],
     *   "selectedCompany": { "id", "name", "legacyTenantId", "roles": [...] }
     * }
     *
     * @return array{0: Authenticatable, 1: mixed}
     */
    public function synchronize(array $payload): array
    {
        $userData = $payload['user'] ?? [];
        $companies = $payload['companies'] ?? [];
        $selectedCompany = $payload['selectedCompany'] ?? null;

        $ssoUserId = $userData['id'] ?? null;
        $legacySsoId = $userData['legacySsoId'] ?? null;
        $email = $userData['email'] ?? null;
        $displayName = $this->resolveDisplayName($userData);
        $username = $userData['username'] ?? null;
        $phoneNumber = $userData['phoneNumber'] ?? null;

        if (! $ssoUserId && ! $email) {
            Log::warning('SSO sync: No user ID or email in payload');

            return [null, null];
        }

        return DB::transaction(function () use ($ssoUserId, $legacySsoId, $email, $displayName, $username, $phoneNumber, $companies, $selectedCompany) {
            $user = $this->findOrCreateUser($ssoUserId, $legacySsoId, $email, $displayName, $username, $phoneNumber);

            $localCompanies = $this->resolveCompanies($companies, $user);

            $this->attachUserToCompanies($user, $localCompanies);
            $this->syncRolesForCompanies($user, $companies, $localCompanies);

            foreach ($companies as $companyData) {
                $ssoCompanyId = $companyData['id'] ?? null;
                if ($ssoCompanyId !== null && isset($localCompanies[$ssoCompanyId])) {
                    $this->syncEnabledModules($localCompanies[$ssoCompanyId], $companyData);
                }
            }

            // Determine which local company is selected
            $selectedLocalCompany = null;
            if ($selectedCompany && isset($localCompanies[$selectedCompany['id']])) {
                $selectedLocalCompany = $localCompanies[$selectedCompany['id']];
            } elseif (count($localCompanies) === 1) {
                $selectedLocalCompany = reset($localCompanies);
            }

            return [$user->fresh(), $selectedLocalCompany];
        });
    }

    protected function resolveDisplayName(array $userData): string
    {
        $firstName = trim($userData['firstName'] ?? '');
        $lastName = trim($userData['lastName'] ?? '');
        $displayName = trim($userData['displayName'] ?? '');

        if ($displayName !== '') {
            // Handle "Last, First" format
            if (str_contains($displayName, ',')) {
                $parts = array_map('trim', explode(',', $displayName, 2));
                $displayName = ($parts[1] ?? '').' '.$parts[0];
            }

            return trim($displayName);
        }

        if ($firstName !== '' || $lastName !== '') {
            return trim("{$firstName} {$lastName}");
        }

        return $userData['email'] ?? $userData['username'] ?? 'SSO User';
    }

    protected function findOrCreateUser(
        int|string|null $ssoUserId,
        int|string|null $legacySsoId,
        ?string $email,
        string $displayName,
        ?string $username,
        ?string $phoneNumber = null
    ) {
        $userModel = $this->getUserModelClass();
        $user = null;

        // 1. Match by sso_id = new SSO user ID
        if ($ssoUserId) {
            $user = $userModel::where('sso_id', (string) $ssoUserId)->first();
        }

        // 2. Match by sso_id = legacy SSO ID (first login via new SSO)
        if (! $user && $legacySsoId) {
            $user = $userModel::where('sso_id', (string) $legacySsoId)->first();
        }

        // 3. Fallback: match by email
        if (! $user && $email) {
            $user = $userModel::where('email', $email)->first();
        }

        if ($user) {
            $updates = [];

            if ($displayName && $user->name !== $displayName) {
                $updates['name'] = $displayName;
            }

            if ($email && $user->email !== $email) {
                $updates['email'] = $email;
            }

            // Always update sso_id to the new SSO user ID
            if ($ssoUserId && $user->sso_id !== (string) $ssoUserId) {
                $updates['sso_id'] = (string) $ssoUserId;
            }

            if ($username && ($user->sso_username ?? null) !== $username) {
                $updates['sso_username'] = $username;
            }

            if ($user->isFillable('phone_number') && ($user->phone_number ?? null) !== $phoneNumber) {
                $updates['phone_number'] = $phoneNumber;
            }

            $updates['last_login_at'] = now();

            $user->forceFill($updates)->save();

            return $user;
        }

        // Create new user
        $createAttributes = [
            'name' => $displayName,
            'email' => $email ?? ($username ? "{$username}@sso.local" : Str::uuid().'@sso.local'),
            'password' => bcrypt(Str::random(40)),
            'sso_id' => $ssoUserId ? (string) $ssoUserId : null,
            'sso_username' => $username,
        ];

        $tempInstance = new $userModel;
        if ($phoneNumber !== null && $tempInstance->isFillable('phone_number')) {
            $createAttributes['phone_number'] = $phoneNumber;
        }

        $user = $userModel::create($createAttributes);

        $user->forceFill([
            'email_verified_at' => now(),
            'last_login_at' => now(),
        ])->save();

        return $user;
    }

    /**
     * Resolve every company in the payload to a local record, bulk-loading
     * existing rows up front so the work stays a handful of queries regardless
     * of how many companies the user belongs to.
     *
     * @param  array<int, array<string, mixed>>  $companies
     * @return array<int|string, object> keyed by the SSO company id
     */
    protected function resolveCompanies(array $companies, $user): array
    {
        $companyModel = $this->getCompanyModelClass();

        $ssoIds = [];
        $tenantIds = [];
        $names = [];
        foreach ($companies as $companyData) {
            if (isset($companyData['id'])) {
                $ssoIds[] = $companyData['id'];
            }
            if (! empty($companyData['legacyTenantId'])) {
                $tenantIds[] = $companyData['legacyTenantId'];
            }
            $names[] = $companyData['name'] ?? 'Unknown Company';
        }

        $bySso = $ssoIds ? $companyModel::whereIn('sso_company_id', $ssoIds)->get()->keyBy('sso_company_id') : collect();
        $byTenant = $tenantIds ? $companyModel::whereIn('core_tenant_id', $tenantIds)->get()->keyBy('core_tenant_id') : collect();
        $byName = $names ? $companyModel::whereIn('name', $names)->get()->keyBy('name') : collect();

        $resolved = [];

        foreach ($companies as $companyData) {
            $ssoCompanyId = $companyData['id'] ?? null;
            $legacyTenantId = $companyData['legacyTenantId'] ?? null;
            $companyName = $companyData['name'] ?? 'Unknown Company';

            $company = null;
            if ($ssoCompanyId && $bySso->has($ssoCompanyId)) {
                $company = $bySso->get($ssoCompanyId);
            }
            if (! $company && $legacyTenantId && $byTenant->has($legacyTenantId)) {
                $company = $byTenant->get($legacyTenantId);
            }
            if (! $company && $byName->has($companyName)) {
                $company = $byName->get($companyName);
            }

            if ($company) {
                $updates = [];
                if ($ssoCompanyId && ($company->sso_company_id ?? null) != $ssoCompanyId) {
                    $updates['sso_company_id'] = $ssoCompanyId;
                }
                if ($legacyTenantId && ! $company->core_tenant_id) {
                    $updates['core_tenant_id'] = $legacyTenantId;
                }
                if ($updates !== []) {
                    $company->forceFill($updates)->save();
                }
            } else {
                $company = $companyModel::create([
                    'name' => $companyName,
                    'owner_id' => $user->id,
                    'sso_company_id' => $ssoCompanyId,
                    'core_tenant_id' => $legacyTenantId,
                ]);

                // Keep the lookup maps current so a later payload entry that
                // matches the same row reuses it instead of inserting twice.
                if ($ssoCompanyId) {
                    $bySso->put($ssoCompanyId, $company);
                }
                if ($legacyTenantId) {
                    $byTenant->put($legacyTenantId, $company);
                }
                $byName->put($companyName, $company);
            }

            if ($ssoCompanyId !== null) {
                $resolved[$ssoCompanyId] = $company;
            }
        }

        return $resolved;
    }

    /**
     * Attach the user to every resolved company in a single existence check
     * plus a single bulk insert.
     *
     * @param  array<int|string, object>  $localCompanies
     */
    protected function attachUserToCompanies($user, array $localCompanies): void
    {
        if ($localCompanies === []) {
            return;
        }

        $companyIds = array_values(array_map(static fn ($company) => $company->id, $localCompanies));

        $alreadyAttached = DB::table('company_user')
            ->where('user_id', $user->id)
            ->whereIn('company_id', $companyIds)
            ->pluck('company_id')
            ->all();
        $alreadyAttached = array_flip($alreadyAttached);

        $now = now();
        $rows = [];
        foreach ($companyIds as $companyId) {
            if (! isset($alreadyAttached[$companyId])) {
                $rows[] = [
                    'company_id' => $companyId,
                    'user_id' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('company_user')->insert($rows);
        }
    }

    /**
     * Sync the user's roles across every company at once: one query to load
     * existing roles, one upsert for the memberships, one delete for the
     * memberships that are no longer granted.
     *
     * @param  array<int, array<string, mixed>>  $companies
     * @param  array<int|string, object>  $localCompanies
     */
    protected function syncRolesForCompanies($user, array $companies, array $localCompanies): void
    {
        $desired = [];
        foreach ($companies as $companyData) {
            $ssoCompanyId = $companyData['id'] ?? null;
            if ($ssoCompanyId === null || ! isset($localCompanies[$ssoCompanyId])) {
                continue;
            }

            $allowed = array_values(array_intersect($companyData['roles'] ?? ['User'], ['Admin', 'User']));
            if ($allowed === []) {
                $allowed = ['User'];
            }

            $desired[$localCompanies[$ssoCompanyId]->id] = $allowed;
        }

        if ($desired === []) {
            return;
        }

        $roleModel = $this->getRoleModelClass();
        $companyIds = array_keys($desired);

        // Use withoutGlobalScopes() to bypass any company/tenant scoping that
        // would add a conflicting WHERE clause during SSO sync.
        $roleMap = [];
        foreach (
            $roleModel::withoutGlobalScopes()
                ->whereIn('company_id', $companyIds)
                ->whereIn('name', ['Admin', 'User'])
                ->get() as $role
        ) {
            $roleMap[$role->company_id][$role->name] = $role->id;
        }

        $now = now();
        $upsertRows = [];
        $keptPairs = [];
        $keptBindings = [];

        foreach ($desired as $companyId => $roleNames) {
            foreach ($roleNames as $roleName) {
                if (! isset($roleMap[$companyId][$roleName])) {
                    $role = $roleModel::withoutGlobalScopes()->create([
                        'name' => $roleName,
                        'guard_name' => 'web',
                        'company_id' => $companyId,
                    ]);
                    $roleMap[$companyId][$roleName] = $role->id;
                }

                $roleId = $roleMap[$companyId][$roleName];
                $upsertRows[] = [
                    'company_id' => $companyId,
                    'user_id' => $user->id,
                    'role_id' => $roleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $keptPairs[] = '(?, ?)';
                $keptBindings[] = $companyId;
                $keptBindings[] = $roleId;
            }
        }

        DB::table('company_user_roles')->upsert($upsertRows, ['company_id', 'user_id', 'role_id'], ['updated_at']);

        // Drop memberships for these companies that are no longer granted.
        DB::table('company_user_roles')
            ->where('user_id', $user->id)
            ->whereIn('company_id', $companyIds)
            ->whereRaw('(company_id, role_id) NOT IN ('.implode(', ', $keptPairs).')', $keptBindings)
            ->delete();
    }

    /**
     * Sync enabled modules from the SSO payload to the local company record.
     *
     * Only updates if the company model has an `enabled_modules` attribute
     * and the SSO payload includes `enabledModules` for this company.
     */
    protected function syncEnabledModules($company, array $companyData): void
    {
        if (! array_key_exists('enabledModules', $companyData)) {
            return;
        }

        if (! $company->isFillable('enabled_modules') && ! $company->hasCast('enabled_modules')) {
            return;
        }

        $modules = $companyData['enabledModules'];

        if ($company->enabled_modules !== $modules) {
            $company->forceFill(['enabled_modules' => $modules])->save();
        }
    }

    /**
     * Override these in app-specific synchronizers if model classes differ.
     */
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
