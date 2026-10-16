<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Rotations;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Rotations\RotationMirror;
use Unified\SsoClient\MasterData\Rotations\RotationProjection;
use Unified\SsoClient\MasterData\Rotations\StandardRotationProjection;
use Unified\SsoClient\Rotations\MirroredRotations;
use Unified\SsoClient\Settings\SettingEntity;
use Unified\SsoClient\Tests\Feature\MasterData\MasterDataTestCase;
use Unified\SsoClient\Trial\TrialMirrorCleaner;

/**
 * rotations on the package's sso_rotations + blocks + assignments read
 * model: rotation.* webhooks, children in place, the resync, the reader
 * (MirroredRotations, one company only), the push through the projection
 * and the trial purge.
 */
class RotationMirrorTest extends MasterDataTestCase
{
    private int $companyId;

    private int $otherCompanyId;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.master_data.rotations', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        (require __DIR__.'/../../../../database/master-data/2026_10_17_100000_create_or_extend_rotations_mirror.php')->up();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
        $this->otherCompanyId = $this->company(71);
    }

    /**
     * A 24/48 with Medic 1 at day 0 and a person at day 1.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rotation(int $id, string $name, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'uuid' => "r-{$id}",
            'name' => $name,
            'description' => null,
            'cycle_days' => 3,
            'anchor_date' => '2026-01-05',
            'pattern' => '24 on / 48 off',
            'blocks' => [
                ['id' => 501, 'uuid' => 'b-501', 'day_index' => 0, 'start_time' => '07:00', 'duration_minutes' => 1440, 'label' => null, 'sort_order' => 0],
            ],
            'assignments' => [
                ['id' => 601, 'uuid' => 'a-601', 'assignable_type' => 'unit', 'unit_id' => 801, 'user_id' => null, 'name' => 'Medic 1', 'start_day_index' => 0, 'starts_on' => '2026-01-01', 'ends_on' => null, 'is_active' => true],
                ['id' => 602, 'uuid' => 'a-602', 'assignable_type' => 'user', 'unit_id' => null, 'user_id' => 9001, 'name' => 'Pat Smith', 'start_day_index' => 1, 'starts_on' => '2026-01-01', 'ends_on' => null, 'is_active' => true],
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
    private function deliver(string $event, array $record, int $ssoCompanyId = 70): TestResponse
    {
        return $this->postWebhook($event, ['company' => ['id' => $ssoCompanyId, 'timezone' => 'America/New_York'], 'rotation' => $record, 'updated_at' => $record['updated_at']]);
    }

    private function mirroredRotation(int $companyId, int $ssoId): ?object
    {
        return DB::table('sso_rotations')->where('company_id', $companyId)->where('sso_rotation_id', $ssoId)->first();
    }

    public function test_a_webhook_writes_the_rotation_with_its_blocks_and_assignments(): void
    {
        $this->deliver('rotation.created', $this->rotation(301, '24/48 Blue'))
            ->assertOk()->assertJson(['status' => 'ok', 'result' => 'created']);

        $row = $this->mirroredRotation($this->companyId, 301);
        $this->assertSame(['24/48 Blue', 3, '2026-01-05', '24 on / 48 off'], [$row->name, (int) $row->cycle_days, substr((string) $row->anchor_date, 0, 10), $row->pattern]);

        $block = DB::table('sso_rotation_blocks')->where('rotation_id', $row->id)->first();
        $this->assertSame([501, 0, '07:00', 1440], [(int) $block->sso_rotation_block_id, (int) $block->day_index, $block->start_time, (int) $block->duration_minutes]);

        $assignments = DB::table('sso_rotation_assignments')->where('rotation_id', $row->id)->orderBy('id')->get();
        $this->assertSame(['unit', 801, null, 0], [$assignments[0]->assignable_type, (int) $assignments[0]->sso_unit_id, $assignments[0]->sso_user_id, (int) $assignments[0]->start_day_index]);
        $this->assertSame(['user', null, 9001, 1], [$assignments[1]->assignable_type, $assignments[1]->sso_unit_id, (int) $assignments[1]->sso_user_id, (int) $assignments[1]->start_day_index]);
    }

    public function test_children_are_edited_in_place_and_removed_ones_are_deleted(): void
    {
        $this->deliver('rotation.created', $this->rotation(301, '24/48 Blue'));
        $rotationId = (int) $this->mirroredRotation($this->companyId, 301)->id;
        $blockId = (int) DB::table('sso_rotation_blocks')->value('id');

        $this->deliver('rotation.updated', $this->rotation(301, '24/48 Blue', [
            'updated_at' => '2026-10-16T13:00:00+00:00',
            'blocks' => [['id' => 501, 'day_index' => 0, 'start_time' => '08:00', 'duration_minutes' => 1440]],
            'assignments' => [['id' => 601, 'assignable_type' => 'unit', 'unit_id' => 801, 'start_day_index' => 2, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_active' => true]],
        ]))->assertJson(['result' => 'updated']);

        $this->assertSame([$blockId, '08:00'], [(int) DB::table('sso_rotation_blocks')->value('id'), DB::table('sso_rotation_blocks')->value('start_time')]);
        $assignments = DB::table('sso_rotation_assignments')->where('rotation_id', $rotationId)->get();
        $this->assertCount(1, $assignments, 'A person SSO took off the rotation is not left on duty here.');
        $this->assertSame([2, '2026-12-31'], [(int) $assignments[0]->start_day_index, substr((string) $assignments[0]->ends_on, 0, 10)]);
    }

    public function test_stale_deliveries_are_ignored_batches_upsert_and_deactivation_turns_off(): void
    {
        $this->deliver('rotation.created', $this->rotation(301, '24/48 Blue', ['updated_at' => '2026-10-16T13:00:00+00:00']));
        $this->deliver('rotation.updated', $this->rotation(301, 'Old', ['updated_at' => '2026-10-16T12:00:00+00:00']))->assertJson(['status' => 'stale']);
        $this->assertSame('24/48 Blue', $this->mirroredRotation($this->companyId, 301)->name);

        $this->postWebhook('rotation.batch_updated', [
            'company' => ['id' => 70, 'timezone' => 'America/New_York'],
            'ids' => [302, 303],
            'rotations' => [$this->rotation(302, 'Kelly', ['cycle_days' => 9, 'assignments' => []]), $this->rotation(303, 'Days', ['cycle_days' => 1, 'assignments' => []])],
        ])->assertJson(['status' => 'ok', 'counts' => ['created' => 2]]);
        $this->assertSame(9, (int) $this->mirroredRotation($this->companyId, 302)->cycle_days);

        $this->deliver('rotation.deactivated', $this->rotation(301, '24/48 Blue', ['is_active' => false, 'updated_at' => '2026-10-16T14:00:00+00:00']))->assertJson(['result' => 'updated']);
        $this->assertFalse((bool) $this->mirroredRotation($this->companyId, 301)->is_active);
        $this->assertSame(3, DB::table('sso_rotations')->count());
    }

    public function test_a_disabled_entity_is_ignored(): void
    {
        config(['sso.master_data.rotations' => false]);

        $this->deliver('rotation.created', $this->rotation(301, '24/48 Blue'))
            ->assertJson(['status' => 'ignored', 'reason' => 'master_data.rotations disabled']);
        $this->assertSame(0, DB::table('sso_rotations')->count());
    }

    public function test_resync_turns_off_dropped_rotations_and_is_idempotent(): void
    {
        $dropped = (int) DB::table('sso_rotations')->insertGetId(['company_id' => $this->companyId, 'name' => 'Gone', 'cycle_days' => 1, 'sso_rotation_id' => 399]);

        Http::fake(['sso.test/api/internal/companies/70/rotations' => Http::response([
            'company' => ['id' => 70, 'timezone' => 'America/New_York'],
            'rotations' => [$this->rotation(301, '24/48 Blue')],
        ])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'rotations'])
            ->expectsOutputToContain('created=1 updated=0 linked=0 unchanged=0 deactivated=1')
            ->assertSuccessful();
        $this->assertFalse((bool) DB::table('sso_rotations')->where('id', $dropped)->value('is_active'));

        $this->artisan('sso:resync-master-data', ['entity' => 'rotations'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=1 deactivated=0')
            ->assertSuccessful();
        $this->assertSame(2, DB::table('sso_rotation_assignments')->count());
    }

    public function test_the_reader_expands_the_mirror_for_one_company_only(): void
    {
        $this->deliver('rotation.created', $this->rotation(301, '24/48 Blue'));
        $this->deliver('rotation.created', $this->rotation(311, 'Other agency', [
            'blocks' => [['id' => 511, 'day_index' => 0, 'start_time' => '06:00', 'duration_minutes' => 600]],
            'assignments' => [['id' => 611, 'assignable_type' => 'unit', 'unit_id' => 801, 'start_day_index' => 0, 'starts_on' => '2026-01-01', 'is_active' => true]],
        ]), 71);

        $reader = app(MirroredRotations::class);

        $unit = $reader->windows($this->companyId, '2026-01-05', '2026-01-07', 'America/New_York', ssoUnitId: 801);
        $this->assertSame([['2026-01-05', '2026-01-05T07:00:00-05:00', '2026-01-06T07:00:00-05:00', 301, 601]], array_map(
            fn (array $window): array => [$window['date'], $window['starts_at'], $window['ends_at'], $window['rotation_id'], $window['assignment_id']],
            $unit,
        ), 'The other company holds an assignment for the same SSO unit id; it never shows here.');

        $person = $reader->windows($this->companyId, '2026-01-05', '2026-01-07', 'America/New_York', ssoUserId: 9001);
        $this->assertSame(['2026-01-04', '2026-01-07'], array_column($person, 'date'), 'Start day 1 at the anchor: on the day before (running into the range) and two days later.');

        $this->assertSame(['24/48 Blue'], array_column($reader->options($this->companyId), 'name'));
        $this->assertNull($reader->rotation($this->companyId, 311));
        $this->assertSame(['2026-01-05', '2026-01-08'], array_column($reader->patternWindows($this->companyId, 301, 0, '2026-01-05', '2026-01-08', 'America/New_York'), 'date'));

        $this->deliver('rotation.deactivated', $this->rotation(301, '24/48 Blue', ['is_active' => false, 'updated_at' => '2026-10-16T13:00:00+00:00']));
        $this->assertSame([], $reader->windows($this->companyId, '2026-01-05', '2026-01-07', 'America/New_York'));
    }

    public function test_the_push_sends_the_projection_rows_and_links_through_it(): void
    {
        config(['sso.master_data.rotations' => false]);
        $projection = new class extends StandardRotationProjection
        {
            /** @var list<array{int|string, int, array<string, int>}> */
            public array $linked = [];

            public function pushRotations(int $localCompanyId): array
            {
                return [[
                    'local_id' => 12,
                    'name' => str_repeat('A', 105),
                    'cycle_days' => 21,
                    'anchor_date' => '2026-01-05',
                    'blocks' => [['day_index' => 0, 'start_time' => '07:00', 'duration_minutes' => 1440]],
                    'assignments' => [['local_id' => 't12', 'unit_sso_id' => 801, 'start_day_index' => 0]],
                ]];
            }

            public function linkPushed(int $localCompanyId, int|string $localId, int $ssoId, array $assignments): ?string
            {
                $this->linked[] = [$localId, $ssoId, $assignments];

                return null;
            }
        };
        $this->app->instance(RotationProjection::class, $projection);

        Http::fake(['sso.test/api/internal/companies/70/rotations/import' => Http::response([
            'created' => 1, 'matched' => 0, 'filled' => [], 'partial' => [], 'invalid' => [], 'conflicts' => [],
            'unresolved_units' => [['local_id' => '12', 'name' => 'A', 'unit_sso_id' => 801, 'unit_local_id' => null, 'reason' => 'turned_off_in_sso']],
            'unknown_users' => [],
            'mapping' => ['12' => ['sso_id' => 301, 'updated_at' => '2026-10-16T12:00:00+00:00', 'assignments' => ['t12' => 601]]],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'rotations', '--company' => '70'])
            ->expectsOutputToContain('cut to its first 100 characters')
            ->expectsOutputToContain('linked=1 link_collisions=0 invalid=0 filled=0 partial=0 unresolved_units=1')
            ->expectsOutputToContain('SSO could not assign these rotations to their unit')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['app_slug'] === 'crew-scheduling' && mb_strlen($request['rotations'][0]['name']) === 100 && $request['rotations'][0]['cycle_days'] === 21);
        $this->assertSame([[12, 301, ['t12' => 601]]], $projection->linked);
    }

    public function test_the_standard_projection_has_nothing_to_push(): void
    {
        $this->artisan('sso:push-master-data', ['entity' => 'rotations', '--company' => '70'])
            ->expectsOutputToContain('no local rotations to send')
            ->assertSuccessful();
    }

    public function test_the_trial_purge_removes_listed_sample_rotations_with_their_children(): void
    {
        $this->deliver('rotation.created', $this->rotation(301, '24/48 Blue'));
        $this->deliver('rotation.created', $this->rotation(302, 'Kept', ['blocks' => [], 'assignments' => []]));

        $report = app(TrialMirrorCleaner::class)->clean($this->companyId, ['rotations' => [301]]);

        $this->assertSame(['deleted' => 1, 'deactivated' => 0, 'kept' => 0, 'missing' => 0], $report['rotations']);
        $this->assertNull($this->mirroredRotation($this->companyId, 301));
        $this->assertNotNull($this->mirroredRotation($this->companyId, 302));
        $this->assertSame(0, DB::table('sso_rotation_blocks')->count() + DB::table('sso_rotation_assignments')->count());
    }

    public function test_the_registry_settings_link_and_publish_tag(): void
    {
        $registry = app(MasterDataRegistry::class);

        $this->assertInstanceOf(RotationMirror::class, $registry->mirror('rotations'));
        $this->assertSame('rotations', $registry->entityForEvent('rotation.batch_updated'));
        $this->assertSame('sso-master-data-rotations', $registry->mirror('rotations')->publishTag());
        $this->assertTrue($registry->mirror('rotations')->isInstalled());
        $this->assertSame('https://sso.test/system/rotations', SettingEntity::Rotations->settingsUrl());
    }
}
