<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\StateConfigurations;

use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;
use Unified\SsoClient\MasterData\ProjectedMirror;

/**
 * Mirrors SSO's state configurations (one row per state the agency reports
 * to; a single state today) into the app's configuration table through the
 * bound StateConfigurationProjection. ProjectedMirror has the rules: link
 * by `sso_state_configuration_id`, adopt an unlinked local row by state,
 * stale guard, kept values, turn off never delete,
 * `state_configuration.*` events and the push.
 */
class StateConfigurationMirror extends ProjectedMirror
{
    public const SSO_ID_COLUMN = 'sso_state_configuration_id';

    public function __construct(LocalTenantResolver $tenants, MasterDataClient $client, StateConfigurationProjection $projection)
    {
        parent::__construct($tenants, $client, $projection);
    }

    public function entity(): string
    {
        return 'state_configurations';
    }

    public static function events(): array
    {
        return [
            'state_configuration.created',
            'state_configuration.updated',
            'state_configuration.deactivated',
            'state_configuration.batch_updated',
        ];
    }

    public function ssoIdColumn(): string
    {
        return self::SSO_ID_COLUMN;
    }

    protected function recordKey(): string
    {
        return 'state_configuration';
    }

    protected function recordName(array $record): string
    {
        return trim((string) ($record['state'] ?? ''));
    }
}
