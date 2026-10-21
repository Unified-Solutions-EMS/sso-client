<?php

namespace Unified\SsoClient;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Unified\SsoClient\Contracts\SsoUserSynchronizerContract;
use Unified\SsoClient\MasterData\Agency\AgencyProjection;
use Unified\SsoClient\MasterData\Agency\StandardAgencyProjection;
use Unified\SsoClient\MasterData\Contacts\ContactProjection;
use Unified\SsoClient\MasterData\Contacts\StandardContactProjection;
use Unified\SsoClient\MasterData\Devices\DeviceProjection;
use Unified\SsoClient\MasterData\Devices\StandardDeviceProjection;
use Unified\SsoClient\MasterData\Facilities\FacilityProjection;
use Unified\SsoClient\MasterData\Facilities\StandardFacilityProjection;
use Unified\SsoClient\MasterData\Locations\LocationProjection;
use Unified\SsoClient\MasterData\Locations\TextAddressLocationProjection;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Medications\MedicationProjection;
use Unified\SsoClient\MasterData\Medications\MirrorOnlyMedicationProjection;
use Unified\SsoClient\MasterData\Personnel\PersonnelProjection;
use Unified\SsoClient\MasterData\Personnel\StandardPersonnelProjection;
use Unified\SsoClient\MasterData\Procedures\MirrorOnlyProcedureProjection;
use Unified\SsoClient\MasterData\Procedures\ProcedureProjection;
use Unified\SsoClient\MasterData\Rotations\RotationProjection;
use Unified\SsoClient\MasterData\Rotations\StandardRotationProjection;
use Unified\SsoClient\MasterData\StateConfigurations\StandardStateConfigurationProjection;
use Unified\SsoClient\MasterData\StateConfigurations\StateConfigurationProjection;
use Unified\SsoClient\MasterData\Units\StandardUnitProjection;
use Unified\SsoClient\MasterData\Units\UnitProjection;
use Unified\SsoClient\MasterData\Vehicles\StandardVehicleProjection;
use Unified\SsoClient\MasterData\Vehicles\VehicleProjection;
use Unified\SsoClient\Metrics\AiUsage;
use Unified\SsoClient\Metrics\Contracts\MetricContextResolver;
use Unified\SsoClient\Metrics\Metrics;
use Unified\SsoClient\Metrics\Resolvers\EloquentMetricContextResolver;
use Unified\SsoClient\Security\Listeners\RecordAuthenticationSecurityEvents;
use Unified\SsoClient\Security\SecurityEvents;
use Unified\SsoClient\Trial\Contracts\TrialMirrorCleanupPolicy;
use Unified\SsoClient\Trial\DefaultTrialMirrorCleanupPolicy;
use Unified\SsoClient\Trial\TrialContextRelay;

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

        // Locations mirror: Crew-Scheduling's `locations` shape unless the app
        // binds its own projection (CloudPCR's dem_locations).
        $this->app->bindIf(LocationProjection::class, TextAddressLocationProjection::class);

        // Vehicles mirror: a `vehicles` table with the contract's column names
        // unless the app binds its own projection (CloudPCR's dem_vehicles).
        $this->app->bindIf(VehicleProjection::class, StandardVehicleProjection::class);

        // Facilities mirror: a `facilities` table with the contract's column
        // names unless the app binds its own (CloudPCR's dem_facility_entries).
        $this->app->bindIf(FacilityProjection::class, StandardFacilityProjection::class);

        // Medications / procedures mirrors: the package's own flat tables;
        // the app binds a projection to carry the list into its structure
        // (CloudPCR's configuration groups) and back for the push.
        $this->app->bindIf(MedicationProjection::class, MirrorOnlyMedicationProjection::class);
        $this->app->bindIf(ProcedureProjection::class, MirrorOnlyProcedureProjection::class);

        // Personnel lands in the package's sso_personnel read model unless the
        // app binds its own projection (CloudPCR's dem_personnel, HR's
        // personnel_profiles).
        $this->app->bindIf(PersonnelProjection::class, StandardPersonnelProjection::class);

        // Agency information, contacts, devices and state configurations land
        // in the package's sso_* read models unless the app binds its own
        // projection (CloudPCR's dem_agencies, dem_contacts, dem_devices,
        // dem_configurations).
        $this->app->bindIf(AgencyProjection::class, StandardAgencyProjection::class);
        $this->app->bindIf(ContactProjection::class, StandardContactProjection::class);
        $this->app->bindIf(DeviceProjection::class, StandardDeviceProjection::class);
        $this->app->bindIf(StateConfigurationProjection::class, StandardStateConfigurationProjection::class);

        // Units land in the package's sso_units + sso_unit_seats read model
        // unless the app binds its own projection (CAD's units, Crew's
        // resources, Fire's NERIS station units).
        $this->app->bindIf(UnitProjection::class, StandardUnitProjection::class);

        // Rotations land in the package's sso_rotations read model (with its
        // blocks and assignments). Crew Scheduling extends the projection to
        // push its template rotations up.
        $this->app->bindIf(RotationProjection::class, StandardRotationProjection::class);

        // Trial purge: what happens to a mirrored sample row SSO deleted.
        // Apps bind their own policy to veto deletes the database cannot see.
        $this->app->bindIf(TrialMirrorCleanupPolicy::class, DefaultTrialMirrorCleanupPolicy::class);

        $this->app->singleton(TrialContextRelay::class);

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
        $this->app->singleton(AiUsage::class);
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

        $this->publishesMigrations([
            __DIR__.'/../database/master-data/2026_10_09_100000_create_or_extend_locations_mirror.php' => database_path('migrations/2026_10_09_100000_create_or_extend_locations_mirror.php'),
        ], 'sso-master-data-locations');

        $this->publishesMigrations([
            __DIR__.'/../database/master-data/2026_10_11_000000_create_or_extend_vehicles_mirror.php' => database_path('migrations/2026_10_11_000000_create_or_extend_vehicles_mirror.php'),
            __DIR__.'/../database/master-data/2026_10_13_200000_add_sso_removed_at_to_mirror_child_tables.php' => database_path('migrations/2026_10_13_200000_add_sso_removed_at_to_mirror_child_tables.php'),
        ], 'sso-master-data-vehicles');

        $this->publishesMigrations([
            __DIR__.'/../database/master-data/2026_10_11_100000_create_or_extend_facilities_mirror.php' => database_path('migrations/2026_10_11_100000_create_or_extend_facilities_mirror.php'),
        ], 'sso-master-data-facilities');

        $this->publishesMigrations([
            __DIR__.'/../database/master-data/2026_10_11_110000_create_medications_mirror.php' => database_path('migrations/2026_10_11_110000_create_medications_mirror.php'),
        ], 'sso-master-data-medications');

        $this->publishesMigrations([
            __DIR__.'/../database/master-data/2026_10_11_120000_create_procedures_mirror.php' => database_path('migrations/2026_10_11_120000_create_procedures_mirror.php'),
        ], 'sso-master-data-procedures');

        $this->publishesMigrations([
            __DIR__.'/../database/master-data/2026_10_13_000000_create_or_extend_personnel_mirror.php' => database_path('migrations/2026_10_13_000000_create_or_extend_personnel_mirror.php'),
            __DIR__.'/../database/master-data/2026_10_13_200000_add_sso_removed_at_to_mirror_child_tables.php' => database_path('migrations/2026_10_13_200000_add_sso_removed_at_to_mirror_child_tables.php'),
        ], 'sso-master-data-personnel');

        foreach ([
            'agency' => '2026_10_14_100000_create_or_extend_agency_mirror.php',
            'contacts' => '2026_10_14_100100_create_or_extend_contacts_mirror.php',
            'devices' => '2026_10_14_100200_create_or_extend_devices_mirror.php',
            'state-configurations' => '2026_10_14_100300_create_or_extend_state_configurations_mirror.php',
            'units' => '2026_10_16_100000_create_or_extend_units_mirror.php',
            'rotations' => '2026_10_17_100000_create_or_extend_rotations_mirror.php',
        ] as $tag => $file) {
            $this->publishesMigrations([
                __DIR__.'/../database/master-data/'.$file => database_path('migrations/'.$file),
            ], 'sso-master-data-'.$tag);
        }

        // Auto-record failed logins / lockouts / password resets as
        // security events in every consuming app.
        if (config('security.listen_auth_events', true)) {
            Event::subscribe(RecordAuthenticationSecurityEvents::class);
        }

        $this->app->make(TrialContextRelay::class)->register($this->app, $this->app->make('events'));

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
