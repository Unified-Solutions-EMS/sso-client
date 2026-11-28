<?php

declare(strict_types=1);

namespace Unified\SsoClient\Release\Concerns;

use Illuminate\Contracts\Queue\Queue;

/**
 * For a queued JOB (not a mailable or notification) whose queue may be set
 * after `onAgencyChannel()`: in the constructor after the call, or with
 * `->onQueue()` when it is dispatched. The agency's channel is remembered
 * and the beta queue is chosen again from the job's final queue when
 * Laravel's bus pushes it, so the order does not matter.
 *
 *     ImportClaims::dispatch($company)->onQueue('imports');
 *
 * Works for everything dispatched through the bus: `dispatch()`,
 * `Job::dispatch()`, `Bus::dispatch()`, `Bus::chain()` and the next job of
 * a chain. Not for `Queue::push()` (no bus) or a job in a `Bus::batch()`
 * (the batch decides the queue: use `ReleaseChannel::onAgencyChannel()` on
 * the batch). Do not use it on a mailable: `Mailable::queue()` is a
 * different method with the same name.
 */
trait QueuesOnAgencyChannelWhenDispatched
{
    use QueuesOnAgencyChannel;

    /** The agency's release channel once known; null = not asked or not routed. */
    public ?string $agencyReleaseChannel = null;

    /**
     * Laravel's bus calls this in place of pushing the job itself, with the
     * job's own connection already resolved.
     *
     * @param  Queue  $queue
     */
    public function queue($queue, mixed $command): mixed
    {
        if ($command === $this && $this->agencyReleaseChannel !== null) {
            $this->putOnReleaseChannel($this->agencyReleaseChannel);
        }

        if (isset($command->delay)) {
            return $queue->later($command->delay, $command, '', $command->queue ?? null);
        }

        return $queue->push($command, '', $command->queue ?? null);
    }
}
