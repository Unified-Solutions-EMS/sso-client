<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData;

use Illuminate\Support\Facades\Log;

/**
 * Routes master-data webhook events (qualification.*, user.qualifications_changed)
 * to the matching mirror.
 *
 * Every outcome other than a handler crash is a 200 acknowledgement: an entity
 * the app has not opted into, a mirror migration that has not run yet, or a
 * company this app has never seen are all normal states, and `sso:resync-master-data`
 * heals anything skipped here.
 */
class MasterDataWebhookHandler
{
    public function __construct(
        private readonly MasterDataRegistry $registry,
        private readonly LocalTenantResolver $tenants,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(string $event, array $payload): array
    {
        $entity = $this->registry->entityForEvent($event);

        if ($entity === null) {
            return ['status' => 'ignored', 'action' => $event, 'reason' => 'unknown_event'];
        }

        if (! $this->registry->enabled($entity)) {
            return ['status' => 'ignored', 'action' => $event, 'reason' => "master_data.{$entity} disabled"];
        }

        $mirror = $this->registry->mirror($entity);

        if (! $mirror->isInstalled()) {
            Log::warning('SSO master data: mirror migration not run, webhook skipped', [
                'event' => $event,
                'entity' => $entity,
            ]);

            return ['status' => 'skipped', 'action' => $event, 'reason' => 'mirror_not_installed'];
        }

        $ssoCompanyId = $payload['company']['id'] ?? null;
        $localCompanyId = $this->tenants->companyId(is_int($ssoCompanyId) || is_string($ssoCompanyId) ? $ssoCompanyId : null);

        if ($localCompanyId === null) {
            return ['status' => 'skipped', 'action' => $event, 'reason' => 'company_not_found'];
        }

        return ['action' => $event] + $mirror->applyWebhook($event, $localCompanyId, $payload);
    }
}
