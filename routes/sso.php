<?php

use Illuminate\Support\Facades\Route;
use Unified\SsoClient\Http\SsoCallbackController;
use Unified\SsoClient\Http\SsoDashboardController;
use Unified\SsoClient\Http\SsoWebhookController;

Route::middleware('web')->group(function () {
    $routes = config('sso.routes', []);

    Route::get($routes['redirect'] ?? '/auth/sso/redirect', [SsoCallbackController::class, 'redirect'])
        ->name('sso.redirect');

    Route::get($routes['callback'] ?? '/auth/sso/callback', [SsoCallbackController::class, 'callback'])
        ->name('sso.callback');

    Route::post($routes['logout'] ?? '/auth/sso/logout', [SsoCallbackController::class, 'logout'])
        ->name('sso.logout');
});

// Webhook endpoint — no CSRF, no auth; signature-verified in controller
Route::post('/api/sso/provision', [SsoWebhookController::class, 'handle'])
    ->name('sso.webhook');

// Dashboard data endpoint — signature-verified in controller
Route::post('/api/sso/dashboard', SsoDashboardController::class)
    ->name('sso.dashboard');
