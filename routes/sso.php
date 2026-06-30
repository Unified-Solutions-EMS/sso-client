<?php

use Illuminate\Support\Facades\Route;
use Unified\SsoClient\Http\AgencyStatus\AgencyStatusController;
use Unified\SsoClient\Http\Device\DeviceHandshakeController;
use Unified\SsoClient\Http\SsoActionController;
use Unified\SsoClient\Http\SsoCallbackController;
use Unified\SsoClient\Http\SsoDashboardController;
use Unified\SsoClient\Http\SsoWebhookController;
use Unified\SsoClient\Middleware\ValidateCoreApiKey;

Route::middleware('web')->group(function () {
    $routes = config('sso.routes', []);

    Route::get($routes['redirect'] ?? '/auth/sso/redirect', [SsoCallbackController::class, 'redirect'])
        ->name('sso.redirect');

    Route::get($routes['callback'] ?? '/auth/sso/callback', [SsoCallbackController::class, 'callback'])
        ->name('sso.callback');

    Route::post($routes['logout'] ?? '/auth/sso/logout', [SsoCallbackController::class, 'logout'])
        ->name('sso.logout');

    // Device-handshake endpoints the locked interstitial calls. Same-origin,
    // authenticated (checked in the controller), and never gated by the
    // device-lock middleware themselves.
    Route::prefix('sso/device')->name('sso.device.')->group(function () {
        Route::post('challenge', [DeviceHandshakeController::class, 'challenge'])->name('challenge');
        Route::post('verify', [DeviceHandshakeController::class, 'verify'])->name('verify');
        Route::post('register', [DeviceHandshakeController::class, 'register'])->name('register');
    });
});

// Webhook endpoint — no CSRF, no auth; signature-verified in controller
Route::post('/api/sso/provision', [SsoWebhookController::class, 'handle'])
    ->name('sso.webhook');

// Dashboard data endpoint — signature-verified in controller
Route::post('/api/sso/dashboard', SsoDashboardController::class)
    ->name('sso.dashboard');

// Action endpoint — signature-verified in controller
Route::post('/api/sso/actions/{action}', SsoActionController::class)
    ->name('sso.action');

// Agency-status endpoint — CORE_APP_API_KEY auth. App binds AgencyStatusProvider
// in its AppServiceProvider; SSO MCP composes responses across apps for Fin /
// coding agents. Returns 200 with isActive=false when the company has no
// presence in this app — that's a normal answer, not an error.
Route::middleware(ValidateCoreApiKey::class)
    ->get('/api/internal/agency-status/{ssoCompanyId}', AgencyStatusController::class)
    ->name('sso.agency-status');
