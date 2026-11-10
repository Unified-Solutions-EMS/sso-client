<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Demographics;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\OtherAgencies\OtherAgencyMirror;
use Unified\SsoClient\Settings\SettingEntity;

/**
 * other_agencies on the standard `sso_other_agencies` read model:
 * other_agency.* webhooks, adoption by name + state, the resync, the push
 * and the one-row pushRow().
 */
class OtherAgencyMirrorTest extends DemographicsTestCase
{
    protected string $entity = 'other_agencies';

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
    }

    /**
     * SSO's OtherAgency::toContractArray().
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function agency(int $id, string $name, ?string $state = 'PA', array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'uuid' => sprintf('9b1d2c3e-0000-4000-8000-%012d', $id),
            'name' => $name,
            'state' => $state,
            'state_agency_id' => '012345',
            'agency_number' => '0123',
            'service_type' => '2209011',
            'is_active' => true,
            'sort_order' => 1,
            'updated_at' => '2026-11-10T12:00:00+00:00',
            'source' => 'state_list',
        ], $overrides);
    }

    private function mirrored(int $ssoId): ?object
    {
        return DB::table('sso_other_agencies')->where('sso_other_agency_id', $ssoId)->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function localAgency(int $companyId, string $name, ?string $state = 'PA', array $attributes = []): int
    {
        return (int) DB::table('sso_other_agencies')->insertGetId([
            'company_id' => $companyId,
            'name' => $name,
            'state' => $state,
            'created_at' => now(),
            'updated_at' => now(),
        ] + $attributes);
    }

    public function test_the_mirror_is_wired(): void
    {
        $registry = app(MasterDataRegistry::class);

        $this->assertInstanceOf(OtherAgencyMirror::class, $registry->mirror('other_agencies'));
        foreach (OtherAgencyMirror::events() as $event) {
            $this->assertSame('other_agencies', $registry->entityForEvent($event));
        }
        $this->assertSame('sso-master-data-other-agencies', $registry->mirror('other_agencies')->publishTag());
        $this->assertTrue($registry->mirror('other_agencies')->isInstalled());
        $this->assertTrue(Schema::hasColumns('sso_other_agencies', [
            'company_id', 'sso_other_agency_id', 'uuid', 'name', 'state', 'state_agency_id', 'agency_number',
            'service_type', 'is_active', 'sort_order', 'sso_updated_at', 'sso_link_pending',
        ]));
        $this->assertSame('/system/other-agencies', SettingEntity::OtherAgencies->settingsPath());
    }

    public function test_each_event_upserts_and_deactivated_turns_off_without_deleting(): void
    {
        $this->deliver('other_agency.created', 70, $this->agency(501, 'Lake Shore EMS'))
            ->assertOk()->assertJson(['status' => 'ok', 'result' => 'created']);

        $row = $this->mirrored(501);
        $this->assertSame($this->companyId, (int) $row->company_id);
        $this->assertSame('Lake Shore EMS', $row->name);
        $this->assertSame('PA', $row->state);
        $this->assertSame('012345', $row->state_agency_id);
        $this->assertSame('0123', $row->agency_number);
        $this->assertSame('2209011', $row->service_type);
        $this->assertSame('9b1d2c3e-0000-4000-8000-000000000501', $row->uuid);
        $this->assertSame('2026-11-10 12:00:00', $row->sso_updated_at);

        $this->deliver('other_agency.updated', 70, $this->agency(501, 'Lake Shore Ambulance', 'PA', ['updated_at' => '2026-11-10T13:00:00+00:00']))
            ->assertJson(['result' => 'updated']);
        $this->deliver('other_agency.updated', 70, $this->agency(501, 'Old name', 'PA', ['updated_at' => '2026-11-10T12:30:00+00:00']))
            ->assertJson(['status' => 'stale']);
        $this->assertSame('Lake Shore Ambulance', $this->mirrored(501)->name);

        $this->deliver('other_agency.deactivated', 70, $this->agency(501, 'Lake Shore Ambulance', 'PA', ['is_active' => false, 'updated_at' => '2026-11-10T14:00:00+00:00']))
            ->assertJson(['result' => 'updated']);
        $this->assertFalse((bool) $this->mirrored(501)->is_active);
        $this->assertSame(1, DB::table('sso_other_agencies')->count());
    }

    public function test_the_batch_upserts_every_row_and_another_companys_rows_are_never_touched(): void
    {
        $theirs = $this->localAgency($this->company(80), 'Lake Shore EMS');

        $this->postWebhook('other_agency.batch_updated', [
            'company' => ['id' => 70, 'division_label' => 'Division'],
            'other_agencies' => [$this->agency(501, 'Lake Shore EMS'), $this->agency(502, 'Erie Fire', 'PA', ['state_agency_id' => null, 'source' => 'manual'])],
            'updated_at' => '2026-11-10T12:00:00+00:00',
        ])->assertOk()->assertJson(['status' => 'ok', 'counts' => ['created' => 2]]);

        $this->assertSame(2, DB::table('sso_other_agencies')->where('company_id', $this->companyId)->count());
        $this->assertNull($this->mirrored(502)->state_agency_id);
        $this->assertNull(DB::table('sso_other_agencies')->where('id', $theirs)->value('sso_other_agency_id'));
    }

    public function test_a_delivery_adopts_by_name_and_state_ignoring_case(): void
    {
        $local = $this->localAgency($this->companyId, '  lake shore ems ', 'pa');
        $otherState = $this->localAgency($this->companyId, 'Lake Shore EMS', 'NY');

        $this->deliver('other_agency.created', 70, $this->agency(501, 'Lake Shore EMS', 'PA'))->assertJson(['result' => 'linked']);

        $row = $this->mirrored(501);
        $this->assertSame($local, (int) $row->id);
        $this->assertTrue((bool) $row->sso_link_pending);
        $this->assertNull(DB::table('sso_other_agencies')->where('id', $otherState)->value('sso_other_agency_id'));
        $this->assertSame(2, DB::table('sso_other_agencies')->count());
    }

    public function test_a_disabled_entity_ignores_other_agency_events(): void
    {
        config(['sso.master_data.other_agencies' => false]);

        $this->deliver('other_agency.created', 70, $this->agency(501, 'Lake Shore EMS'))
            ->assertOk()->assertJson(['status' => 'ignored', 'reason' => 'master_data.other_agencies disabled']);
        $this->assertSame(0, DB::table('sso_other_agencies')->count());
    }

    public function test_resync_writes_the_list_adopts_turns_off_dropped_rows_and_is_idempotent(): void
    {
        $adoptable = $this->localAgency($this->companyId, 'Erie Fire', 'PA');
        $dropped = $this->localAgency($this->companyId, 'Gone EMS', 'PA', ['sso_other_agency_id' => 599]);
        $unlinked = $this->localAgency($this->companyId, 'Typed on a chart', null);

        Http::fake(['sso.test/api/internal/companies/70/other_agencies' => Http::response([
            'company' => ['id' => 70],
            'other_agencies' => [$this->agency(501, 'Lake Shore EMS'), $this->agency(502, 'ERIE FIRE', 'PA')],
        ])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'other_agencies'])
            ->expectsOutputToContain('created=1 updated=0 linked=1 unchanged=0 deactivated=1')
            ->assertSuccessful();

        $this->assertSame(502, (int) DB::table('sso_other_agencies')->where('id', $adoptable)->value('sso_other_agency_id'));
        $this->assertSame('ERIE FIRE', DB::table('sso_other_agencies')->where('id', $adoptable)->value('name'));
        $this->assertFalse((bool) DB::table('sso_other_agencies')->where('id', $dropped)->value('is_active'));
        $this->assertTrue((bool) DB::table('sso_other_agencies')->where('id', $unlinked)->value('is_active'));
        $this->assertSame(4, DB::table('sso_other_agencies')->count());

        $this->artisan('sso:resync-master-data', ['entity' => 'other_agencies'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=2 deactivated=0')
            ->assertSuccessful();
    }

    public function test_the_push_sends_the_list_and_links_the_mapping(): void
    {
        config(['sso.master_data.other_agencies' => false]);

        $local = $this->localAgency($this->companyId, 'Lake Shore EMS', 'PA', ['state_agency_id' => '012345', 'service_type' => '2209011']);

        Http::fake(['sso.test/api/internal/companies/70/other_agencies/import' => Http::response([
            'created' => 1, 'matched' => 0, 'filled' => [], 'conflicts' => [], 'invalid' => [],
            'mapping' => [(string) $local => ['sso_id' => 501, 'updated_at' => '2026-11-10T12:00:00+00:00']],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'other_agencies', '--company' => '70'])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['other_agencies'] === [
            ['local_id' => $local, 'is_active' => true, 'name' => 'Lake Shore EMS', 'state' => 'PA', 'state_agency_id' => '012345', 'service_type' => '2209011'],
        ]);
        $this->assertSame(501, (int) DB::table('sso_other_agencies')->where('id', $local)->value('sso_other_agency_id'));
    }

    public function test_push_row_sends_one_row_and_links_it_from_the_mapping(): void
    {
        $this->localAgency($this->companyId, 'Not this one', 'PA');
        $local = $this->localAgency($this->companyId, 'Lake Shore EMS', 'PA', ['state_agency_id' => '012345', 'agency_number' => '0123']);

        Http::fake(['sso.test/api/internal/companies/70/other_agencies/import' => Http::response([
            'created' => 1, 'matched' => 0,
            'mapping' => [(string) $local => ['sso_id' => 501, 'updated_at' => '2026-11-10T12:00:00+00:00']],
        ])]);

        $this->assertSame(501, app(OtherAgencyMirror::class)->pushRow($this->companyId, $local));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer core-key')
            && $request['app_slug'] === 'cloudpcr'
            && $request['other_agencies'] === [
                ['local_id' => $local, 'is_active' => true, 'name' => 'Lake Shore EMS', 'state' => 'PA', 'state_agency_id' => '012345', 'agency_number' => '0123'],
            ]);

        $row = DB::table('sso_other_agencies')->where('id', $local)->first();
        $this->assertSame(501, (int) $row->sso_other_agency_id);
        $this->assertFalse((bool) $row->sso_link_pending);
        $this->assertSame('2026-11-10 12:00:00', $row->sso_updated_at);

        $this->assertSame(501, app(OtherAgencyMirror::class)->pushRow($this->companyId, $local), 'A linked row answers its id without asking SSO again.');
        Http::assertSentCount(1);
    }

    public function test_push_row_fails_soft_and_leaves_the_row_unlinked(): void
    {
        Log::spy();
        $local = $this->localAgency($this->companyId, 'Lake Shore EMS');

        Http::fake(['sso.test/*' => Http::response(['message' => 'down'], 503)]);
        $this->assertNull(app(OtherAgencyMirror::class)->pushRow($this->companyId, $local));

        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        $this->assertNull(app(OtherAgencyMirror::class)->pushRow($this->companyId, $local));

        $this->assertNull(DB::table('sso_other_agencies')->where('id', $local)->value('sso_other_agency_id'));
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_push_row_refuses_another_companys_row_and_an_unlinked_company(): void
    {
        Http::fake();
        $theirs = $this->localAgency($this->company(80), 'Lake Shore EMS');
        $orphanCompany = (int) DB::table('companies')->insertGetId(['name' => 'Local only', 'sso_company_id' => null, 'timezone' => 'America/New_York']);
        $orphanRow = $this->localAgency($orphanCompany, 'Lake Shore EMS');

        $this->assertNull(app(OtherAgencyMirror::class)->pushRow($this->companyId, $theirs));
        $this->assertNull(app(OtherAgencyMirror::class)->pushRow($orphanCompany, $orphanRow));
        Http::assertNothingSent();
    }

    public function test_push_row_does_not_link_an_sso_id_another_row_holds(): void
    {
        $this->localAgency($this->companyId, 'Lake Shore EMS', 'PA', ['sso_other_agency_id' => 501]);
        $copy = $this->localAgency($this->companyId, 'lake shore ems', 'PA');

        Http::fake(['sso.test/api/internal/companies/70/other_agencies/import' => Http::response([
            'mapping' => [(string) $copy => 501],
        ])]);

        $this->assertNull(app(OtherAgencyMirror::class)->pushRow($this->companyId, $copy));
        $this->assertNull(DB::table('sso_other_agencies')->where('id', $copy)->value('sso_other_agency_id'));
    }
}
