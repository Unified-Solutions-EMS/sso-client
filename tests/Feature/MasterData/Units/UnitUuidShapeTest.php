<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Units;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\MasterData\Units\UnitProjection;
use Unified\SsoClient\Tests\Feature\MasterData\MasterDataTestCase;
use Unified\SsoClient\Tests\Stubs\UuidUnitProjection;

/**
 * A CAD-shaped app: units keyed by uuid, the Unit/Resource in `call_sign`,
 * one-off event units and soft-deleted rows out of scope, engines not
 * accepted, and an untouched trial sample never pushed. Proves the shared
 * catalog code carries string local ids through linking, the push and the
 * mapping.
 */
class UnitUuidShapeTest extends MasterDataTestCase
{
    private int $companyId;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.app_slug', 'cad');
        $app['config']->set('sso.master_data.units', true);
        $app->bind(UnitProjection::class, UuidUnitProjection::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('cad_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('call_sign')->nullable();
            $table->string('level_of_care')->nullable();
            $table->date('one_off_date')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        (require __DIR__.'/../../../../database/master-data/2026_10_16_100000_create_or_extend_units_mirror.php')->up();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function local(string $id, string $name, ?string $callSign, array $attributes = []): string
    {
        DB::table('cad_units')->insert(['id' => $id, 'company_id' => $this->companyId, 'name' => $name, 'call_sign' => $callSign, 'created_at' => now(), 'updated_at' => now()] + $attributes);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function unit(int $id, string $name, array $overrides = []): array
    {
        return array_replace([
            'id' => $id, 'name' => $name, 'display_name' => null, 'unit_type' => 'ambulance', 'level_of_care' => 'bls',
            'capabilities' => [], 'vehicle_id' => null, 'location_id' => null, 'division_id' => null, 'crew_size' => null,
            'seats' => [], 'is_active' => true, 'sort_order' => 0, 'updated_at' => '2026-10-16T12:00:00+00:00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function deliverUnit(string $event, array $record): TestResponse
    {
        return $this->postWebhook($event, ['company' => ['id' => 70], 'unit' => $record, 'updated_at' => $record['updated_at']]);
    }

    public function test_deliveries_create_and_adopt_uuid_rows_and_skip_what_the_app_cannot_hold(): void
    {
        $adoptable = $this->local('aaaaaaaa-0000-4000-8000-000000000001', 'Medic One', 'm1');
        $this->local('aaaaaaaa-0000-4000-8000-000000000002', 'Event', 'M2', ['one_off_date' => '2026-10-20']);

        $this->deliverUnit('unit.created', $this->unit(801, 'M1'))->assertJson(['result' => 'linked']);
        $this->assertSame(801, (int) DB::table('cad_units')->where('id', $adoptable)->value('sso_unit_id'));

        $this->deliverUnit('unit.created', $this->unit(802, 'M2', ['display_name' => 'Medic Two']))->assertJson(['result' => 'created']);
        $created = DB::table('cad_units')->where('sso_unit_id', 802)->first();
        $this->assertSame(['M2', 'Medic Two'], [$created->call_sign, $created->name]);
        $this->assertNull(DB::table('cad_units')->where('id', 'aaaaaaaa-0000-4000-8000-000000000002')->value('sso_unit_id'), 'A one-off event unit is never adopted.');

        $this->deliverUnit('unit.updated', $this->unit(802, 'M2', ['level_of_care' => 'als', 'updated_at' => '2026-10-16T13:00:00+00:00']))->assertJson(['result' => 'updated']);
        $this->assertSame('als', DB::table('cad_units')->where('sso_unit_id', 802)->value('level_of_care'));

        $this->postWebhook('unit.batch_updated', ['company' => ['id' => 70], 'units' => [$this->unit(803, 'E3', ['unit_type' => 'engine'])]])
            ->assertJson(['status' => 'ok', 'counts' => ['skipped' => 1]]);
        $this->assertNull(DB::table('cad_units')->where('sso_unit_id', 803)->first());
    }

    public function test_the_push_sends_uuid_local_ids_and_links_them_from_the_mapping(): void
    {
        config(['sso.master_data.units' => false]);

        $medic = $this->local('bbbbbbbb-0000-4000-8000-000000000001', 'Medic 1', 'M1', ['level_of_care' => 'als']);
        $chair = $this->local('bbbbbbbb-0000-4000-8000-000000000002', 'Wheelchair 1', null);
        $this->local('bbbbbbbb-0000-4000-8000-000000000003', 'Fair', 'F1', ['one_off_date' => '2026-10-20']);
        $this->local('bbbbbbbb-0000-4000-8000-000000000004', 'Gone', 'G1', ['deleted_at' => now()]);

        Http::fake(['sso.test/api/internal/companies/70/units/import' => Http::response([
            'created' => 2, 'matched' => 0, 'conflicts' => [], 'invalid' => [],
            'mapping' => [
                $medic => ['sso_id' => 801, 'updated_at' => '2026-10-16T12:00:00+00:00', 'seats' => []],
                $chair => ['sso_id' => 802, 'updated_at' => '2026-10-16T12:00:00+00:00', 'seats' => []],
            ],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'units', '--company' => '70'])
            ->expectsOutputToContain('created=2 matched=0')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['units'] === [
            ['local_id' => $medic, 'is_active' => true, 'name' => 'M1', 'display_name' => 'Medic 1', 'level_of_care' => 'als', 'uuid' => $medic],
            ['local_id' => $chair, 'is_active' => true, 'name' => 'Wheelchair 1', 'uuid' => $chair],
        ]);
        $this->assertSame(801, (int) DB::table('cad_units')->where('id', $medic)->value('sso_unit_id'));
        $this->assertSame(802, (int) DB::table('cad_units')->where('id', $chair)->value('sso_unit_id'));
        $this->assertFalse((bool) DB::table('cad_units')->where('id', $medic)->value('sso_link_pending'));
    }

    public function test_the_projection_can_hold_back_a_company_from_the_push(): void
    {
        $this->local('cccccccc-0000-4000-8000-000000000001', 'Medic 1', null);
        $this->local('cccccccc-0000-4000-8000-000000000002', 'Medic 2', null);
        Http::fake();

        $this->artisan('sso:push-master-data', ['entity' => 'units', '--company' => '70'])
            ->expectsOutputToContain('no local units to send')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_link_by_name_links_uuid_rows(): void
    {
        $medic = $this->local('dddddddd-0000-4000-8000-000000000001', 'Medic 1', 'M1');

        Http::fake(['sso.test/api/internal/companies/70/units' => Http::response(['company' => ['id' => 70], 'units' => [$this->unit(801, 'm1')]])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'units', '--link-by-name' => true])
            ->expectsOutputToContain('linked 1')
            ->assertSuccessful();

        $this->assertSame(801, (int) DB::table('cad_units')->where('id', $medic)->value('sso_unit_id'));
    }
}
