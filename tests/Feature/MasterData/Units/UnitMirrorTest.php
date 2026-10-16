<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Units;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Units\UnitMirror;
use Unified\SsoClient\Settings\SettingEntity;
use Unified\SsoClient\Tests\Feature\MasterData\MasterDataTestCase;
use Unified\SsoClient\Trial\TrialMirrorCleaner;

/**
 * units on the standard `sso_units` + `sso_unit_seats` read model, with the
 * divisions, locations, vehicles and qualifications mirrors installed so
 * every translation can be watched: unit.* webhooks, seats in place,
 * adoption, the resync, the push and the trial purge.
 */
class UnitMirrorTest extends MasterDataTestCase
{
    private int $companyId;

    private int $vehicle;

    private int $pendingVehicle;

    private int $location;

    private int $division;

    private int $driver;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.app_slug', 'crew-scheduling');
        $app['config']->set('sso.master_data.units', true);
        $app['config']->set('sso.master_data.vehicles', true);
        $app['config']->set('sso.master_data.locations', true);
        $app['config']->set('sso.master_data.divisions', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        foreach ([
            '2026_10_09_000000_create_or_extend_divisions_mirror.php',
            '2026_10_09_100000_create_or_extend_locations_mirror.php',
            '2026_10_11_000000_create_or_extend_vehicles_mirror.php',
            '2026_10_16_100000_create_or_extend_units_mirror.php',
        ] as $file) {
            (require __DIR__.'/../../../../database/master-data/'.$file)->up();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
        $this->division = $this->row('divisions', ['name' => 'North', 'sso_division_id' => 31]);
        $this->location = $this->row('locations', ['name' => 'Station 1', 'sso_location_id' => 21]);
        $this->vehicle = $this->row('vehicles', ['unit_number' => '101', 'sso_vehicle_id' => 41]);
        $this->pendingVehicle = $this->row('vehicles', ['unit_number' => '102', 'sso_vehicle_id' => 42, 'sso_link_pending' => true]);
        $this->driver = $this->localQualification($this->companyId, 'Driver', 51);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function row(string $table, array $attributes): int
    {
        return (int) DB::table($table)->insertGetId($attributes + ['company_id' => $this->companyId, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function unit(int $id, string $name, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'uuid' => "uuid-{$id}",
            'name' => $name,
            'display_name' => null,
            'unit_type' => 'ambulance',
            'unit_type_name' => 'Ambulance',
            'level_of_care' => 'als',
            'level_of_care_name' => 'ALS',
            'capabilities' => ['stretcher', 'oxygen'],
            'vehicle_id' => 41,
            'vehicle' => ['id' => 41, 'unit_number' => '101', 'call_sign' => 'M1'],
            'location_id' => 21,
            'location' => ['id' => 21, 'name' => 'Station 1'],
            'division_id' => 31,
            'division' => ['id' => 31, 'name' => 'North'],
            'crew_size' => 2,
            'seats' => [
                ['id' => 901, 'uuid' => 's-901', 'position' => 1, 'label' => 'Driver', 'qualification_id' => 51, 'qualification_name' => 'Driver', 'sort_order' => 0],
                ['id' => 902, 'uuid' => 's-902', 'position' => 2, 'label' => 'Attendant', 'qualification_id' => null, 'qualification_name' => null, 'sort_order' => 1],
            ],
            'source_app' => null,
            'is_active' => true,
            'sort_order' => 0,
            'updated_at' => '2026-10-16T12:00:00+00:00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function deliverUnit(string $event, array $record): TestResponse
    {
        return $this->postWebhook($event, ['company' => ['id' => 70], 'unit' => $record, 'updated_at' => $record['updated_at']]);
    }

    private function mirroredUnit(int $ssoId): ?object
    {
        return DB::table('sso_units')->where('sso_unit_id', $ssoId)->first();
    }

    /**
     * @return list<object>
     */
    private function seats(int $unitId): array
    {
        return DB::table('sso_unit_seats')->where('unit_id', $unitId)->orderBy('id')->get()->all();
    }

    public function test_a_webhook_writes_the_unit_with_its_references_and_seats_translated(): void
    {
        $this->deliverUnit('unit.created', $this->unit(801, 'Medic 1'))
            ->assertOk()->assertJson(['status' => 'ok', 'result' => 'created']);

        $row = $this->mirroredUnit(801);
        $this->assertSame('Medic 1', $row->name);
        $this->assertSame('ambulance', $row->unit_type);
        $this->assertSame(['stretcher', 'oxygen'], json_decode($row->capabilities, true));
        $this->assertSame($this->vehicle, (int) $row->vehicle_id);
        $this->assertSame($this->location, (int) $row->location_id);
        $this->assertSame($this->division, (int) $row->division_id);
        $this->assertSame(2, (int) $row->crew_size);

        $seats = $this->seats((int) $row->id);
        $this->assertCount(2, $seats);
        $this->assertSame(['Driver', $this->driver, 901], [$seats[0]->label, (int) $seats[0]->qualification_id, (int) $seats[0]->sso_unit_seat_id]);
        $this->assertNull($seats[1]->qualification_id);
    }

    public function test_seats_are_edited_in_place_and_a_removed_seat_is_kept_unlinked(): void
    {
        $this->deliverUnit('unit.created', $this->unit(801, 'Medic 1'));
        $unitId = (int) $this->mirroredUnit(801)->id;
        [$first, $second] = $this->seats($unitId);

        $this->deliverUnit('unit.updated', $this->unit(801, 'Medic 1', [
            'updated_at' => '2026-10-16T13:00:00+00:00',
            'seats' => [['id' => 901, 'position' => 1, 'label' => 'Driver/Operator', 'qualification_id' => 51, 'sort_order' => 0]],
        ]))->assertJson(['result' => 'updated']);

        $seats = $this->seats($unitId);
        $this->assertCount(2, $seats, 'A seat SSO removed is never deleted by default.');
        $this->assertSame([$first->id, 'Driver/Operator'], [$seats[0]->id, $seats[0]->label]);
        $this->assertSame($second->id, $seats[1]->id);
        $this->assertNull($seats[1]->sso_unit_seat_id);
        $this->assertNotNull($seats[1]->sso_removed_at);
    }

    public function test_a_reference_not_linked_here_leaves_the_local_value_and_none_clears_it(): void
    {
        $this->deliverUnit('unit.created', $this->unit(801, 'Medic 1', ['vehicle_id' => 999]));
        $this->assertNull($this->mirroredUnit(801)->vehicle_id, 'A new row gets no vehicle until the vehicles mirror catches up.');

        $this->deliverUnit('unit.updated', $this->unit(801, 'Medic 1', ['vehicle_id' => 41, 'updated_at' => '2026-10-16T13:00:00+00:00']));
        $this->deliverUnit('unit.updated', $this->unit(801, 'Medic 1', ['vehicle_id' => 999, 'display_name' => 'Medic One', 'updated_at' => '2026-10-16T14:00:00+00:00']));
        $this->assertSame($this->vehicle, (int) $this->mirroredUnit(801)->vehicle_id);
        $this->assertSame('Medic One', $this->mirroredUnit(801)->display_name);

        $this->deliverUnit('unit.updated', $this->unit(801, 'Medic 1', ['vehicle_id' => null, 'location_id' => null, 'updated_at' => '2026-10-16T15:00:00+00:00']));
        $this->assertNull($this->mirroredUnit(801)->vehicle_id);
        $this->assertNull($this->mirroredUnit(801)->location_id);
    }

    public function test_a_delivery_adopts_by_name_ignoring_case_and_turning_off_never_deletes(): void
    {
        $local = $this->row('sso_units', ['name' => 'medic 1', 'display_name' => 'Kept']);

        $this->deliverUnit('unit.created', $this->unit(801, 'Medic 1'))->assertJson(['result' => 'linked']);
        $this->assertSame($local, (int) $this->mirroredUnit(801)->id);
        $this->assertSame('Kept', $this->mirroredUnit(801)->display_name);
        $this->assertTrue((bool) $this->mirroredUnit(801)->sso_link_pending);
        $this->assertSame([], $this->seats($local), 'A pending adoption leaves the seats alone.');

        $this->deliverUnit('unit.created', $this->unit(802, 'Medic 2'));
        $this->deliverUnit('unit.deactivated', $this->unit(802, 'Medic 2', ['is_active' => false, 'updated_at' => '2026-10-16T13:00:00+00:00']))
            ->assertJson(['result' => 'updated']);
        $this->assertFalse((bool) $this->mirroredUnit(802)->is_active);
        $this->assertSame(2, DB::table('sso_units')->count());
    }

    public function test_a_stale_delivery_is_ignored_the_batch_upserts_and_a_disabled_entity_is_ignored(): void
    {
        $this->deliverUnit('unit.created', $this->unit(801, 'Medic 1', ['updated_at' => '2026-10-16T13:00:00+00:00']));
        $this->deliverUnit('unit.updated', $this->unit(801, 'Old name', ['updated_at' => '2026-10-16T12:00:00+00:00']))
            ->assertJson(['status' => 'stale']);
        $this->assertSame('Medic 1', $this->mirroredUnit(801)->name);

        $this->postWebhook('unit.batch_updated', [
            'company' => ['id' => 70],
            'ids' => [802, 803],
            'units' => [$this->unit(802, 'Medic 2'), $this->unit(803, 'Engine 3', ['unit_type' => 'engine', 'seats' => []])],
        ])->assertJson(['status' => 'ok', 'counts' => ['created' => 2]]);
        $this->assertSame('engine', $this->mirroredUnit(803)->unit_type);

        config(['sso.master_data.units' => false]);

        $this->deliverUnit('unit.created', $this->unit(804, 'Medic 4'))
            ->assertJson(['status' => 'ignored', 'reason' => 'master_data.units disabled']);
        $this->assertNull($this->mirroredUnit(804));
    }

    public function test_resync_turns_off_dropped_units_counts_kept_seats_and_is_idempotent(): void
    {
        $dropped = $this->row('sso_units', ['name' => 'Old unit', 'sso_unit_id' => 899]);
        $handTyped = $this->row('sso_units', ['name' => 'Rescue 9']);
        $ownSeat = (int) DB::table('sso_unit_seats')->insertGetId(['unit_id' => $handTyped, 'position' => 1, 'label' => 'Officer']);

        Http::fake(['sso.test/api/internal/companies/70/units' => Http::response([
            'company' => ['id' => 70, 'timezone' => 'America/New_York'],
            'units' => [$this->unit(801, 'Medic 1'), $this->unit(805, 'Rescue 9', ['seats' => [['id' => 950, 'position' => 2, 'label' => 'Driver', 'qualification_id' => null]]])],
        ])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'units'])
            ->expectsOutputToContain('created=1 updated=0 linked=1 unchanged=0 deactivated=1 unlinked_seats=1')
            ->assertSuccessful();
        $this->assertFalse((bool) DB::table('sso_units')->where('id', $dropped)->value('is_active'));
        $this->assertSame(805, (int) DB::table('sso_units')->where('id', $handTyped)->value('sso_unit_id'));
        $this->assertNull(DB::table('sso_unit_seats')->where('id', $ownSeat)->value('sso_unit_seat_id'), 'A seat at another position is not adopted.');

        $this->artisan('sso:resync-master-data', ['entity' => 'units'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=2 deactivated=0 unlinked_seats=1')
            ->assertSuccessful();
    }

    public function test_the_push_sends_references_and_seats_and_links_both_from_the_mapping(): void
    {
        config(['sso.master_data.units' => false]);

        $medic = $this->row('sso_units', [
            'name' => 'Medic 1', 'unit_type' => 'ambulance', 'level_of_care' => 'als', 'capabilities' => json_encode(['oxygen']), 'crew_size' => 2,
            'vehicle_id' => $this->vehicle, 'location_id' => $this->location, 'division_id' => $this->division,
        ]);
        $engine = $this->row('sso_units', ['name' => 'Engine 3', 'vehicle_id' => $this->pendingVehicle, 'is_active' => false]);
        $driverSeat = (int) DB::table('sso_unit_seats')->insertGetId(['unit_id' => $medic, 'position' => 1, 'label' => 'Driver', 'qualification_id' => $this->driver]);
        $attendant = (int) DB::table('sso_unit_seats')->insertGetId(['unit_id' => $medic, 'position' => 2, 'label' => 'Attendant']);

        Http::fake(['sso.test/api/internal/companies/70/units/import' => Http::response([
            'created' => 1, 'matched' => 1, 'filled' => [], 'partial' => [['local_id' => (string) $engine, 'name' => 'Engine 3', 'fields' => ['level_of_care' => 'Not a level.']]],
            'refused' => [], 'invalid' => [], 'conflicts' => [],
            'unresolved_vehicles' => [['local_id' => (string) $engine, 'name' => 'Engine 3', 'vehicle_local_id' => $this->pendingVehicle, 'reason' => 'unknown_vehicle']],
            'unresolved_locations' => [], 'unresolved_divisions' => [],
            'mapping' => [
                (string) $medic => ['sso_id' => 801, 'updated_at' => '2026-10-16T12:00:00+00:00', 'seats' => [(string) $driverSeat => 901, (string) $attendant => 902]],
                (string) $engine => ['sso_id' => 802, 'updated_at' => '2026-10-16T12:00:00+00:00', 'seats' => (object) []],
            ],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'units', '--company' => '70', '--dry-run' => true])
            ->expectsOutputToContain('would send 2 units')
            ->assertSuccessful();
        Http::assertNothingSent();

        $this->artisan('sso:push-master-data', ['entity' => 'units', '--company' => '70'])
            ->expectsOutputToContain('linked=2 link_collisions=0 invalid=0 filled=0 partial=1 unresolved_vehicles=1')
            ->expectsOutputToContain('SSO could not set these usual vehicles')
            ->expectsOutputToContain('SSO saved these rows without some of their values')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['app_slug'] === 'crew-scheduling' && $request['units'] === [
            [
                'local_id' => $medic, 'is_active' => true,
                'name' => 'Medic 1', 'unit_type' => 'ambulance', 'level_of_care' => 'als', 'capabilities' => ['oxygen'], 'crew_size' => 2,
                'vehicle_sso_id' => 41, 'location_sso_id' => 21, 'division_sso_id' => 31,
                'seats' => [
                    ['local_id' => $driverSeat, 'position' => 1, 'label' => 'Driver', 'qualification_sso_id' => 51],
                    ['local_id' => $attendant, 'position' => 2, 'label' => 'Attendant'],
                ],
            ],
            ['local_id' => $engine, 'is_active' => false, 'name' => 'Engine 3', 'vehicle_local_id' => $this->pendingVehicle],
        ]);

        $this->assertSame(801, (int) DB::table('sso_units')->where('id', $medic)->value('sso_unit_id'));
        $this->assertSame([901, 902], DB::table('sso_unit_seats')->where('unit_id', $medic)->orderBy('id')->pluck('sso_unit_seat_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_the_trial_purge_removes_listed_sample_units_with_their_seats_before_their_vehicle(): void
    {
        $this->deliverUnit('unit.created', $this->unit(801, 'Medic 1'));
        $this->deliverUnit('unit.created', $this->unit(802, 'Kept unit', ['seats' => []]));

        $report = app(TrialMirrorCleaner::class)->clean($this->companyId, ['units' => [801], 'vehicles' => [41]]);

        $this->assertSame(['deleted' => 1, 'deactivated' => 0, 'kept' => 0, 'missing' => 0], $report['units']);
        $this->assertNull($this->mirroredUnit(801));
        $this->assertSame(0, DB::table('sso_unit_seats')->count());
        $this->assertSame(['deleted' => 0, 'deactivated' => 1, 'kept' => 0, 'missing' => 0], $report['vehicles'], 'A unit the prospect kept still runs that vehicle.');
    }

    public function test_the_registry_settings_link_and_publish_tag(): void
    {
        $registry = app(MasterDataRegistry::class);

        $this->assertInstanceOf(UnitMirror::class, $registry->mirror('units'));
        $this->assertSame('units', $registry->entityForEvent('unit.batch_updated'));
        $this->assertSame('sso-master-data-units', $registry->mirror('units')->publishTag());
        $this->assertTrue($registry->mirror('units')->isInstalled());
        $this->assertSame('https://sso.test/system/units', SettingEntity::Units->settingsUrl());
    }
}
