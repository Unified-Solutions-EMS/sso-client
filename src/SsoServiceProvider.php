<?php

namespace Unified\SsoClient;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Unified\SsoClient\Contracts\SsoUserSynchronizerContract;
use Unified\SsoClient\Metrics\Contracts\MetricContextResolver;
use Unified\SsoClient\Metrics\Metrics;
use Unified\SsoClient\Metrics\Resolvers\EloquentMetricContextResolver;
use Unified\SsoClient\Security\Listeners\RecordAuthenticationSecurityEvents;
use Unified\SsoClient\Security\SecurityEvents;

class SsoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sso.php', 'sso');
        $this->mergeConfigFrom(__DIR__.'/../config/metrics.php', 'metrics');
        $this->mergeConfigFrom(__DIR__.'/../config/security.php', 'security');

        $this->app->singleton(SsoClient::class);
        $this->app->singleton(SsoSessionState::class);

        $this->app->bindIf(SsoUserSynchronizerContract::class, SsoUserSynchronizer::class);

        // Metrics — apps can override by binding their own
        // MetricContextResolver implementation in AppServiceProvider.
        $this->app->bindIf(MetricContextResolver::class, function (): MetricContextResolver {
            return new EloquentMetricContextResolver(
                companyModel: (string) config('metrics.company_model'),
                userModel: (string) config('metrics.user_model'),
                companySsoIdColumn: (string) config('metrics.company_sso_id_column', 'sso_company_id'),
                userSsoIdColumn: (string) config('metrics.user_sso_id_column', 'sso_id'),
            );
        });

        $this->app->singleton(Metrics::class);
        $this->app->singleton(SecurityEvents::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Console\PurgeFakeUsersCommand::class]);
        }

        $this->publishes([
            __DIR__.'/../config/sso.php' => config_path('sso.php'),
        ], 'sso-config');

        $this->publishes([
            __DIR__.'/../config/metrics.php' => config_path('metrics.php'),
        ], 'metrics-config');

        $this->publishes([
            __DIR__.'/../config/security.php' => config_path('security.php'),
        ], 'security-config');

        // Auto-record failed logins / lockouts / password resets as
        // security events in every consuming app.
        if (config('security.listen_auth_events', true)) {
            Event::subscribe(RecordAuthenticationSecurityEvents::class);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadRoutesFrom(__DIR__.'/../routes/sso.php');

        $router = $this->app->make('router');
        $router->aliasMiddleware('sso.session', Middleware\EnsureSsoSessionIsFresh::class);
        $router->aliasMiddleware('sso.api', Middleware\SsoApiAuthenticate::class);
        $router->aliasMiddleware('sso.session-actions', Middleware\EnforceSsoSessionActions::class);
        $router->aliasMiddleware('metrics.session', Metrics\Middleware\TrackSessionMetric::class);

        // Auto-register the session-actions middleware in the `web` group
        // so every authenticated route in every consuming app picks up
        // pending impersonation / logout actions on the next request
        // without each app having to wire it manually.
        $kernel = $this->app->make(HttpKernel::class);
        if (method_exists($kernel, 'appendMiddlewareToGroup')) {
            $kernel->appendMiddlewareToGroup('web', Middleware\EnforceSsoSessionActions::class);
        }
    }
}
