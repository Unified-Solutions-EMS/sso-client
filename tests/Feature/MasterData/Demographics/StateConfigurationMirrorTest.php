<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Demographics;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\Trial\TrialMirrorCleaner;

/**
 * state_configurations on the standard `sso_state_configurations` read
 * model: one row per state, matched on state.
 */
class StateConfigurationMirrorTest extends DemographicsTestCase
{
    protected string $entity = 'state_configurations';

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function configuration(int $id, string $state, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'state' => $state,
            'protocols' => ['9914001', '9914003'],
            'specialty_service_capabilities' => [],
            'billing_status' => '9923003',
            'emd_performed' => '9902001',
            'emd_vendor' => 'ProQA',
            'patient_monitoring_capabilities' => ['2215001'],
            'crew_call_signs' => ['Medic 1', 'Medic 2'],
            'dispatch_center' => 'Franklin County 911',
            'dispatch_center_phone' => '+15188910000',
            'is_active' => true,
            'sort_order' => 0,
            'updated_at' => '2026-10-14T12:00:00+00:00',
        ], $overrides);
    }

    private function mirrored(int $ssoId): ?object
    {
        return DB::table('sso_state_configurations')->where('sso_state_configuration_id', $ssoId)->first();
    }

    private function local(int $companyId, string $state, array $attributes = []): int
    {
        return (int) DB::table('sso_state_configurations')->insertGetId([
            'company_id' => $companyId,
            'state' => $state,
            'created_at' => now(),
            'updated_at' => now(),
        ] + $attributes);
    }

    public function test_webhooks_write_update_ignore_stale_and_turn_off_the_state_row(): void
    {
        $this->deliver('state_configuration.created', 70, $this->configuration(901, '36'))
            ->assertOk()->assertJson(['status' => 'ok', 'result' => 'created']);

        $row = $this->mirrored(901);
        $this->assertSame('36', $row->state);
        $this->assertSame(['Medic 1', 'Medic 2'], json_decode($row->crew_call_signs, true));
        $this->assertNull($row->specialty_service_capabilities, 'An empty list is stored as nothing.');

        $this->deliver('state_configuration.updated', 70, $this->configuration(901, '36', ['emd_vendor' => 'Priority', 'updated_at' => '2026-10-14T13:00:00+00:00']))
            ->assertJson(['result' => 'updated']);
        $this->deliver('state_configuration.updated', 70, $this->configuration(901, '36', ['emd_vendor' => 'Older', 'updated_at' => '2026-10-14T12:30:00+00:00']))
            ->assertJson(['status' => 'stale']);
        $this->assertSame('Priority', $this->mirrored(901)->emd_vendor);

        $this->deliver('state_configuration.deactivated', 70, $this->configuration(901, '36', ['is_active' => false, 'updated_at' => '2026-10-14T14:00:00+00:00']));
        $this->assertFalse((bool) $this->mirrored(901)->is_active);
        $this->assertSame(1, DB::table('sso_state_configurations')->count());
    }

    public function test_a_delivery_adopts_the_local_row_of_the_same_state_pending(): void
    {
        $local = $this->local($this->companyId, '36', ['emd_vendor' => 'Local vendor']);

        $this->deliver('state_configuration.created', 70, $this->configuration(901, '36'))->assertJson(['result' => 'linked']);
        $this->deliver('state_configuration.created', 70, $this->configuration(902, '50'))->assertJson(['result' => 'created']);

        $this->assertSame($local, (int) $this->mirrored(901)->id);
        $this->assertSame('Local vendor', $this->mirrored(901)->emd_vendor);
        $this->assertTrue((bool) $this->mirrored(901)->sso_link_pending);
    }

    public function test_the_batch_upserts_and_a_disabled_entity_is_ignored(): void
    {
        $this->postWebhook('state_configuration.batch_updated', [
            'company' => ['id' => 70],
            'ids' => [901],
            'state_configurations' => [$this->configuration(901, '36')],
        ])->assertJson(['status' => 'ok', 'counts' => ['created' => 1]]);

        config(['sso.master_data.state_configurations' => false]);

        $this->deliver('state_configuration.updated', 70, $this->configuration(901, '36', ['emd_vendor' => 'Ignored', 'updated_at' => '2026-10-14T13:00:00+00:00']))
            ->assertJson(['status' => 'ignored', 'reason' => 'master_data.state_configurations disabled']);
        $this->assertSame('ProQA', $this->mirrored(901)->emd_vendor);
    }

    public function test_resync_turns_off_a_state_sso_no_longer_lists_and_is_idempotent(): void
    {
        $dropped = $this->local($this->companyId, '50', ['sso_state_configuration_id' => 950]);

        Http::fake(['sso.test/api/internal/companies/70/state_configurations' => Http::response([
            'company' => ['id' => 70],
            'state_configurations' => [$this->configuration(901, '36')],
        ])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'state_configurations'])
            ->expectsOutputToContain('created=1 updated=0 linked=0 unchanged=0 deactivated=1')
            ->assertSuccessful();
        $this->assertFalse((bool) DB::table('sso_state_configurations')->where('id', $dropped)->value('is_active'));

        $this->artisan('sso:resync-master-data', ['entity' => 'state_configurations'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=1 deactivated=0')
            ->assertSuccessful();
    }

    public function test_the_push_sends_each_state_and_links_the_mapping(): void
    {
        config(['sso.master_data.state_configurations' => false]);
        $ny = $this->local($this->companyId, '36', ['protocols' => json_encode(['9914001']), 'emd_vendor' => 'ProQA']);

        Http::fake(['sso.test/api/internal/companies/70/state_configurations/import' => Http::response([
            'created' => 1, 'matched' => 0, 'conflicts' => [], 'invalid' => [],
            'mapping' => [(string) $ny => ['sso_id' => 901, 'updated_at' => '2026-10-14T12:00:00+00:00']],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'state_configurations', '--company' => '70'])
            ->expectsOutputToContain('linked=1 link_collisions=0')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['state_configurations'] === [
            ['local_id' => $ny, 'is_active' => true, 'state' => '36', 'protocols' => ['9914001'], 'emd_vendor' => 'ProQA'],
        ]);
        $this->assertSame(901, (int) DB::table('sso_state_configurations')->where('id', $ny)->value('sso_state_configuration_id'));
    }

    public function test_the_trial_purge_never_touches_state_configurations(): void
    {
        $row = $this->local($this->companyId, '36', ['sso_state_configuration_id' => 901]);

        $this->assertSame([], app(TrialMirrorCleaner::class)->clean($this->companyId, ['state_configurations' => [901]]));
        $this->assertNotNull(DB::table('sso_state_configurations')->where('id', $row)->first());
    }
}
