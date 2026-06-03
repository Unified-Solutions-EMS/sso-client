<?php

namespace Unified\SsoClient\Tests\Stubs;

use Unified\SsoClient\SsoUserSynchronizer;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\Role;
use Unified\SsoClient\Tests\Stubs\Models\User;

/**
 * Points the real synchronizer at the test stub models. Apps do the same
 * thing in production by overriding these three methods.
 */
class StubSynchronizer extends SsoUserSynchronizer
{
    protected function getUserModelClass(): string
    {
        return User::class;
    }

    protected function getCompanyModelClass(): string
    {
        return Company::class;
    }

    protected function getRoleModelClass(): string
    {
        return Role::class;
    }
}
