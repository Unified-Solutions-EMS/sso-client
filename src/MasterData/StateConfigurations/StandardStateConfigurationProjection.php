<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\StateConfigurations;

use Unified\SsoClient\MasterData\Support\StandardRecordProjection;

/**
 * The default projection: the package-owned `sso_state_configurations`
 * read model, code lists and call signs as JSON text.
 */
class StandardStateConfigurationProjection extends StandardRecordProjection implements StateConfigurationProjection
{
    public const TABLE = 'sso_state_configurations';

    public function table(): string
    {
        return self::TABLE;
    }

    public function nameColumn(): string
    {
        return 'state';
    }

    public function matchColumns(): array
    {
        return ['state'];
    }

    protected function fields(): array
    {
        return [
            'state' => 'state',
            'protocols' => 'protocols',
            'specialty_service_capabilities' => 'specialty_service_capabilities',
            'billing_status' => 'billing_status',
            'emd_performed' => 'emd_performed',
            'emd_vendor' => 'emd_vendor',
            'patient_monitoring_capabilities' => 'patient_monitoring_capabilities',
            'crew_call_signs' => 'crew_call_signs',
            'dispatch_center' => 'dispatch_center',
            'dispatch_center_phone' => 'dispatch_center_phone',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['protocols', 'specialty_service_capabilities', 'patient_monitoring_capabilities', 'crew_call_signs'];
    }
}
