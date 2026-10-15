<?php

namespace Unified\SsoClient\Tests\Feature\MasterData;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * mergeConfigFrom() is shallow: an app that publishes config/sso.php with its
 * own `master_data` array replaces the package's array wholesale. Every
 * reader must therefore default per key, so an app listing only the
 * entities it uses still gets "off" for the rest and the package defaults
 * for the tuning keys.
 */
class PartialMasterDataConfigTest extends MasterDataTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.master_data', ['divisions' => true]);
    }

    public function test_entities_missing_from_a_partial_override_read_as_disabled(): void
    {
        $registry = app(MasterDataRegistry::class);

        $this->assertTrue($registry->enabled('divisions'));

        foreach (['qualifications', 'locations', 'vehicles', 'facilities', 'medications', 'procedures', 'agency', 'contacts', 'devices', 'state_configurations'] as $entity) {
            $this->assertFalse($registry->enabled($entity), "{$entity} should default to disabled");
        }
    }

    public function test_a_partial_override_still_schedules_the_nightly_resync_for_its_entities(): void
    {
        $this->app->forgetInstance(Schedule::class);

        $commands = array_values(array_filter(
            array_map(fn (Event $event): string => (string) $event->command, $this->app->make(Schedule::class)->events()),
            fn (string $command): bool => str_contains($command, 'sso:resync-master-data'),
        ));

        $this->assertCount(1, $commands);
        $this->assertStringContainsString("sso:resync-master-data 'divisions'", $commands[0]);
    }

    public function test_a_disabled_entity_from_a_partial_override_acknowledges_its_webhooks(): void
    {
        $this->company(70);

        $this->postWebhook('qualification.created', [
            'company' => ['id' => 70],
            'qualification' => $this->qualificationRecord(502, 'Driver'),
        ])->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(0, DB::table('qualifications')->count());
    }
}
