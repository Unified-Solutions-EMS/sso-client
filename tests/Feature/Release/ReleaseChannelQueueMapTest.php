<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature\Release;

use Illuminate\Bus\PendingBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Unified\SsoClient\MasterData\Personnel\Jobs\PullPersonnelRecord;
use Unified\SsoClient\Release\Concerns\QueuesOnAgencyChannel;
use Unified\SsoClient\Release\Concerns\QueuesOnAgencyChannelWhenDispatched;
use Unified\SsoClient\Release\ReleaseChannel;
use Unified\SsoClient\Tests\Stubs\Models\Company;

/**
 * `sso.release.queue_map`: a beta agency's job goes to the beta queue for
 * the queue it would have used. Without a map nothing changes from before:
 * every beta agency's job goes to `beta`.
 */
class ReleaseChannelQueueMapTest extends ReleaseChannelTestCase
{
    private Company $stableAgency;

    private Company $betaAgency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stableAgency = $this->agency(701);
        $this->betaAgency = $this->agency(702, 'beta');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.default', 'redis');
        $app['config']->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default']);
        $app['config']->set('queue.connections.redis-long', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'long']);
        $app['config']->set('mail.default', 'array');
    }

    private function useMap(string $unmapped = 'beta'): void
    {
        config([
            'sso.release.queue_map' => [
                'default' => 'beta',
                'imports' => 'beta-imports',
                'mail' => 'beta-mail',
                'redis-long' => ['imports' => 'beta-long-imports'],
            ],
            'sso.release.unmapped_queues' => $unmapped,
        ]);
    }

    public function test_without_a_map_every_beta_agency_job_goes_to_beta_whatever_its_queue(): void
    {
        $this->assertSame('beta', (new MapJobQueueFirst($this->betaAgency->id, 'imports'))->queue);
        $this->assertSame('beta', (new MapJobQueueFirst($this->betaAgency->id))->queue);
        $this->assertSame('beta', ReleaseChannel::queueForCompany($this->betaAgency->id, 'availity'));
        $this->assertSame('beta', ReleaseChannel::queueForCompany($this->betaAgency->id));

        $this->assertSame('imports', (new MapJobQueueFirst($this->stableAgency->id, 'imports'))->queue);
        $this->assertNull((new MapJobQueueFirst($this->stableAgency->id))->queue);
    }

    public function test_without_a_map_the_keep_setting_leaves_named_queues_and_moves_the_default(): void
    {
        config(['sso.release.unmapped_queues' => 'keep']);

        $this->assertSame('availity', (new MapJobQueueFirst($this->betaAgency->id, 'availity'))->queue);
        $this->assertSame('beta', (new MapJobQueueFirst($this->betaAgency->id))->queue);
    }

    public function test_a_mapped_queue_goes_to_its_beta_queue(): void
    {
        $this->useMap();

        $this->assertSame('beta-imports', (new MapJobQueueFirst($this->betaAgency->id, 'imports'))->queue);
        $this->assertSame('beta', (new MapJobQueueFirst($this->betaAgency->id))->queue);
        $this->assertSame('beta', (new MapJobQueueFirst($this->betaAgency->id, 'default'))->queue);
        $this->assertSame('beta-imports', ReleaseChannel::queueForSsoCompanyId(702, 'imports'));
    }

    public function test_stable_agencies_keep_their_queue_with_a_map(): void
    {
        $this->useMap('keep');

        $this->assertSame('imports', (new MapJobQueueFirst($this->stableAgency->id, 'imports'))->queue);
        $this->assertNull((new MapJobQueueFirst($this->stableAgency->id))->queue);
    }

    public function test_an_unmapped_named_queue_goes_to_beta_by_default(): void
    {
        $this->useMap();

        $this->assertSame('beta', (new MapJobQueueFirst($this->betaAgency->id, 'availity'))->queue);
    }

    public function test_an_unmapped_named_queue_stays_with_the_keep_setting(): void
    {
        $this->useMap('keep');

        $this->assertSame('availity', (new MapJobQueueFirst($this->betaAgency->id, 'availity'))->queue);
        $this->assertSame('beta-imports', (new MapJobQueueFirst($this->betaAgency->id, 'imports'))->queue);
        $this->assertSame('beta', (new MapJobQueueFirst($this->betaAgency->id))->queue);
    }

    public function test_a_job_already_on_a_beta_queue_stays_there(): void
    {
        $this->useMap('keep');

        $this->assertSame('beta-imports', (new MapJobQueueFirst($this->betaAgency->id, 'beta-imports'))->queue);
        $this->assertSame('beta', (new MapJobQueueFirst($this->betaAgency->id, 'beta'))->queue);
    }

    public function test_an_entry_for_the_jobs_connection_wins_and_the_connection_is_kept(): void
    {
        $this->useMap('keep');

        $long = new MapJobQueueFirst($this->betaAgency->id, 'imports', 'redis-long');
        $this->assertSame('beta-long-imports', $long->queue);
        $this->assertSame('redis-long', $long->connection);

        $default = new MapJobQueueFirst($this->betaAgency->id, 'imports');
        $this->assertSame('beta-imports', $default->queue);
        $this->assertNull($default->connection);

        // The connection's own default queue ('long') has no entry: it is the
        // job's default, so it goes to `beta`, still on its own connection.
        $own = new MapJobQueueFirst($this->betaAgency->id, null, 'redis-long');
        $this->assertSame('beta', $own->queue);
        $this->assertSame('redis-long', $own->connection);
    }

    public function test_the_map_is_inert_when_routing_is_not_active(): void
    {
        $this->useMap();
        config(['sso.release.urls.beta' => null]);
        ReleaseChannel::flushSchemaCache();
        DB::enableQueryLog();

        $this->assertSame('imports', (new MapJobQueueFirst($this->betaAgency->id, 'imports'))->queue);
        $this->assertNull(ReleaseChannel::queueForCompany($this->betaAgency->id, 'imports'));
        $this->assertSame('imports', ReleaseChannel::onAgencyChannel((new MapPlainJob)->onQueue('imports'), $this->betaAgency->id)->queue);

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_a_queue_set_after_the_trait_call_needs_the_dispatch_time_trait(): void
    {
        $this->useMap();
        Queue::fake();

        // With the plain trait the later onQueue() wins (unchanged behaviour).
        $this->assertSame('imports', (new MapJobQueueAfter($this->betaAgency->id))->queue);

        MapJobQueueAfterWhenDispatched::dispatch($this->betaAgency->id);
        MapJobQueueAfterWhenDispatched::dispatch($this->stableAgency->id);

        Queue::assertPushedOn('beta-imports', MapJobQueueAfterWhenDispatched::class);
        Queue::assertPushedOn('imports', MapJobQueueAfterWhenDispatched::class);
    }

    public function test_a_queue_set_at_dispatch_time(): void
    {
        $this->useMap();
        Queue::fake();

        // Dispatch-time trait: order does not matter.
        MapJobWhenDispatched::dispatch($this->betaAgency->id)->onQueue('imports');
        MapJobWhenDispatched::dispatch($this->betaAgency->id)->delay(30)->onQueue('mail');

        // Plain trait: name the agency after the queue (dispatch() forwards both calls to the job).
        MapPlainJob::dispatch()->onQueue('imports')->onAgencyChannel($this->betaAgency->id);

        Queue::assertPushedOn('beta-imports', MapJobWhenDispatched::class);
        Queue::assertPushedOn('beta-mail', MapJobWhenDispatched::class, fn (MapJobWhenDispatched $job): bool => $job->delay === 30);
        Queue::assertPushedOn('beta-imports', MapPlainJob::class);
    }

    public function test_the_dispatch_time_trait_changes_nothing_for_unrouted_agencies(): void
    {
        $this->useMap();
        config(['sso.release.urls.beta' => null]);
        Queue::fake();

        MapJobWhenDispatched::dispatch($this->betaAgency->id)->onQueue('imports');

        Queue::assertPushedOn('imports', MapJobWhenDispatched::class, fn (MapJobWhenDispatched $job): bool => $job->agencyReleaseChannel === null);
    }

    public function test_a_queued_notification_goes_to_its_beta_queue(): void
    {
        $this->useMap();
        Queue::fake();

        NotificationFacade::route('mail', 'pat@example.test')->notify(new MapNotification($this->betaAgency->id, 'mail'));
        NotificationFacade::route('mail', 'pat@example.test')->notify(new MapNotification($this->stableAgency->id, 'mail'));

        Queue::assertPushedOn('beta-mail', SendQueuedNotifications::class);
        Queue::assertPushedOn('mail', SendQueuedNotifications::class);
    }

    public function test_package_jobs_use_the_map(): void
    {
        config(['sso.release.queue_map' => ['default' => 'beta-default']]);

        $this->assertSame('beta-default', (new PullPersonnelRecord($this->betaAgency->id, 702, 900))->queue);
        $this->assertNull((new PullPersonnelRecord($this->stableAgency->id, 701, 900))->queue);
    }

    public function test_the_batch_helper_puts_the_batch_on_the_agencys_queue(): void
    {
        $this->useMap();
        Bus::fake();

        ReleaseChannel::onAgencyChannel(Bus::batch([new MapPlainJob])->onQueue('imports'), $this->betaAgency)->dispatch();
        ReleaseChannel::onAgencyChannel(Bus::batch([new MapPlainJob])->onConnection('redis-long')->onQueue('imports'), $this->betaAgency)->dispatch();
        ReleaseChannel::onAgencyChannelBySsoCompanyId(Bus::batch([new MapPlainJob])->onQueue('imports'), 701)->dispatch();

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->queue() === 'beta-imports' && $batch->connection() === null);
        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->queue() === 'beta-long-imports' && $batch->connection() === 'redis-long');
        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->queue() === 'imports');
    }

    public function test_the_helper_covers_chains_and_plain_jobs(): void
    {
        $this->useMap();
        Queue::fake();

        ReleaseChannel::onAgencyChannel(Bus::chain([new MapPlainJob, new MapPlainJob])->onQueue('imports'), $this->betaAgency)->dispatch();
        dispatch(ReleaseChannel::onAgencyChannel((new MapPlainJob)->onQueue('availity'), $this->betaAgency->id));

        Queue::assertPushedOn('beta-imports', MapPlainJob::class, fn (MapPlainJob $job): bool => $job->chainQueue === 'beta-imports');
        Queue::assertPushedOn('beta', MapPlainJob::class);
    }

    public function test_the_mail_helper_puts_a_queued_mailable_on_the_agencys_queue(): void
    {
        $this->useMap();
        Queue::fake();

        Mail::to('pat@example.test')->queue(ReleaseChannel::onAgencyChannel((new MapMailable)->onQueue('mail'), $this->betaAgency));
        Mail::to('pat@example.test')->queue(ReleaseChannel::onAgencyChannel(new MapMailable, $this->betaAgency));
        Mail::to('pat@example.test')->queue(ReleaseChannel::onAgencyChannel((new MapMailable)->onQueue('mail'), $this->stableAgency));

        Queue::assertPushedOn('beta-mail', SendQueuedMailable::class);
        Queue::assertPushedOn('beta', SendQueuedMailable::class);
        Queue::assertPushedOn('mail', SendQueuedMailable::class);
    }

    public function test_a_mailable_using_the_trait(): void
    {
        $this->useMap();
        Queue::fake();

        Mail::to('pat@example.test')->queue(new MapMailableWithTrait($this->betaAgency->id));

        Queue::assertPushedOn('beta-mail', SendQueuedMailable::class);
    }

    public function test_list_prints_the_map_and_the_beta_workers(): void
    {
        $this->useMap('keep');

        $this->artisan('sso:release-channel', ['--list' => true])
            ->expectsTable(['Connection', 'Queue', 'Beta queue'], [
                ["(the job's own)", 'default', 'beta'],
                ["(the job's own)", 'imports', 'beta-imports'],
                ["(the job's own)", 'mail', 'beta-mail'],
                ['redis-long', 'imports', 'beta-long-imports'],
            ])
            ->expectsOutputToContain('Other named queues: stay where they are')
            ->expectsOutputToContain('php artisan queue:work redis --queue=beta-imports')
            ->expectsOutputToContain('php artisan queue:work redis-long --queue=beta-long-imports')
            ->assertSuccessful();
    }

    public function test_list_without_a_map(): void
    {
        $this->artisan('sso:release-channel', ['--list' => true])
            ->expectsOutputToContain("Queue map: none. Every beta agency's job goes to \"beta\".")
            ->expectsOutputToContain('php artisan queue:work redis --queue=beta')
            ->assertSuccessful();
    }
}

class MapPlainJob implements ShouldQueue
{
    use Dispatchable, Queueable, QueuesOnAgencyChannel;

    public function handle(): void {}
}

class MapJobQueueFirst implements ShouldQueue
{
    use Dispatchable, Queueable, QueuesOnAgencyChannel;

    public function __construct(int $companyId, ?string $queue = null, ?string $connection = null)
    {
        if ($connection !== null) {
            $this->onConnection($connection);
        }

        if ($queue !== null) {
            $this->onQueue($queue);
        }

        $this->onAgencyChannel($companyId);
    }

    public function handle(): void {}
}

class MapJobQueueAfter implements ShouldQueue
{
    use Dispatchable, Queueable, QueuesOnAgencyChannel;

    public function __construct(int $companyId)
    {
        $this->onAgencyChannel($companyId);
        $this->onQueue('imports');
    }

    public function handle(): void {}
}

class MapJobQueueAfterWhenDispatched implements ShouldQueue
{
    use Dispatchable, Queueable, QueuesOnAgencyChannelWhenDispatched;

    public function __construct(int $companyId)
    {
        $this->onAgencyChannel($companyId);
        $this->onQueue('imports');
    }

    public function handle(): void {}
}

class MapJobWhenDispatched implements ShouldQueue
{
    use Dispatchable, Queueable, QueuesOnAgencyChannelWhenDispatched;

    public function __construct(int $companyId)
    {
        $this->onAgencyChannel($companyId);
    }

    public function handle(): void {}
}

class MapNotification extends Notification implements ShouldQueue
{
    use Queueable, QueuesOnAgencyChannel;

    public function __construct(int $companyId, string $queue)
    {
        $this->onQueue($queue);
        $this->onAgencyChannel($companyId);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->line('Hello');
    }
}

class MapMailable extends Mailable
{
    use Queueable;

    public function build(): static
    {
        return $this->html('<p>Hello</p>');
    }
}

class MapMailableWithTrait extends Mailable
{
    use Queueable, QueuesOnAgencyChannel;

    public function __construct(int $companyId)
    {
        $this->onQueue('mail');
        $this->onAgencyChannel($companyId);
    }

    public function build(): static
    {
        return $this->html('<p>Hello</p>');
    }
}
