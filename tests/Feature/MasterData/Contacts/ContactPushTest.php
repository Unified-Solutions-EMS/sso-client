<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Contacts;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * sso:push-master-data agency_contacts, run with the entity still off: the
 * company's contacts go up in the import shape and SSO's mapping links
 * them in place.
 */
class ContactPushTest extends ContactsTestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sso.master_data.agency_contacts' => false]);
        $this->companyId = $this->company(70);
    }

    public function test_it_sends_the_contacts_and_links_the_mapping(): void
    {
        $director = $this->localContact($this->companyId, 'director', 'Sam', 'Ortiz', [
            'is_primary' => true,
            'title' => 'Chief',
            'phones' => json_encode([['type' => 'work', 'number' => '+15185550123']]),
            'address_zip' => '12983',
            'address_state' => '36',
        ]);
        $retired = $this->localContact($this->companyId, 'medical_director', 'Dana', 'Reyes', ['is_active' => false, 'md_board_certified' => false]);
        $this->localContact($this->company(80), 'director', 'Not', 'Ours');

        Http::fake(['sso.test/api/internal/companies/70/agency_contacts/import' => Http::response([
            'created' => 1, 'matched' => 1, 'conflicts' => [], 'invalid' => [],
            'mapping' => [
                (string) $director => ['sso_id' => 901, 'updated_at' => '2026-10-14T12:00:00+00:00'],
                (string) $retired => ['sso_id' => 902, 'updated_at' => '2026-10-14T12:00:00+00:00'],
            ],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'agency_contacts', '--company' => '70'])
            ->expectsOutputToContain('linked=2 link_collisions=0')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['app_slug'] === 'billing' && $request['agency_contacts'] === [
            [
                'local_id' => $director, 'is_active' => true,
                'type' => 'director', 'is_primary' => true, 'last_name' => 'Ortiz', 'first_name' => 'Sam', 'title' => 'Chief',
                'phones' => [['type' => 'work', 'number' => '+15185550123']],
                'address' => ['state' => '36', 'zip' => '12983'],
            ],
            [
                'local_id' => $retired, 'is_active' => false,
                'type' => 'medical_director', 'is_primary' => false, 'last_name' => 'Reyes', 'first_name' => 'Dana', 'md_board_certified' => false,
            ],
        ]);

        $this->assertSame($director, (int) $this->mirrored(901)->id);
        $this->assertSame('2026-10-14 12:00:00', DB::table('sso_agency_contacts')->where('id', $director)->value('sso_updated_at'));
        $this->assertSame(902, (int) DB::table('sso_agency_contacts')->where('id', $retired)->value('sso_agency_contact_id'));
        $this->assertSame('Chief', DB::table('sso_agency_contacts')->where('id', $director)->value('title'), 'The push changes no local field.');
    }

    public function test_a_dry_run_sends_nothing(): void
    {
        $this->localContact($this->companyId, 'director', 'Sam', 'Ortiz');
        Http::fake();

        $this->artisan('sso:push-master-data', ['entity' => 'agency_contacts', '--company' => '70', '--dry-run' => true])->assertSuccessful();

        Http::assertNothingSent();
    }
}
