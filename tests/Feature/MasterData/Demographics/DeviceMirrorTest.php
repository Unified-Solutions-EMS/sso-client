<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Demographics;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\MasterData\Devices\DeviceWriteThrough;
use Unified\SsoClient\Trial\TrialMirrorCleaner;

/**
 * devices on the standard `sso_devices` read model, with the standard
 * vehicles mirror installed so the vehicle translation can be watched:
 * device.* webhooks, adoption by serial, the resync, the push, the
 * single-device write-through and the trial purge.
 */
class DeviceMirrorTest extends DemographicsTestCase
{
    protected string $entity = 'devices';

    private int $companyId;

    private int $linkedVehicle;

    private int $pendingVehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
        $this->linkedVehicle = $this->vehicle($this->companyId, 'M1', 41);
        $this->pendingVehicle = $this->vehicle($this->companyId, 'M2', 42, pending: true);
    }

    protected function createAppTables(): void
    {
        (require __DIR__.'/../../../../database/master-data/2026_10_11_000000_create_or_extend_vehicles_mirror.php')->up();
    }

    private function vehicle(int $companyId, string $unit, ?int $ssoId, bool $pending = false): int
    {
        return (int) DB::table('vehicles')->insertGetId([
            'company_id' => $companyId,
            'unit_number' => $unit,
            'sso_vehicle_id' => $ssoId,
            'sso_link_pending' => $pending,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function device(int $id, string $serial, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'serial' => $serial,
            'name' => 'LIFEPAK 15',
            'device_types' => ['1009001'],
            'manufacturer' => 'Stryker',
            'model' => 'LP15',
            'purchase_date' => '2023-05-01',
            'source' => 'integration',
            'last_seen_at' => '2026-10-14T08:00:00-04:00',
            'vehicle_id' => null,
            'vehicle_name' => null,
            'is_active' => true,
            'sort_order' => 0,
            'updated_at' => '2026-10-14T12:00:00+00:00',
        ], $overrides);
    }

    private function mirrored(int $ssoId): ?object
    {
        return DB::table('sso_devices')->where('sso_device_id', $ssoId)->first();
    }

    private function local(int $companyId, string $serial, array $attributes = []): int
    {
        return (int) DB::table('sso_devices')->insertGetId([
            'company_id' => $companyId,
            'serial' => $serial,
            'created_at' => now(),
            'updated_at' => now(),
        ] + $attributes);
    }

    public function test_a_webhook_writes_the_device_with_its_vehicle_translated_and_last_seen_in_utc(): void
    {
        $this->deliver('device.created', 70, $this->device(801, 'LP15-0001', ['vehicle_id' => 41]))
            ->assertOk()->assertJson(['status' => 'ok', 'result' => 'created']);

        $row = $this->mirrored(801);
        $this->assertSame($this->linkedVehicle, (int) $row->vehicle_id);
        $this->assertSame('2026-10-14 12:00:00', $row->last_seen_at);
        $this->assertSame(['1009001'], json_decode($row->device_types, true));
        $this->assertSame('integration', $row->source);
    }

    public function test_a_vehicle_not_linked_here_leaves_the_local_value_and_none_clears_it(): void
    {
        $this->deliver('device.created', 70, $this->device(801, 'LP15-0001', ['vehicle_id' => 999]))->assertJson(['result' => 'created']);
        $this->assertNull($this->mirrored(801)->vehicle_id, 'A new row gets no vehicle until the vehicles mirror catches up.');

        $this->deliver('device.updated', 70, $this->device(801, 'LP15-0001', ['vehicle_id' => 41, 'updated_at' => '2026-10-14T13:00:00+00:00']));
        $this->deliver('device.updated', 70, $this->device(801, 'LP15-0001', ['vehicle_id' => 999, 'name' => 'Renamed', 'updated_at' => '2026-10-14T14:00:00+00:00']));
        $this->assertSame($this->linkedVehicle, (int) $this->mirrored(801)->vehicle_id);
        $this->assertSame('Renamed', $this->mirrored(801)->name);

        $this->deliver('device.updated', 70, $this->device(801, 'LP15-0001', ['vehicle_id' => null, 'updated_at' => '2026-10-14T15:00:00+00:00']));
        $this->assertNull($this->mirrored(801)->vehicle_id);
    }

    public function test_a_delivery_adopts_by_serial_ignoring_case_and_turning_off_never_deletes(): void
    {
        $local = $this->local($this->companyId, 'lp15-0001', ['name' => 'Local name']);

        $this->deliver('device.created', 70, $this->device(801, 'LP15-0001'))->assertJson(['result' => 'linked']);
        $this->assertSame($local, (int) $this->mirrored(801)->id);
        $this->assertSame('Local name', $this->mirrored(801)->name);

        $this->deliver('device.created', 70, $this->device(802, 'LP15-0002'))->assertJson(['result' => 'created']);
        $this->deliver('device.deactivated', 70, $this->device(802, 'LP15-0002', ['is_active' => false, 'updated_at' => '2026-10-14T13:00:00+00:00']))
            ->assertJson(['result' => 'updated']);
        $this->assertFalse((bool) $this->mirrored(802)->is_active);
        $this->assertSame(2, DB::table('sso_devices')->count());
    }

    public function test_the_batch_upserts_and_a_disabled_entity_is_ignored(): void
    {
        $this->postWebhook('device.batch_updated', [
            'company' => ['id' => 70],
            'ids' => [801, 802],
            'devices' => [$this->device(801, 'LP15-0001'), $this->device(802, 'X-2', ['name' => null])],
        ])->assertJson(['status' => 'ok', 'counts' => ['created' => 2]]);

        $this->assertNull($this->mirrored(802)->name, 'A device named only by its serial keeps no name.');

        config(['sso.master_data.devices' => false]);

        $this->deliver('device.created', 70, $this->device(803, 'LP15-0003'))
            ->assertJson(['status' => 'ignored', 'reason' => 'master_data.devices disabled']);
        $this->assertNull($this->mirrored(803));
    }

    public function test_resync_turns_off_dropped_linked_devices_and_is_idempotent(): void
    {
        $dropped = $this->local($this->companyId, 'OLD-1', ['sso_device_id' => 899]);
        $handTyped = $this->local($this->companyId, 'VENT-1');

        Http::fake(['sso.test/api/internal/companies/70/devices' => Http::response([
            'company' => ['id' => 70],
            'devices' => [$this->device(801, 'LP15-0001', ['vehicle_id' => 41])],
        ])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'devices'])
            ->expectsOutputToContain('created=1 updated=0 linked=0 unchanged=0 deactivated=1')
            ->assertSuccessful();
        $this->assertFalse((bool) DB::table('sso_devices')->where('id', $dropped)->value('is_active'));
        $this->assertTrue((bool) DB::table('sso_devices')->where('id', $handTyped)->value('is_active'));

        $this->artisan('sso:resync-master-data', ['entity' => 'devices'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=1 deactivated=0')
            ->assertSuccessful();
    }

    public function test_the_push_names_vehicles_by_sso_id_when_confirmed_and_reports_duplicate_serials(): void
    {
        config(['sso.master_data.devices' => false]);

        $onLinked = $this->local($this->companyId, 'LP15-0001', ['vehicle_id' => $this->linkedVehicle, 'last_seen_at' => '2026-10-14 12:00:00', 'device_types' => json_encode(['1009001'])]);
        $onPending = $this->local($this->companyId, 'lp15-0001', ['vehicle_id' => $this->pendingVehicle]);
        $loose = $this->local($this->companyId, 'VENT-1');

        Http::fake(['sso.test/api/internal/companies/70/devices/import' => Http::response([
            'created' => 2, 'matched' => 1, 'conflicts' => [], 'invalid' => [],
            'mapping' => [
                (string) $onLinked => ['sso_id' => 801, 'updated_at' => '2026-10-14T12:00:00+00:00'],
                (string) $onPending => ['sso_id' => 801, 'updated_at' => '2026-10-14T12:00:00+00:00'],
                (string) $loose => ['sso_id' => 802, 'updated_at' => '2026-10-14T12:00:00+00:00'],
            ],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'devices', '--company' => '70', '--dry-run' => true])
            ->expectsOutputToContain('would send 3 devices')
            ->assertSuccessful();
        Http::assertNothingSent();

        $this->artisan('sso:push-master-data', ['entity' => 'devices', '--company' => '70'])
            ->expectsOutputToContain('linked=2 link_collisions=1')
            ->expectsTable(['Local id', 'Name', 'SSO id', 'Linked to local id', 'Not linked because'], [[(string) $onPending, 'lp15-0001', '801', (string) $onLinked, 'sso_id_held_by_another_local_row']])
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['devices'] === [
            ['local_id' => $onLinked, 'is_active' => true, 'serial' => 'LP15-0001', 'device_types' => ['1009001'], 'last_seen_at' => '2026-10-14T12:00:00+00:00', 'vehicle_sso_id' => 41],
            ['local_id' => $onPending, 'is_active' => true, 'serial' => 'lp15-0001', 'vehicle_local_id' => $this->pendingVehicle],
            ['local_id' => $loose, 'is_active' => true, 'serial' => 'VENT-1'],
        ]);
        $this->assertSame(802, (int) DB::table('sso_devices')->where('id', $loose)->value('sso_device_id'));
    }

    public function test_write_through_sends_one_device_with_its_vehicle_and_never_throws(): void
    {
        Http::fake(['sso.test/api/internal/companies/70/devices/import' => Http::response(['created' => 1, 'mapping' => []])]);

        DeviceWriteThrough::send($this->companyId, [
            'local_id' => 12,
            'serial' => 'LP15-0009',
            'manufacturer' => 'Stryker',
            'device_types' => ['1009001'],
            'vehicle_local_id' => $this->linkedVehicle,
        ]);
        DeviceWriteThrough::send($this->companyId, ['local_id' => 13, 'serial' => 'X-1', 'vehicle_local_id' => $this->pendingVehicle, 'source' => 'manual']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request['app_slug'] === 'cloudpcr' && $request['devices'] === [
            ['local_id' => 12, 'serial' => 'LP15-0009', 'manufacturer' => 'Stryker', 'device_types' => ['1009001'], 'source' => 'integration', 'vehicle_sso_id' => 41],
        ]);
        Http::assertSent(fn (Request $request): bool => $request['devices'] === [
            ['local_id' => 13, 'serial' => 'X-1', 'source' => 'manual', 'vehicle_local_id' => $this->pendingVehicle],
        ]);
    }

    public function test_write_through_is_silent_when_sso_fails_and_does_nothing_while_the_mirror_is_off(): void
    {
        Http::fake(['sso.test/*' => Http::response(['message' => 'Server error'], 500)]);
        Log::spy();

        DeviceWriteThrough::send($this->companyId, ['local_id' => 12, 'serial' => 'LP15-0009']);

        Http::assertSentCount(1);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'device write-through failed'))->once();

        config(['sso.master_data.devices' => false]);
        DeviceWriteThrough::send($this->companyId, ['local_id' => 12, 'serial' => 'LP15-0009']);
        Http::assertSentCount(1);
    }

    public function test_the_trial_purge_removes_listed_devices_before_vehicles_and_keeps_a_vehicle_a_prospect_device_is_on(): void
    {
        config(['sso.master_data.vehicles' => true]);
        $sample = $this->local($this->companyId, 'SAMPLE-1', ['sso_device_id' => 801, 'vehicle_id' => $this->linkedVehicle]);
        $prospect = $this->local($this->companyId, 'REAL-1', ['sso_device_id' => 802, 'vehicle_id' => $this->pendingVehicle]);

        $report = app(TrialMirrorCleaner::class)->clean($this->companyId, ['devices' => [801], 'vehicles' => [41, 42]]);

        $this->assertSame(['deleted' => 1, 'deactivated' => 0, 'kept' => 0, 'missing' => 0], $report['devices']);
        $this->assertSame(['deleted' => 1, 'deactivated' => 1, 'kept' => 0, 'missing' => 0], $report['vehicles']);
        $this->assertNull(DB::table('sso_devices')->where('id', $sample)->first());
        $this->assertNotNull(DB::table('sso_devices')->where('id', $prospect)->first());
        $this->assertNull(DB::table('vehicles')->where('id', $this->linkedVehicle)->first());
        $this->assertFalse((bool) DB::table('vehicles')->where('id', $this->pendingVehicle)->value('is_active'));
    }
}
