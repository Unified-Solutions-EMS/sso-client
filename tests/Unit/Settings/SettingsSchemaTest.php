<?php

namespace Unified\SsoClient\Tests\Unit\Settings;

use InvalidArgumentException;
use LogicException;
use Unified\SsoClient\Settings\SettingsSchema;
use Unified\SsoClient\Tests\Fixtures\FakeSettingsProvider;
use Unified\SsoClient\Tests\TestCase;

class SettingsSchemaTest extends TestCase
{
    public function test_builder_emits_groups_in_order_with_their_settings(): void
    {
        $schema = (new FakeSettingsProvider)->schema()->toArray();

        $this->assertSame(['dispatch', 'numbering', 'integrations', 'pay'], array_column($schema['groups'], 'key'));
        $this->assertSame([1, 2, 3, 4], array_column($schema['groups'], 'order'));
        $this->assertSame('Dispatch alerts', $schema['groups'][0]['label']);
        $this->assertSame(
            ['alerts.pre_pickup', 'alerts.pre_pickup_minutes', 'alerts.quiet_start'],
            array_column($schema['groups'][0]['settings'], 'key'),
        );
    }

    public function test_setting_carries_every_contract_field(): void
    {
        $setting = (new FakeSettingsProvider)->schema()->toArray()['groups'][0]['settings'][1];

        $this->assertSame([
            'key' => 'alerts.pre_pickup_minutes',
            'type' => 'number',
            'entity' => null,
            'label' => 'Minutes before pickup',
            'help' => null,
            'options' => null,
            'validation' => ['nullable', 'numeric', 'integer', 'min:1', 'max:120'],
            'default' => 15,
            'requires' => ['alerts.pre_pickup'],
            'danger' => false,
            'secret' => false,
            'group' => 'dispatch',
        ], $setting);
    }

    public function test_select_options_entity_and_danger_serialize(): void
    {
        $settings = collect((new FakeSettingsProvider)->schema()->toArray()['groups'][1]['settings'])->keyBy('key');

        $reset = $settings['numbering.reset'];
        $this->assertSame('select', $reset['type']);
        $this->assertSame([['value' => 'yearly', 'label' => 'Every year'], ['value' => 'never', 'label' => 'Never']], $reset['options']);
        $this->assertTrue($reset['danger']);
        $this->assertContains('in:"yearly","never"', $reset['validation']);

        $this->assertSame('multi_select', $settings['numbering.service_types']['type']);

        $station = $settings['dispatch.default_station'];
        $this->assertSame('entity', $station['type']);
        $this->assertSame('station', $station['entity']);
        $this->assertSame(['station'], $station['requires']);
    }

    public function test_secret_flag_serializes(): void
    {
        $token = (new FakeSettingsProvider)->schema()->toArray()['groups'][2]['settings'][0];

        $this->assertSame('integrations.bryx_token', $token['key']);
        $this->assertTrue($token['secret']);
    }

    public function test_secret_default_is_never_emitted(): void
    {
        $schema = (new FakeSettingsProvider)->schema();

        $this->assertNull($schema->toArray()['groups'][2]['settings'][0]['default']);
        $this->assertNull($schema->defaults()['integrations.bryx_token']);
        $this->assertStringNotContainsString('platform-fallback-key', json_encode($schema->toArray()));
    }

    public function test_atomic_group_serializes(): void
    {
        $groups = collect((new FakeSettingsProvider)->schema()->toArray()['groups'])->keyBy('key');

        $this->assertTrue($groups['pay']['atomic']);
        $this->assertFalse($groups['dispatch']['atomic']);
    }

    public function test_atomic_before_group_is_rejected(): void
    {
        $this->expectException(LogicException::class);

        SettingsSchema::make()->atomic();
    }

    public function test_explicit_group_order_wins_over_declaration_order(): void
    {
        $schema = SettingsSchema::make()
            ->group('late', 'Late', 10)->toggle('a', 'A')
            ->group('early', 'Early', 1)->toggle('b', 'B')
            ->toArray();

        $this->assertSame(['early', 'late'], array_column($schema['groups'], 'key'));
    }

    public function test_required_rule_drops_nullable(): void
    {
        $setting = SettingsSchema::make()->group('g', 'G')->text('name', 'Name')->rules(['required', 'max:10'])->find('name');

        $this->assertSame(['string', 'required', 'max:10'], $setting->valueRules());
    }

    public function test_duplicate_keys_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SettingsSchema::make()->group('a', 'A')->toggle('x', 'X')->group('b', 'B')->toggle('x', 'X again');
    }

    public function test_select_requires_options(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SettingsSchema::make()->group('a', 'A')->select('x', 'X', []);
    }

    public function test_setting_before_group_is_rejected(): void
    {
        $this->expectException(LogicException::class);

        SettingsSchema::make()->toggle('x', 'X');
    }

    public function test_modifier_before_setting_is_rejected(): void
    {
        $this->expectException(LogicException::class);

        SettingsSchema::make()->group('a', 'A')->danger();
    }
}
