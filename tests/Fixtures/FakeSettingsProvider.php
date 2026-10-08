<?php

namespace Unified\SsoClient\Tests\Fixtures;

use Unified\SsoClient\Contracts\SettingsProvider;
use Unified\SsoClient\Settings\SettingEntity;
use Unified\SsoClient\Settings\SettingsActor;
use Unified\SsoClient\Settings\SettingsResult;
use Unified\SsoClient\Settings\SettingsSchema;

/**
 * In-memory provider that records what the package hands to apply(), so
 * tests can assert invalid and unknown keys never reach the app.
 */
class FakeSettingsProvider implements SettingsProvider
{
    /** @var array<int, array<string, mixed>> */
    public array $stored = [];

    /** @var array<int, int> sso company ids this app has a local record of */
    public array $provisioned = [42, 43];

    /** @var array<int, array{company: int, patch: array<string, mixed>, actor: SettingsActor}> */
    public array $applyCalls = [];

    /** @var array<string, string> key => reason */
    public array $blockedKeys = [];

    /** @var array<int, string> */
    public array $silentKeys = [];

    public function schema(): SettingsSchema
    {
        return SettingsSchema::make()
            ->group('dispatch', 'Dispatch alerts')
            ->toggle('alerts.pre_pickup', 'Pre-pickup alert')->default(false)->help('Text the crew before pickup.')
            ->number('alerts.pre_pickup_minutes', 'Minutes before pickup')->rules('integer|min:1|max:120')->default(15)
            ->requires('alerts.pre_pickup')
            ->time('alerts.quiet_start', 'Quiet hours start')
            ->group('numbering', 'Incident numbering')
            ->text('numbering.prefix', 'Incident number prefix')->rules('max:6')
            ->select('numbering.reset', 'Reset numbering', ['yearly' => 'Every year', 'never' => 'Never'])->default('yearly')
            ->danger()
            ->multiSelect('numbering.service_types', 'Numbered service types', ['ems' => 'EMS', 'fire' => 'Fire'])
            ->entity('dispatch.default_station', 'Default station', SettingEntity::Station)->requires('station')
            ->textarea('numbering.notes', 'Notes')
            ->group('integrations', 'Integrations')
            ->text('integrations.bryx_token', 'Bryx API token')->rules('min:8')->secret()
            ->default('platform-fallback-key')
            ->group('pay', 'Pay period')->atomic()
            ->select('pay.frequency', 'Pay period frequency', ['weekly' => 'Weekly', 'biweekly' => 'Every two weeks'])
            ->text('pay.start_date', 'Pay period start date')->rules('date');
    }

    public function values(int $ssoCompanyId): ?array
    {
        if (! in_array($ssoCompanyId, $this->provisioned, true)) {
            return null;
        }

        return $this->stored[$ssoCompanyId] ?? [];
    }

    public function apply(int $ssoCompanyId, array $patch, SettingsActor $actor): SettingsResult
    {
        $this->applyCalls[] = ['company' => $ssoCompanyId, 'patch' => $patch, 'actor' => $actor];
        $result = SettingsResult::make();

        foreach ($patch as $key => $value) {
            if (in_array($key, $this->silentKeys, true)) {
                continue;
            }

            if (isset($this->blockedKeys[$key])) {
                $result->blocked($key, $this->blockedKeys[$key]);

                continue;
            }

            $this->stored[$ssoCompanyId][$key] = $value;
            $result->saved($key);
        }

        return $result;
    }
}
