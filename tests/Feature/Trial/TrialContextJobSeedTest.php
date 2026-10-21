<?php

namespace Unified\SsoClient\Tests\Feature\Trial;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\StubContextRunTrialSeeder;
use Unified\SsoClient\Tests\Stubs\StubTrialDataSeeder;
use Unified\SsoClient\Tests\TestCase;
use Unified\SsoClient\Trial\AppTrialJobs;
use Unified\SsoClient\Trial\TrialContext;

/**
 * An app whose RunTrialSeeder takes `?TrialContext $context = null` as a
 * fourth constructor argument (the documented opt-in): the context is
 * serialized with the job on a real (database) queue and reaches the seeder
 * when a worker runs it. Separate processes, because the other trial tests
 * alias the three-argument job as App\Jobs\RunTrialSeeder.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class TrialContextJobSeedTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(AppTrialJobs::SEEDER)) {
            class_alias(StubTrialDataSeeder::class, AppTrialJobs::SEEDER);
            class_alias(StubContextRunTrialSeeder::class, AppTrialJobs::SEEDER_JOB);
        }

        parent::setUp();

        StubTrialDataSeeder::reset();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        $app['config']->set('queue.failed.driver', 'null');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    private function seedThroughTheQueue(?TrialContext $context): void
    {
        $company = Company::query()->create(['name' => 'Trial 77', 'sso_company_id' => '77']);

        app(AppTrialJobs::class)->seed($company, ['admin-uuid', 'fake-1'], 'admin-uuid', $context);

        $this->assertSame([], StubTrialDataSeeder::$calls, 'queued, not run yet');
        $this->assertSame(1, DB::table('jobs')->count());

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertCount(1, StubTrialDataSeeder::$calls);
    }

    public function test_the_context_round_trips_through_the_queue_payload_to_the_seeder(): void
    {
        $context = TrialContext::fromArray(require __DIR__.'/../../Fixtures/trial_seed_context.php');

        $this->seedThroughTheQueue($context);

        $received = StubTrialDataSeeder::$calls[0]['context'];
        $this->assertInstanceOf(TrialContext::class, $received);
        $this->assertNotSame($context, $received, 'rebuilt by the worker from the stored payload');
        $this->assertEquals($context, $received);
        $this->assertSame(['Medic 1', 'Medic 2', 'Medic 3', 'Engine 1', 'Engine 2', 'Command 1', 'Rescue 1'], $received->vehicleNames());
        $this->assertSame(['admin-uuid', 'fake-1'], StubTrialDataSeeder::$calls[0]['users']);
    }

    public function test_no_context_reaches_the_seeder_as_null(): void
    {
        $this->seedThroughTheQueue(null);

        $this->assertSame([null], StubTrialDataSeeder::$contexts);
    }
}
