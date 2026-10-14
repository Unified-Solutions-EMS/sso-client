<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Contacts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * SSO matches an agency's duplicate contacts (the same type and name listed
 * twice) to ONE SSO row and maps both local ids to it. The push links the
 * first, reports the rest, and never stops on the unique index.
 */
class ContactPushCollisionTest extends ContactsTestCase
{
    private const HEADERS = ['Local id', 'Name', 'SSO id', 'Linked to local id', 'Not linked because'];

    public function test_two_local_contacts_mapped_to_one_sso_id_link_the_first_and_report_the_second(): void
    {
        config(['sso.master_data.agency_contacts' => false]);
        $companyId = $this->company(70);
        $first = $this->localContact($companyId, 'director', 'Sam', 'Ortiz');
        $copy = $this->localContact($companyId, 'director', 'sam', 'ortiz');
        $other = $this->localContact($companyId, 'billing', 'Lee', 'Park');

        Http::fake(['sso.test/api/internal/companies/70/agency_contacts/import' => Http::response([
            'created' => 2, 'matched' => 1, 'conflicts' => [], 'invalid' => [],
            'mapping' => [
                (string) $first => ['sso_id' => 901, 'updated_at' => '2026-10-14T12:00:00+00:00'],
                (string) $copy => ['sso_id' => 901, 'updated_at' => '2026-10-14T12:00:00+00:00'],
                (string) $other => ['sso_id' => 902, 'updated_at' => '2026-10-14T12:00:00+00:00'],
            ],
        ])]);

        foreach (['first run', 'rerun'] as $run) {
            $this->artisan('sso:push-master-data', ['entity' => 'agency_contacts', '--company' => '70'])
                ->expectsOutputToContain('linked=2 link_collisions=1')
                ->expectsTable(self::HEADERS, [[(string) $copy, 'sam ortiz', '901', (string) $first, 'sso_id_held_by_another_local_row']])
                ->assertSuccessful();

            $this->assertSame(
                [901, null, 902],
                array_map(fn (int $id): ?int => ($value = DB::table('sso_agency_contacts')->where('id', $id)->value('sso_agency_contact_id')) === null ? null : (int) $value, [$first, $copy, $other]),
                $run,
            );
        }

        $this->assertSame(3, DB::table('sso_agency_contacts')->count(), 'The duplicate is never deleted.');
    }
}
