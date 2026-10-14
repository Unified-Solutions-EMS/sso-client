<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Facilities;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * SSO matches an agency's duplicate facilities (the same code, or the same
 * name and ZIP, listed twice) to ONE SSO row and answers with both local ids
 * mapped to it. CloudPCR's `dem_facility_entries` has no company_id, so its
 * SSO link is unique across the whole table: the push must link one entry,
 * report the rest, and never stop on the unique index (CloudPCR companies
 * 29, 145 and 343, 2026-10-14: "Duplicate entry '6176'").
 */
class FacilityPushCollisionTest extends FacilitiesTestCase
{
    private const HEADERS = ['Local id', 'Name', 'SSO id', 'Linked to local id', 'Not linked because'];

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sso.master_data.facilities' => false]);
        $this->companyId = $this->company(70);
    }

    /**
     * @param  array<int|string, mixed>  $mapping
     */
    private function fakeImport(array $mapping): void
    {
        Http::fake(['sso.test/api/internal/companies/70/facilities/import' => Http::response([
            'created' => 1, 'matched' => count($mapping) - 1, 'conflicts' => [], 'invalid' => [], 'mapping' => $mapping,
        ])]);
    }

    private function link(int $id): ?int
    {
        $value = DB::table('dem_facility_entries')->where('id', $id)->value('sso_facility_id');

        return $value === null ? null : (int) $value;
    }

    public function test_two_local_entries_mapped_to_one_sso_id_link_the_first_and_report_the_second(): void
    {
        $first = $this->localFacility($this->companyId, 'Mercy Hospital', ['dfacility_03' => 'H-1', 'dfacility_10' => '12983']);
        $copy = $this->localFacility($this->companyId, 'Mercy Hospital', ['dfacility_03' => 'h-1', 'dfacility_10' => '12983']);
        $other = $this->localFacility($this->companyId, 'Adirondack Medical Center');
        $this->fakeImport([
            (string) $first => ['sso_id' => 6176, 'updated_at' => '2026-10-14T12:00:00+00:00'],
            (string) $copy => ['sso_id' => 6176, 'updated_at' => '2026-10-14T12:00:00+00:00'],
            (string) $other => ['sso_id' => 6177, 'updated_at' => '2026-10-14T12:00:00+00:00'],
        ]);

        foreach (['first run', 'rerun'] as $run) {
            $this->artisan('sso:push-master-data', ['entity' => 'facilities', '--company' => '70', '--batch' => '1'])
                ->expectsOutputToContain('linked=2 link_collisions=1')
                ->expectsTable(self::HEADERS, [[(string) $copy, 'Mercy Hospital', '6176', (string) $first, 'sso_id_held_by_another_local_row']])
                ->assertSuccessful();

            $this->assertSame([6176, null, 6177], [$this->link($first), $this->link($copy), $this->link($other)], $run);
        }

        $this->assertSame(3, DB::table('dem_facility_entries')->count(), 'the duplicate entry is never deleted');
        $this->assertSame('Mercy Hospital', DB::table('dem_facility_entries')->where('id', $copy)->value('dfacility_02'));
    }

    public function test_an_sso_id_held_outside_the_company_scope_is_reported_not_written(): void
    {
        $elsewhere = $this->localFacility($this->company(80), 'Mercy Hospital', ['sso_facility_id' => 6176]);
        $ours = $this->localFacility($this->companyId, 'Mercy Hospital');
        $next = $this->localFacility($this->companyId, 'Adirondack Medical Center');
        $this->fakeImport([(string) $ours => ['sso_id' => 6176], (string) $next => ['sso_id' => 6177]]);

        $this->artisan('sso:push-master-data', ['entity' => 'facilities', '--company' => '70'])
            ->expectsOutputToContain('linked=1 link_collisions=1')
            ->expectsTable(self::HEADERS, [[(string) $ours, 'Mercy Hospital', '6176', (string) $elsewhere, 'sso_id_held_by_another_local_row']])
            ->assertSuccessful();

        $this->assertSame([6176, null, 6177], [$this->link($elsewhere), $this->link($ours), $this->link($next)]);
    }

    public function test_a_link_written_between_the_check_and_the_write_is_reported_and_the_push_goes_on(): void
    {
        $ours = $this->localFacility($this->companyId, 'Mercy Hospital');
        $next = $this->localFacility($this->companyId, 'Adirondack Medical Center');
        $intruder = $this->localFacility($this->company(80), 'Mercy Hospital');
        $this->fakeImport([(string) $ours => ['sso_id' => 6176], (string) $next => ['sso_id' => 6177]]);

        // Another process (a webhook delivery) links 6176 right after this
        // push looked for a holder and before it writes.
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed, $intruder): void {
            if ($armed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'sso_facility_id') && in_array(6176, $query->bindings, true)) {
                $armed = false;
                DB::table('dem_facility_entries')->where('id', $intruder)->update(['sso_facility_id' => 6176]);
            }
        });

        $this->artisan('sso:push-master-data', ['entity' => 'facilities', '--company' => '70'])
            ->expectsOutputToContain('linked=1 link_collisions=1')
            ->expectsTable(self::HEADERS, [[(string) $ours, 'Mercy Hospital', '6176', (string) $intruder, 'sso_id_held_by_another_local_row']])
            ->assertSuccessful();

        $this->assertSame([null, 6177, 6176], [$this->link($ours), $this->link($next), $this->link($intruder)]);
    }
}
