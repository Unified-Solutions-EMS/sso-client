<?php

namespace Unified\SsoClient\Tests\Stubs\Models;

/** An app that keeps its own loadRolesForCompany() next to the trait (Billing, Truck-Checks). */
class OwnLoaderUser extends RoleSyncingUser
{
    /** @var list<int> */
    public static array $loads = [];

    public function loadRolesForCompany(int $companyId): void
    {
        static::$loads[] = $companyId;

        $this->refreshRolesForCompany($companyId);
    }
}
