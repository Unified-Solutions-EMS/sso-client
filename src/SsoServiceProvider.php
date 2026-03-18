<?php

namespace Unified\SsoClient;

use Illuminate\Support\ServiceProvider;
use Unified\SsoClient\Contracts\SsoUserSynchronizerContract;

class SsoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sso.php', 'sso');

        $this->app->singleton(SsoClient::class);
        $this->app->singleton(SsoSessionState::class);

        // Bind the default synchronizer; apps can override in their own ServiceProvider
        $this->app->bindIf(SsoUserSynchronizerContract::class, SsoUserSynchronizer::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/sso.php' => config_path('sso.php'),
        ], 'sso-config');

        $this->loadRoutesFrom(__DIR__.'/../routes/sso.php');

        // Register middleware aliases
        $router = $this->app->make('router');
        $router->aliasMiddleware('sso.session', Middleware\EnsureSsoSessionIsFresh::class);
        $router->aliasMiddleware('sso.api', Middleware\SsoApiAuthenticate::class);
    }
}
