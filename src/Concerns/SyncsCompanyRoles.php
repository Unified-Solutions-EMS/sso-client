<?php

namespace Unified\SsoClient\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

/**
 * Shared company-scoped role handling for downstream apps that consume SSO
 * roles via spatie/laravel-permission. Replaces the hand-rolled
 * loadRolesForCompany()/hasRoleInCompany() copies previously duplicated in
 * each app's User model.
 *
 * Assumes the using model also uses Spatie's HasRoles trait and that the app
 * has a company-scoped `company_user_roles` pivot (company_id, user_id,
 * role_id) populated by the SSO synchronizer/webhook handlers.
 *
 * @mixin Model
 * @mixin HasRoles
 */
trait SyncsCompanyRoles
{
    public function initializeSyncsCompanyRoles(): void
    {
        $this->mergeCasts(['staff_roles' => 'array']);
    }

    /**
     * Hydrate Spatie's role state for the active tenant. Call after login and
     * after any company switch / impersonation so hasRole()/can() answer for
     * the right company.
     */
    public function loadRolesForCompany(int $companyId): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roleModel = $this->companyRoleModelClass();

        $roleIds = DB::table('company_user_roles')
            ->where('user_id', $this->getKey())
            ->where('company_id', $companyId)
            ->pluck('role_id');

        $roles = $roleModel::withoutGlobalScopes()->whereIn('id', $roleIds)->get();

        $this->syncRoles($roles);
    }

    public function hasRoleInCompany(string $roleName, int $companyId): bool
    {
        return DB::table('company_user_roles')
            ->join('roles', 'roles.id', '=', 'company_user_roles.role_id')
            ->where('company_user_roles.user_id', $this->getKey())
            ->where('company_user_roles.company_id', $companyId)
            ->where('roles.name', $roleName)
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    public function companyRoleNames(int $companyId): array
    {
        return DB::table('company_user_roles')
            ->join('roles', 'roles.id', '=', 'company_user_roles.role_id')
            ->where('company_user_roles.user_id', $this->getKey())
            ->where('company_user_roles.company_id', $companyId)
            ->pluck('roles.name')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function staffRoleSlugs(): array
    {
        $value = $this->staff_roles;

        if (is_string($value)) {
            $value = json_decode($value, true) ?: [];
        }

        return is_array($value) ? array_values($value) : [];
    }

    public function isStaff(): bool
    {
        return $this->staffRoleSlugs() !== [];
    }

    public function hasStaffRole(string $slug): bool
    {
        return in_array($slug, $this->staffRoleSlugs(), true);
    }

    public function isGlobalAdmin(): bool
    {
        return $this->hasStaffRole('global-admin');
    }

    protected function companyRoleModelClass(): string
    {
        return config('permission.models.role', Role::class);
    }
}
