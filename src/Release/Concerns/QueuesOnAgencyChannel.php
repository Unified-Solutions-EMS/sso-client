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
 *     public function __construct(public int $companyId)
 *     {
 *         $this->onAgencyChannel($companyId);
 *     }
 */
trait QueuesOnAgencyChannel
{
    /**
     * @param  Model|int|string|null  $company  the local company, or its local id
     */
    public function onAgencyChannel(Model|int|string|null $company): static
    {
        $queue = ReleaseChannel::queueForCompany($company);

        if ($queue !== null) {
            $this->onQueue($queue);
        }

        return $this;
    }

    public function onAgencyChannelBySsoCompanyId(int|string|null $ssoCompanyId): static
    {
        $queue = ReleaseChannel::queueForSsoCompanyId($ssoCompanyId);

        if ($queue !== null) {
            $this->onQueue($queue);
        }

        return $this;
    }
}
