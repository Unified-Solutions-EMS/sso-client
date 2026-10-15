<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Demographics;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\Trial\TrialMirrorCleaner;

/**
 * agency (one profile per company) on the standard `sso_agency_profiles`
 * read model: agency.updated applies the whole profile, the company's only
 * row is adopted, nothing is ever turned off, and the push sends one
 * object.
 */
class AgencyMirrorTest extends DemographicsTestCase
{
    protected string $entity = 'agency';

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
    private function agency(int $id, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'state' => '36',
            'state_agency_id' => 'NY-1234',
            'agency_number' => '1234',
            'npi' => '1234567893',
            'tax_id' => null,
            'tax_status' => '1016001',
            'license_number' => 'L-55',
            'is_licensed_agency' => true,
            'primary_service_type' => '9920001',
            'other_service_types' => ['9920003'],
            'level_of_service' => '9917003',
            'organization_status' => '1016003',
            'organization_type' => '9912001',
            'fire_department_id' => null,
            'neris_department_id' => null,
            'statistical_year' => 2025,
            'total_calls' => 4200,
            'address' => ['street' => '1 Main St', 'city_name' => 'Saranac Lake', 'state' => '36', 'zip' => '12983'],
            'mailing' => ['same_as_physical' => true],
            'latitude' => 44.3247,
            'longitude' => -74.1313,
            'phone' => '+15188910000',
            'fax' => null,
            'email' => 'office@example.com',
            'website' => null,
            'custom_values' => ['itAgency.100' => ['value' => 'Y', 'nv' => null]],
            'service_areas' => [['id' => 1, 'state' => '36', 'county' => '36033', 'zip' => '12983', 'sort_order' => 0]],
            'year_stats' => [['id' => 1, 'year' => 2025, 'call_center_volume' => 5000, 'transport_volume' => 3100]],
            'updated_at' => '2026-10-14T12:00:00+00:00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $agency
     */
    private function deliverAgency(string $event, array $agency, int $ssoCompanyId = 70): TestResponse
    {
        return $this->postWebhook($event, [
            'company' => ['id' => $ssoCompanyId, 'name' => 'Saranac Lake Volunteer Rescue', 'timezone' => 'America/New_York'],
            'agency' => $agency,
            'updated_at' => $agency['updated_at'] ?? null,
        ]);
    }

    private function row(): ?object
    {
        return DB::table('sso_agency_profiles')->where('company_id', $this->companyId)->first();
    }

    public function test_agency_updated_applies_the_whole_profile_and_an_older_delivery_is_ignored(): void
    {
        $this->deliverAgency('agency.updated', $this->agency(31, ['npi_nv' => null, 'fax_nv' => '7701003']))
            ->assertOk()->assertJson(['status' => 'ok', 'result' => 'created']);

        $row = $this->row();
        $this->assertSame(31, (int) $row->sso_agency_id);
        $this->assertSame('NY-1234', $row->state_agency_id);
        $this->assertTrue((bool) $row->is_licensed_agency);
        $this->assertSame([['id' => 1, 'state' => '36', 'county' => '36033', 'zip' => '12983', 'sort_order' => 0]], json_decode($row->service_areas, true));
        $this->assertSame(['itAgency.100' => ['value' => 'Y', 'nv' => null]], json_decode($row->custom_values, true));
        $this->assertSame(['fax_nv' => '7701003'], json_decode($row->not_values, true));

        $this->deliverAgency('agency.updated', $this->agency(31, ['npi' => '9876543210', 'service_areas' => [], 'updated_at' => '2026-10-14T13:00:00+00:00']))
            ->assertJson(['result' => 'updated']);
        $this->deliverAgency('agency.updated', $this->agency(31, ['npi' => 'older', 'updated_at' => '2026-10-14T12:30:00+00:00']))
            ->assertJson(['status' => 'stale']);

        $this->assertSame('9876543210', $this->row()->npi);
        $this->assertNull($this->row()->service_areas);
        $this->assertSame(1, DB::table('sso_agency_profiles')->count());
    }

    public function test_the_companys_only_row_is_adopted_pending_and_another_companys_row_is_untouched(): void
    {
        $local = (int) DB::table('sso_agency_profiles')->insertGetId(['company_id' => $this->companyId, 'npi' => 'local npi', 'created_at' => now(), 'updated_at' => now()]);
        $other = (int) DB::table('sso_agency_profiles')->insertGetId(['company_id' => $this->company(80), 'created_at' => now(), 'updated_at' => now()]);

        $this->deliverAgency('agency.updated', $this->agency(31))->assertJson(['result' => 'linked']);

        $row = $this->row();
        $this->assertSame($local, (int) $row->id);
        $this->assertTrue((bool) $row->sso_link_pending);
        $this->assertSame('local npi', $row->npi, 'A pending adoption keeps the agency\'s fields.');
        $this->assertNull(DB::table('sso_agency_profiles')->where('id', $other)->value('sso_agency_id'));

        $this->deliverAgency('agency.updated', $this->agency(31, ['updated_at' => '2026-10-14T13:00:00+00:00']))->assertJson(['result' => 'pending']);
    }

    public function test_the_batch_applies_the_profile_and_a_disabled_entity_is_ignored(): void
    {
        $this->postWebhook('agency.batch_updated', [
            'company' => ['id' => 70],
            'ids' => [31],
            'agency' => [$this->agency(31)],
        ])->assertJson(['status' => 'ok', 'counts' => ['created' => 1]]);

        config(['sso.master_data.agency' => false]);

        $this->deliverAgency('agency.updated', $this->agency(31, ['npi' => 'ignored', 'updated_at' => '2026-10-14T13:00:00+00:00']))
            ->assertJson(['status' => 'ignored', 'reason' => 'master_data.agency disabled']);
        $this->assertSame('1234567893', $this->row()->npi);
    }

    public function test_resync_applies_the_profile_never_turns_anything_off_and_is_idempotent(): void
    {
        Http::fakeSequence('sso.test/api/internal/companies/70/agency')
            ->push(['company' => ['id' => 70, 'name' => 'Agency 70', 'timezone' => 'America/New_York'], 'agency' => null])
            ->push(['company' => ['id' => 70, 'name' => 'Agency 70', 'timezone' => 'America/New_York'], 'agency' => $this->agency(31)])
            ->push(['company' => ['id' => 70, 'name' => 'Agency 70', 'timezone' => 'America/New_York'], 'agency' => $this->agency(31)]);

        $this->artisan('sso:resync-master-data', ['entity' => 'agency'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=0 deactivated=0')
            ->assertSuccessful();
        $this->assertNull($this->row());

        $this->artisan('sso:resync-master-data', ['entity' => 'agency'])
            ->expectsOutputToContain('created=1 updated=0 linked=0 unchanged=0 deactivated=0')
            ->assertSuccessful();

        $this->artisan('sso:resync-master-data', ['entity' => 'agency'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=1 deactivated=0')
            ->assertSuccessful();
    }

    public function test_link_by_name_links_the_companys_only_row(): void
    {
        $local = (int) DB::table('sso_agency_profiles')->insertGetId(['company_id' => $this->companyId, 'created_at' => now(), 'updated_at' => now()]);

        Http::fake(['sso.test/api/internal/companies/70/agency' => Http::response(['company' => ['id' => 70, 'name' => 'Agency 70'], 'agency' => $this->agency(31)])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'agency', '--link-by-name' => true])
            ->expectsOutputToContain('linked 1, ambiguous 0, local only 0, SSO only 0')
            ->assertSuccessful();

        $this->assertSame(31, (int) DB::table('sso_agency_profiles')->where('id', $local)->value('sso_agency_id'));
    }

    public function test_the_push_sends_one_object_links_it_and_prints_what_sso_kept(): void
    {
        config(['sso.master_data.agency' => false]);
        $local = (int) DB::table('sso_agency_profiles')->insertGetId([
            'company_id' => $this->companyId,
            'npi' => '1234567893',
            'is_licensed_agency' => 1,
            'service_areas' => json_encode([['state' => '36', 'zip' => '12983']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake(['sso.test/api/internal/companies/70/agency/import' => Http::response([
            'created' => 0, 'matched' => 1, 'filled' => ['service_areas'],
            'conflicts' => [['local_id' => $local, 'name' => 'Agency 70', 'field' => 'npi', 'local_value' => '1234567893', 'sso_value' => '1111111111']],
            'mapping' => [(string) $local => ['sso_id' => 31, 'updated_at' => '2026-10-14T12:00:00+00:00']],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'agency', '--company' => '70', '--dry-run' => true])
            ->expectsOutputToContain('would send 1 agency')
            ->assertSuccessful();

        $this->artisan('sso:push-master-data', ['entity' => 'agency', '--company' => '70', '--batch' => '1'])
            ->expectsOutputToContain('created=0 matched=1 assignments_added=0 conflicts=1 truncated_descriptions=0 unknown_users=0 skipped_local_users_without_sso_id=0 linked=1 link_collisions=0 filled=1')
            ->expectsTable(['Local id', 'Name', 'Field', 'This app had', 'SSO kept'], [[(string) $local, 'Agency 70', 'npi', '1234567893', '1111111111']])
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'app_slug' => 'cloudpcr',
            'agency' => ['local_id' => $local, 'npi' => '1234567893', 'is_licensed_agency' => true, 'service_areas' => [['state' => '36', 'zip' => '12983']]],
        ]);
        $this->assertSame(31, (int) DB::table('sso_agency_profiles')->where('id', $local)->value('sso_agency_id'));
    }

    public function test_the_push_sends_nothing_for_a_company_without_an_agency_row(): void
    {
        Http::fake();

        $this->artisan('sso:push-master-data', ['entity' => 'agency', '--company' => '70'])
            ->expectsOutputToContain('no local agency to send')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_trial_purge_never_touches_the_agency(): void
    {
        DB::table('sso_agency_profiles')->insert(['company_id' => $this->companyId, 'sso_agency_id' => 31, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame([], app(TrialMirrorCleaner::class)->clean($this->companyId, ['agency' => [31]]));
        $this->assertSame(1, DB::table('sso_agency_profiles')->count());
    }
}
