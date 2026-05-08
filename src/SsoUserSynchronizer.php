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

            $localCompanies = [];
            foreach ($companies as $companyData) {
                $company = $this->findOrCreateCompany($companyData, $user);
                if ($company) {
                    $localCompanies[$companyData['id']] = $company;
                    $this->attachUserToCompany($user, $company);
                    $this->syncRoles($user, $company, $companyData['roles'] ?? ['User']);
                    $this->syncEnabledModules($company, $companyData);
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

    protected function findOrCreateCompany(array $companyData, $user)
    {
        $companyModel = $this->getCompanyModelClass();
        $ssoCompanyId = $companyData['id'] ?? null;
        $legacyTenantId = $companyData['legacyTenantId'] ?? null;
        $companyName = $companyData['name'] ?? 'Unknown Company';

        $company = null;

        // 1. Match by sso_company_id
        if ($ssoCompanyId) {
            $company = $companyModel::where('sso_company_id', $ssoCompanyId)->first();
        }

        // 2. Match by core_tenant_id = legacy_tenant_id
        if (! $company && $legacyTenantId) {
            $company = $companyModel::where('core_tenant_id', $legacyTenantId)->first();
        }

        // 3. Fallback: match by name
        if (! $company) {
            $company = $companyModel::where('name', $companyName)->first();
        }

        if ($company) {
            $updates = [];

            // Set sso_company_id if not already set
            if ($ssoCompanyId && ($company->sso_company_id ?? null) != $ssoCompanyId) {
                $updates['sso_company_id'] = $ssoCompanyId;
            }

            // Set core_tenant_id from legacy if not already set
            if ($legacyTenantId && ! $company->core_tenant_id) {
                $updates['core_tenant_id'] = $legacyTenantId;
            }

            if ($updates !== []) {
                $company->forceFill($updates)->save();
            }

            return $company;
        }

        // Create new company
        $company = $companyModel::create([
            'name' => $companyName,
            'owner_id' => $user->id,
            'sso_company_id' => $ssoCompanyId,
            'core_tenant_id' => $legacyTenantId,
        ]);

        return $company;
    }

    protected function attachUserToCompany($user, $company): void
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
            // Use withoutGlobalScopes() to bypass any company/tenant scoping
            // that would add a conflicting WHERE clause during SSO sync
            $role = $roleModel::withoutGlobalScopes()->firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web', 'company_id' => $company->id],
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

        // Remove roles not in the SSO payload for this company
        DB::table('company_user_roles')
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->whereNotIn('role_id', $roleIds)
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
