<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Vehicles;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\MasterData\Vehicles\VehicleChildTable;
use Unified\SsoClient\MasterData\Vehicles\VehicleProjection;
use Unified\SsoClient\Tests\Stubs\DemVehicleProjection;

/**
 * A vehicle child table that declares no ChildRowPolicy is unlinked, never
 * deleted, when SSO removes the child: the row may carry the app's own
 * data. CloudPCR's pure-mirror tables declare `delete` (covered by
 * VehicleChildRowsTest).
 */
class VehicleChildRemovalTest extends VehiclesTestCase
{
    protected string $shape = 'cloudpcr';

    private int $companyId;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->bind(VehicleProjection::class, fn () => new class extends DemVehicleProjection
        {
            public function childTables(): array
            {
                $children = parent::childTables();
                $levels = $children['crew_levels'];

                $children['crew_levels'] = new class($levels) implements VehicleChildTable
                {
                    public function __construct(private readonly VehicleChildTable $inner) {}

                    public function table(): string
                    {
                        return $this->inner->table();
                    }

                    public function parentColumn(): string
                    {
                        return $this->inner->parentColumn();
                    }

                    public function keyColumn(): string
                    {
                        return $this->inner->keyColumn();
                    }

                    public function toColumns(array $child): array
                    {
                        return $this->inner->toColumns($child);
                    }

                    public function pushColumns(): array
                    {
                        return $this->inner->pushColumns();
                    }

                    public function toImport(object $row): array
                    {
                        return $this->inner->toImport($row);
                    }
                };

                return $children;
            }
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
    }

    public function test_an_undeclared_child_table_unlinks_keeps_the_row_and_leaves_it_out_of_the_push(): void
    {
        Http::fake(['sso.test/api/internal/companies/70/vehicles/import' => Http::response(['created' => 0, 'matched' => 1, 'mapping' => []])]);
        $record = $this->vehicleRecord(901, 'M-1', ['crew_levels' => [$this->crewLevel(11, '9917005', 2), $this->crewLevel(12, '9917007', 1)]]);
        $this->deliver('vehicle.created', 70, $record)->assertJson(['result' => 'created']);
        $vehicleId = (int) $this->mirrored($this->companyId, 901)->id;
        $cct = DB::table('dem_vehicle_cert_levels')->where('sso_crew_level_id', 12)->first();

        $record['crew_levels'] = [$this->crewLevel(11, '9917005', 2)];
        $record['updated_at'] = '2026-10-11T13:00:00+00:00';
        $this->deliver('vehicle.updated', 70, $record)->assertJson(['result' => 'updated']);

        $kept = DB::table('dem_vehicle_cert_levels')->find($cct->id);
        $this->assertNotNull($kept, 'never deleted without a declared delete policy');
        $this->assertNull($kept->sso_crew_level_id);
        $this->assertNotNull($kept->sso_removed_at);

        $this->deliver('vehicle.updated', 70, $record)->assertJson(['result' => 'unchanged']);
        $this->assertEquals($kept, DB::table('dem_vehicle_cert_levels')->find($cct->id), 'a repeat changes nothing');

        config(['sso.master_data.vehicles' => false]);
        $this->artisan('sso:push-master-data', ['entity' => 'vehicles', '--company' => '70'])->assertSuccessful();

        $als = (int) DB::table('dem_vehicle_cert_levels')->where('sso_crew_level_id', 11)->value('id');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/vehicles/import')
            && array_column($request['vehicles'][0]['crew_levels'] ?? [], 'local_id') === [$als]);
        $this->assertSame(2, DB::table('dem_vehicle_cert_levels')->where('dem_vehicle_id', $vehicleId)->count());
    }
}
