<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Demographics;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\MasterData\Agency\AgencyProjection;
use Unified\SsoClient\Tests\Stubs\DemAgencyProjection;

/**
 * agency into CloudPCR's shape (DemAgencyProjection): `dem_agencies` with
 * its service areas and yearly statistics as child rows written in place
 * from SSO's lists.
 */
class AgencyCloudPcrShapeTest extends DemographicsTestCase
{
    protected string $entity = 'agency';

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70, 'Saranac Lake Rescue');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->bind(AgencyProjection::class, DemAgencyProjection::class);
    }

    protected function createAppTables(): void
    {
        Schema::create('dem_agencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            foreach (['01', '02', '03', '04', '09', '25'] as $element) {
                $table->string("dagency_{$element}")->nullable();
            }
            $table->string('dagency_09_nv')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('dem_agency_service_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dem_agency_id')->constrained('dem_agencies')->cascadeOnDelete();
            $table->string('dagency_05')->nullable();
            $table->text('dagency_06')->nullable();
            $table->text('dagency_08')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('dem_agency_year_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dem_agency_id')->constrained('dem_agencies')->cascadeOnDelete();
            $table->smallInteger('dagency_15')->nullable();
            $table->integer('dagency_18')->nullable();
            $table->integer('dagency_20')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function agency(array $overrides = []): array
    {
        return array_replace([
            'id' => 31,
            'state' => '36',
            'state_agency_id' => 'NY-1234',
            'agency_number' => '1234',
            'npi' => null,
            'npi_nv' => null,
            'primary_service_type' => null,
            'primary_service_type_nv' => '7701003',
            'service_areas' => [
                ['id' => 1, 'state' => '36', 'county' => '36033', 'zip' => '12983', 'sort_order' => 0],
                ['id' => 2, 'state' => '36', 'county' => '36031', 'zip' => '12946', 'sort_order' => 1],
            ],
            'year_stats' => [['id' => 1, 'year' => 2025, 'call_center_volume' => 5000, 'transport_volume' => 3100]],
            'updated_at' => '2026-10-14T12:00:00+00:00',
        ], $overrides);
    }

    private function deliverAgency(array $agency): TestResponse
    {
        return $this->postWebhook('agency.updated', [
            'company' => ['id' => 70, 'name' => 'Saranac Lake Rescue', 'timezone' => 'America/New_York'],
            'agency' => $agency,
            'updated_at' => $agency['updated_at'],
        ]);
    }

    /**
     * @return list<array{0: string|null, 1: string|null}>
     */
    private function areas(int $agencyId): array
    {
        return DB::table('dem_agency_service_areas')->where('dem_agency_id', $agencyId)->orderBy('sort_order')->get()
            ->map(fn (object $area): array => [$area->dagency_06, $area->dagency_08])->all();
    }

    public function test_the_profile_lands_on_dem_agencies_and_the_children_are_written_in_place(): void
    {
        $this->deliverAgency($this->agency())->assertOk()->assertJson(['result' => 'created']);

        $agency = DB::table('dem_agencies')->where('company_id', $this->companyId)->first();
        $this->assertSame('Saranac Lake Rescue', $agency->dagency_03, 'The agency name is the company name.');
        $this->assertSame('7701003', $agency->dagency_09_nv);
        $this->assertSame([['36033', '12983'], ['36031', '12946']], $this->areas((int) $agency->id));
        $firstArea = (int) DB::table('dem_agency_service_areas')->where('dem_agency_id', $agency->id)->orderBy('sort_order')->value('id');

        $this->deliverAgency($this->agency([
            'service_areas' => [['id' => 1, 'state' => '36', 'county' => '36033', 'zip' => '12983', 'sort_order' => 0]],
            'updated_at' => '2026-10-14T13:00:00+00:00',
        ]))->assertJson(['result' => 'updated']);

        $this->assertSame([['36033', '12983']], $this->areas((int) $agency->id), 'An area SSO removed leaves the pure mirror table.');
        $this->assertSame($firstArea, (int) DB::table('dem_agency_service_areas')->where('dem_agency_id', $agency->id)->value('id'), 'An unchanged area keeps its row id.');
        $this->assertSame(1, DB::table('dem_agency_year_stats')->where('dem_agency_id', $agency->id)->count());
    }

    public function test_a_soft_deleted_row_is_not_the_companys_agency_and_a_pending_adoption_keeps_its_children(): void
    {
        DB::table('dem_agencies')->insert(['company_id' => $this->companyId, 'dagency_01' => 'deleted', 'deleted_at' => now()]);
        $live = (int) DB::table('dem_agencies')->insertGetId(['company_id' => $this->companyId, 'dagency_01' => 'LOCAL']);
        DB::table('dem_agency_service_areas')->insert(['dem_agency_id' => $live, 'dagency_06' => 'local county', 'dagency_08' => '00000']);

        $this->deliverAgency($this->agency())->assertJson(['result' => 'linked']);

        $this->assertSame(31, (int) DB::table('dem_agencies')->where('id', $live)->value('sso_agency_id'));
        $this->assertSame('LOCAL', DB::table('dem_agencies')->where('id', $live)->value('dagency_01'));
        $this->assertSame([['local county', '00000']], $this->areas($live));

        Http::fake(['sso.test/api/internal/companies/70/agency' => Http::response([
            'company' => ['id' => 70, 'name' => 'Saranac Lake Rescue', 'timezone' => 'America/New_York'],
            'agency' => $this->agency(),
        ])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'agency'])
            ->expectsOutputToContain('created=0 updated=1 linked=0 unchanged=0 deactivated=0')
            ->assertSuccessful();

        $this->assertSame('NY-1234', DB::table('dem_agencies')->where('id', $live)->value('dagency_01'));
        $this->assertSame([['36033', '12983'], ['36031', '12946']], $this->areas($live), 'The resync confirms the link and writes SSO\'s lists.');
    }

    public function test_the_push_sends_the_profile_with_its_children(): void
    {
        config(['sso.master_data.agency' => false]);
        $local = (int) DB::table('dem_agencies')->insertGetId(['company_id' => $this->companyId, 'dagency_01' => 'NY-1234', 'dagency_04' => '36', 'dagency_09_nv' => '7701003']);
        DB::table('dem_agency_service_areas')->insert(['dem_agency_id' => $local, 'dagency_05' => '36', 'dagency_06' => '36033', 'dagency_08' => '12983']);
        DB::table('dem_agency_year_stats')->insert(['dem_agency_id' => $local, 'dagency_15' => 2025, 'dagency_20' => 3100]);

        Http::fake(['sso.test/api/internal/companies/70/agency/import' => Http::response([
            'created' => 1, 'matched' => 0, 'conflicts' => [],
            'mapping' => [(string) $local => ['sso_id' => 31, 'updated_at' => '2026-10-14T12:00:00+00:00']],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'agency', '--company' => '70'])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['agency'] === [
            'local_id' => $local,
            'state_agency_id' => 'NY-1234',
            'state' => '36',
            'primary_service_type_nv' => '7701003',
            'service_areas' => [['state' => '36', 'county' => '36033', 'zip' => '12983']],
            'year_stats' => [['year' => 2025, 'call_center_volume' => null, 'transport_volume' => 3100]],
        ]);
        $this->assertSame(31, (int) DB::table('dem_agencies')->where('id', $local)->value('sso_agency_id'));
    }
}
