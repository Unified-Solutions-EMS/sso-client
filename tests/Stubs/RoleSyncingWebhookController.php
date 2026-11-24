<?php

namespace Unified\SsoClient\Tests\Stubs;

use Unified\SsoClient\Http\SsoWebhookController;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\RoleSyncingUser;
use Unified\SsoClient\Tests\Stubs\Models\SpatieRole;

/** The package controller with an app's Spatie-backed models in place of App\Models\*. */
class RoleSyncingWebhookController extends SsoWebhookController
{
    protected function getUserModelClass(): string
    {
        return RoleSyncingUser::class;
    }

    protected function getCompanyModelClass(): string
    {
        return Company::class;
    }

    protected function getRoleModelClass(): string
    {
        return SpatieRole::class;
    }
}
