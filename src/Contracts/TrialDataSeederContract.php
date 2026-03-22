<?php

namespace Unified\SsoClient\Contracts;

interface TrialDataSeederContract
{
    /**
     * Seed realistic sample data for a trial company.
     *
     * @param  mixed  $company  The local Company model instance.
     * @param  array<string>  $userSsoIds  SSO UUIDs for all trial users (admin + fake users).
     * @param  string  $adminSsoId  SSO UUID of the admin user.
     */
    public function seed(mixed $company, array $userSsoIds, string $adminSsoId): void;
}
