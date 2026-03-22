<?php

namespace Unified\SsoClient\Contracts;

interface TrialDataPurgerContract
{
    /**
     * Purge all trial data for a company, keeping only the admin user.
     *
     * @param  mixed  $company  The local Company model instance.
     * @param  string  $adminSsoId  SSO UUID of the admin user to preserve.
     */
    public function purge(mixed $company, string $adminSsoId): void;
}
