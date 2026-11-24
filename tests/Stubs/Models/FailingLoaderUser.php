<?php

namespace Unified\SsoClient\Tests\Stubs\Models;

/** Role loading that cannot reach its store (database or cache outage). */
class FailingLoaderUser extends RoleSyncingUser
{
    public function getMorphClass(): string
    {
        return RoleSyncingUser::class;
    }

    public function loadRolesForCompany(int $companyId): void
    {
        throw new \RuntimeException('store unreachable');
    }
}
