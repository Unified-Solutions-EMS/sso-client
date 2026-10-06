<?php

use Illuminate\Support\Facades\Route;
use Unified\SsoClient\Middleware\ValidateCoreApiKey;
use Unified\SsoClient\Settings\Http\SettingsController;

// Settings rail — CORE_APP_API_KEY auth. App binds SettingsProvider in its
// AppServiceProvider; SSO's central Settings page reads the schema + values
// and PATCHes partial changes. No provider bound → 200 with supported=false.
Route::middleware(ValidateCoreApiKey::class)
    ->prefix('/api/internal/settings')
    ->group(function (): void {
        Route::get('{ssoCompanyId}', [SettingsController::class, 'show'])
            ->whereNumber('ssoCompanyId')
            ->name('sso.settings.show');

        Route::patch('{ssoCompanyId}', [SettingsController::class, 'update'])
            ->whereNumber('ssoCompanyId')
            ->name('sso.settings.update');
    });
