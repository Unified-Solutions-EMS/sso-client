<?php

namespace Unified\SsoClient\Tests\Fixtures;

use Unified\SsoClient\Contracts\SettingsProvider;
use Unified\SsoClient\Settings\SettingsActor;
use Unified\SsoClient\Settings\SettingsResult;
use Unified\SsoClient\Settings\SettingsSchema;

/**
 * A provider with one table setting shaped like CloudPCR's Non-Agency Crew:
 * text, a static select, a per-company select and an on/off column.
 */
class FakeTableSettingsProvider implements SettingsProvider
{
    /** @var array<int, list<array<string, mixed>>> rows per SSO company */
    public array $rows = [
        42 => [['id' => 1, 'last_name' => 'Smith', 'first_name' => 'Jane', 'level_code' => '9925007', 'certification_number' => '123456', 'kind' => 'ems', 'is_active' => true]],
        43 => [],
    ];

    /** @var array<int, list<array{value: string, label: string}>> level options per SSO company */
    public array $levels = [
        42 => [['value' => '9925007', 'label' => 'EMT'], ['value' => '9925005', 'label' => 'Paramedic']],
        43 => [],
    ];

    /** @var array<int, array{company: int, patch: array<string, mixed>}> */
    public array $applyCalls = [];

    public int $valuesCalls = 0;

    public function schema(): SettingsSchema
    {
        return SettingsSchema::make()
            ->group('crew', 'Crew')
            ->table('non_agency_crew', 'Non-Agency Crew')->help('People from other agencies who ride with your crews.')
            ->columns([
                ['key' => 'last_name', 'label' => 'Last name', 'type' => 'text', 'required' => true],
                ['key' => 'first_name', 'label' => 'First name', 'type' => 'text', 'required' => true],
                ['key' => 'level_code', 'label' => 'Level', 'type' => 'select', 'options_from_values' => true],
                ['key' => 'certification_number', 'label' => 'Certification number', 'type' => 'text', 'max' => 50],
                ['key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => [['value' => 'ems', 'label' => 'EMS'], ['value' => 'fire', 'label' => 'Fire']]],
                ['key' => 'is_active', 'label' => 'Active', 'type' => 'toggle'],
            ])
            ->toggle('crew.allow_non_agency', 'Allow non-agency crew')->default(true);
    }

    public function values(int $ssoCompanyId): ?array
    {
        $this->valuesCalls++;

        if (! array_key_exists($ssoCompanyId, $this->rows)) {
            return null;
        }

        return [
            'non_agency_crew' => [
                'rows' => $this->rows[$ssoCompanyId],
                'options' => ['level_code' => $this->levels[$ssoCompanyId]],
            ],
        ];
    }

    public function apply(int $ssoCompanyId, array $patch, SettingsActor $actor): SettingsResult
    {
        $this->applyCalls[] = ['company' => $ssoCompanyId, 'patch' => $patch];
        $result = SettingsResult::make();

        foreach (array_keys($patch) as $key) {
            $result->saved($key);
        }

        return $result;
    }
}
