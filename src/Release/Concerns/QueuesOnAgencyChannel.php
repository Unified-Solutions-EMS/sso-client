<?php

declare(strict_types=1);

namespace Unified\SsoClient\Release\Concerns;

use Illuminate\Database\Eloquent\Model;
use Unified\SsoClient\Release\ReleaseChannel;

/**
 * For a queued job, notification or mailable about one agency: puts it on
 * the queue of that agency's release channel, so a beta agency's work runs
 * on beta's worker. Needs Laravel's `Queueable` (it calls `onQueue()`).
 *
 * Stable agencies, a missing agency, and any app where release channels are
 * not set up leave the queue exactly as it was, so the job's own `$queue`
 * (or the connection default) still applies there.
 *
 * The beta queue is chosen from the queue the job is on when this is
 * called (`sso.release.queue_map`), so call it after `onQueue()`:
 *
 *     public function __construct(public int $companyId)
 *     {
 *         $this->onQueue('imports');
 *         $this->onAgencyChannel($companyId);
 *     }
 *
 * A job whose queue is set later (after this call in the constructor, or
 * `->onQueue()` at dispatch) uses `QueuesOnAgencyChannelWhenDispatched`.
 */
trait QueuesOnAgencyChannel
{
    /**
     * @param  Model|int|string|null  $company  the local company, or its local id
     */
    public function onAgencyChannel(Model|int|string|null $company): static
    {
        if (ReleaseChannel::routingActive()) {
            $this->putOnReleaseChannel(ReleaseChannel::forCompany($company));
        }

        return $this;
    }

    public function onAgencyChannelBySsoCompanyId(int|string|null $ssoCompanyId): static
    {
        if (ReleaseChannel::routingActive()) {
            $this->putOnReleaseChannel(ReleaseChannel::forSsoCompanyId($ssoCompanyId));
        }

        return $this;
    }

    protected function putOnReleaseChannel(string $channel): void
    {
        if (property_exists($this, 'agencyReleaseChannel')) {
            $this->agencyReleaseChannel = $channel;
        }

        ReleaseChannel::applyChannel($this, $channel);
    }
}
