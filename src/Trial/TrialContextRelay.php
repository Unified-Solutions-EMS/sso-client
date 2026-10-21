<?php

declare(strict_types=1);

namespace Unified\SsoClient\Trial;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Unified\SsoClient\Contracts\ReceivesTrialContext;

/**
 * Carries the trial context into the app's own `App\Jobs\RunTrialSeeder`,
 * whose constructor takes only `(company, userSsoIds, adminSsoId)` and
 * whose `handle()` resolves the seeder from the container in a later
 * process.
 *
 * The context rides in the queued payload of that one job (never on any
 * other job), and while a worker runs it every seeder implementing
 * {@see ReceivesTrialContext} that the container resolves is handed it.
 * Works on a sync queue too: the job runs inside send().
 */
class TrialContextRelay
{
    public const PAYLOAD_KEY = 'ssoTrialContext';

    private bool $sending = false;

    /** @var array<string, mixed>|null */
    private ?array $outgoing = null;

    private bool $running = false;

    private ?TrialContext $current = null;

    public function register(Container $container, Dispatcher $events): void
    {
        Queue::createPayloadUsing(fn (mixed $connection, mixed $queue, array $payload): array => $this->outgoingPayload($payload));

        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->receive($event->job->payload());
        });

        $events->listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], function (JobProcessed|JobFailed|JobExceptionOccurred $event): void {
            if (self::isSeederJob($event->job->payload())) {
                $this->running = false;
                $this->current = null;
            }
        });

        $container->afterResolving(ReceivesTrialContext::class, function (ReceivesTrialContext $seeder): void {
            if ($this->running) {
                $seeder->withTrialContext($this->current);
            }
        });
    }

    /**
     * Runs the dispatch of the app's seeder job with the context in its
     * payload. The dispatch must complete inside the callable (a
     * PendingDispatch sends on destruct, so do not return it).
     *
     * @param  callable(): void  $dispatch
     */
    public function send(?TrialContext $context, callable $dispatch): void
    {
        $this->sending = true;
        $this->outgoing = $context?->toArray();

        try {
            $dispatch();
        } finally {
            $this->sending = false;
            $this->outgoing = null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function outgoingPayload(array $payload): array
    {
        return $this->sending && $this->outgoing !== null && self::isSeederJob($payload)
            ? [self::PAYLOAD_KEY => $this->outgoing]
            : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function receive(array $payload): void
    {
        if (! self::isSeederJob($payload)) {
            return;
        }

        $context = $payload[self::PAYLOAD_KEY] ?? null;

        $this->running = true;
        $this->current = is_array($context) ? TrialContext::fromArray($context) : null;
    }

    /**
     * `commandName` is still the job object while the payload is created,
     * and its class name once the payload is stored.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function isSeederJob(array $payload): bool
    {
        $command = $payload['data']['commandName'] ?? null;

        return (is_object($command) || is_string($command))
            && class_exists(AppTrialJobs::SEEDER_JOB)
            && is_a($command, AppTrialJobs::SEEDER_JOB, true);
    }
}
