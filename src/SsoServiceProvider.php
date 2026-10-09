<?php

namespace Unified\SsoClient;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Unified\SsoClient\Contracts\SsoUserSynchronizerContract;
use Unified\SsoClient\MasterData\MasterDataRegistry;
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
            $this->commands([
                Console\PurgeFakeUsersCommand::class,
                MasterData\Console\ResyncMasterDataCommand::class,
                MasterData\Console\PushMasterDataCommand::class,
            ]);
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

        // Master-data mirror migrations are opt-in per app and per entity, so
        // they are published rather than loaded, one tag each.
        $this->publishesMigrations([
            __DIR__.'/../database/master-data/2026_10_06_000000_add_sso_mirror_columns_to_qualifications_table.php' => database_path('migrations/2026_10_06_000000_add_sso_mirror_columns_to_qualifications_table.php'),
        ], 'sso-master-data');

        $this->publishesMigrations([
            __DIR__.'/../database/master-data/2026_10_09_000000_create_or_extend_divisions_mirror.php' => database_path('migrations/2026_10_09_000000_create_or_extend_divisions_mirror.php'),
        ], 'sso-master-data-divisions');

        // Auto-record failed logins / lockouts / password resets as
        // security events in every consuming app.
        if (config('security.listen_auth_events', true)) {
            Event::subscribe(RecordAuthenticationSecurityEvents::class);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $this->scheduleMasterDataResync($schedule);
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sso');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/sso'),
        ], 'sso-views');

        $this->loadRoutesFrom(__DIR__.'/../routes/sso.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/settings.php');

        $router = $this->app->make('router');
        $router->aliasMiddleware('sso.session', Middleware\EnsureSsoSessionIsFresh::class);
        $router->aliasMiddleware('sso.api', Middleware\SsoApiAuthenticate::class);
        $router->aliasMiddleware('sso.session-actions', Middleware\EnforceSsoSessionActions::class);
        $router->aliasMiddleware('sso.purge-legacy-cookies', Middleware\PurgeLegacyApexCookies::class);
        $router->aliasMiddleware('metrics.session', Metrics\Middleware\TrackSessionMetric::class);

        // Auto-register the session-actions middleware in the `web` group
        // so every authenticated route in every consuming app picks up
        // pending impersonation / logout actions on the next request
        // without each app having to wire it manually.
        //
        // Same treatment for the legacy cookie scrub: the parent-domain
        // cookies the old ASP.NET app left behind are sent to every app on
        // the platform, so every app has to be the one that clears them.
        $kernel = $this->app->make(HttpKernel::class);
        if (method_exists($kernel, 'appendMiddlewareToGroup')) {
            $kernel->appendMiddlewareToGroup('web', Middleware\EnforceSsoSessionActions::class);
            $kernel->appendMiddlewareToGroup('web', Middleware\PurgeLegacyApexCookies::class);
        }
    }

    /**
     * Nightly healer per enabled master-data entity. Webhooks dropped because
     * the user or company did not exist locally yet are otherwise only healed
     * by that user's next login, and scheduled crew may never log in.
     */
    protected function scheduleMasterDataResync(Schedule $schedule): void
    {
        if (! config('sso.master_data.schedule_resync', true)) {
            return;
        }

        $registry = $this->app->make(MasterDataRegistry::class);

        foreach ($registry->entities() as $entity) {
            if ($registry->enabled($entity)) {
                $schedule->command('sso:resync-master-data', [$entity])
                    ->dailyAt('03:15')
                    ->withoutOverlapping();
            }
        }
    }
}
