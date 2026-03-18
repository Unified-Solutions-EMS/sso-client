<?php

use Illuminate\Support\Facades\Route;
use Unified\SsoClient\Http\SsoCallbackController;

Route::middleware('web')->group(function () {
    $routes = config('sso.routes', []);

    Route::get($routes['redirect'] ?? '/auth/sso/redirect', [SsoCallbackController::class, 'redirect'])
        ->name('sso.redirect');

    Route::get($routes['callback'] ?? '/auth/sso/callback', [SsoCallbackController::class, 'callback'])
        ->name('sso.callback');

    Route::post($routes['logout'] ?? '/auth/sso/logout', [SsoCallbackController::class, 'logout'])
        ->name('sso.logout');
});
