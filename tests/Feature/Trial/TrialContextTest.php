<?php

namespace Unified\SsoClient\Tests\Feature\Trial;

use PHPUnit\Framework\TestCase;
use Unified\SsoClient\Trial\TrialContext;

class TrialContextTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        return require __DIR__.'/../../Fixtures/trial_seed_context.php';
    }

    public function test_reads_every_field_of_ssos_context_block(): void
    {
        $context = TrialContext::fromPayload(['ready' => true, 'counts' => ['vehicles' => 7], 'context' => self::fixture()]);

        $this->assertNotNull($context);
        $this->assertSame('NY', $context->state());
        $this->assertSame('36', $context->stateFips());
        $this->assertSame('Albany', $context->city());
        $this->assertSame('36001', $context->countyFips());
        $this->assertSame('12207', $context->zip());
        $this->assertSame('America/New_York', $context->timezone());
        $this->assertSame('Example EMS', $context->agencyName());
        $this->assertSame('5185550100', $context->phone());
        $this->assertSame(12, $context->ownerUserId());
        $this->assertSame([13, 14], $context->fakeUserIds());
        $this->assertSame(['North District', 'South District'], $context->divisions());
        $this->assertSame(['Medic 1', 'Medic 2', 'Medic 3', 'Engine 1'], $context->units());
        $this->assertCount(4, $context->stations());
        $this->assertSame(['unit_number' => 'Medic 3', 'call_sign' => 'M3', 'type' => 'BLS'], $context->vehicles()[2]);
        $this->assertSame(['name' => 'Albany Medical Center Hospital', 'type' => 'hospital'], $context->facilities()[0]);
        $this->assertSame(
            ['user_id' => 13, 'first_name' => 'Jordan', 'last_name' => 'Reyes', 'licence' => 'DEMO-1234', 'level' => '9925007'],
            $context->people()[0],
        );
    }

    public function test_helpers_name_the_vehicles_and_stations_and_give_addresses(): void
    {
        $context = TrialContext::fromArray(self::fixture());

        $this->assertSame(['Medic 1', 'Medic 2', 'Medic 3', 'Engine 1', 'Engine 2', 'Command 1', 'Rescue 1'], $context->vehicleNames());
        $this->assertSame(['Station 1 - Headquarters', 'Station 2', 'Station 3', 'Station 4'], $context->stationNames());
        $this->assertSame('Station 3', $context->station(3)['name'] ?? null);
        $this->assertNull($context->station(9));

        $this->assertSame(['street' => '100 Main St', 'city' => 'Albany', 'state' => 'NY', 'zip' => '12207', 'county_fips' => '36001'], $context->address());
        $this->assertSame(['street' => '300 Central Ave', 'city' => 'Schenectady', 'state' => 'NY', 'zip' => '12305', 'county_fips' => '36001'], $context->address(3));
        $this->assertSame(['street' => null, 'city' => 'Albany', 'state' => 'NY', 'zip' => '12207', 'county_fips' => '36001'], $context->address(9), 'a missing station falls back to the trial place');
    }

    public function test_payload_without_a_context_block_gives_null(): void
    {
        $this->assertNull(TrialContext::fromPayload([]));
        $this->assertNull(TrialContext::fromPayload(['ready' => true, 'counts' => ['divisions' => 2]]));
        $this->assertNull(TrialContext::fromPayload(['context' => null]));
        $this->assertNull(TrialContext::fromPayload(['context' => 'NY']));
    }

    public function test_missing_keys_read_as_nulls_and_empty_lists(): void
    {
        $context = TrialContext::fromPayload(['context' => []]);

        $this->assertNotNull($context);
        foreach (['state', 'stateFips', 'city', 'countyFips', 'zip', 'timezone', 'agencyName', 'phone', 'ownerUserId'] as $accessor) {
            $this->assertNull($context->{$accessor}(), $accessor);
        }
        foreach (['fakeUserIds', 'divisions', 'stations', 'vehicles', 'units', 'facilities', 'people', 'vehicleNames', 'stationNames'] as $accessor) {
            $this->assertSame([], $context->{$accessor}(), $accessor);
        }
        $this->assertSame(['street' => null, 'city' => null, 'state' => null, 'zip' => null, 'county_fips' => null], $context->address());
    }

    public function test_tolerates_extra_keys_odd_types_and_incomplete_entries(): void
    {
        $context = TrialContext::fromArray([
            'state' => '  NY ',
            'state_fips' => 6,
            'county_fips' => 6037,
            'zip' => 2134,
            'phone' => '',
            'owner_user_id' => '12',
            'fake_user_ids' => [13, 'x', 0, '14', null],
            'divisions' => ['North District', '', null, ['nested']],
            'stations' => [
                ['name' => 'Station A', 'street' => '1 Elm St'],
                ['number' => 7, 'name' => ''],
                'Station B',
                ['name' => 'Station C', 'zip' => 501, 'extra' => true],
            ],
            'vehicles' => [['unit_number' => 'Medic 1'], ['call_sign' => 'X1'], 'Medic 9'],
            'facilities' => [['type' => 'hospital'], ['name' => 'General']],
            'people' => [['user_id' => 0, 'first_name' => 'Nobody'], ['user_id' => '13', 'first_name' => 'Jordan']],
            'units' => 'Medic 1',
            'new_key_sso_added_later' => ['anything'],
        ]);

        $this->assertSame('NY', $context->state());
        $this->assertSame('06', $context->stateFips(), 'leading zeros come back on a numeric FIPS');
        $this->assertSame('06037', $context->countyFips());
        $this->assertSame('02134', $context->zip());
        $this->assertNull($context->phone());
        $this->assertSame(12, $context->ownerUserId());
        $this->assertSame([13, 14], $context->fakeUserIds());
        $this->assertSame(['North District'], $context->divisions());
        $this->assertSame([], $context->units());
        $this->assertSame([
            ['number' => 1, 'name' => 'Station A', 'street' => '1 Elm St', 'city' => null, 'state' => null, 'zip' => null],
            ['number' => 4, 'name' => 'Station C', 'street' => null, 'city' => null, 'state' => null, 'zip' => '00501'],
        ], $context->stations());
        $this->assertSame([['unit_number' => 'Medic 1', 'call_sign' => null, 'type' => null]], $context->vehicles());
        $this->assertSame([['name' => 'General', 'type' => null]], $context->facilities());
        $this->assertSame([['user_id' => 13, 'first_name' => 'Jordan', 'last_name' => null, 'licence' => null, 'level' => null]], $context->people());
    }

    public function test_to_array_round_trips(): void
    {
        $context = TrialContext::fromArray(self::fixture());

        $this->assertEquals($context, TrialContext::fromArray($context->toArray()));
        $this->assertSame($context->toArray(), TrialContext::fromArray(json_decode(json_encode($context->toArray()), true))->toArray());
    }

    public function test_serializes_as_is_for_a_queued_job(): void
    {
        $context = TrialContext::fromArray(self::fixture());

        $this->assertEquals($context, unserialize(serialize($context)));
    }
}
