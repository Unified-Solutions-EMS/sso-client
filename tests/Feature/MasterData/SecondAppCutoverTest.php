<?php

namespace Unified\SsoClient\Tests\Feature\MasterData;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\Tests\Stubs\StubSynchronizer;

/**
 * The reviewer's scenario on PR #17: Crew-Scheduling cut over first, so SSO's
 * "Narcotics" (501) has only Crew's holder (Alice, 9001). HR is the second
 * app; its local "Narcotics" is also held by Bob (9002), whom SSO has never
 * heard about for this qualification. HR must not lose Bob's assignment.
 */
class SecondAppCutoverTest extends MasterDataTestCase
{
    private int $companyId;

    private int $alice;

    private int $bob;

    private int $narcotics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
        $this->alice = $this->user(9001);
        $this->bob = $this->user(9002);
        $this->narcotics = $this->localQualification($this->companyId, 'Narcotics');
        DB::table('qualifications')->where('id', $this->narcotics)->update(['description' => 'HR wording', 'applies_to' => null]);
        $this->assign($this->alice, $this->companyId, $this->narcotics);
        $this->assign($this->bob, $this->companyId, $this->narcotics);
    }

    public function test_documented_order_push_before_enable_carries_hr_only_holders_into_sso(): void
    {
        config(['sso.master_data.qualifications' => false]);

        // Mirror disabled: Crew-era webhooks for 501 are acknowledged and ignored.
        $this->postWebhook('user.qualifications_changed', [
            'company' => ['id' => 70], 'user' => ['id' => 9002], 'qualification_ids' => [],
        ])->assertJson(['status' => 'ignored']);

        Http::fake([
            'sso.test/api/internal/companies/70/qualifications/import' => Http::response([
                'mapping' => [(string) $this->narcotics => ['sso_id' => 501, 'updated_at' => '2026-10-07T12:00:00Z']],
                'created' => 0, 'matched' => 1, 'assignments_added' => 1, 'conflicts' => [], 'unknown_users' => 0,
            ]),
            'sso.test/api/internal/companies/70/qualifications' => Http::response([
                'company' => ['id' => 70],
                'qualifications' => [$this->qualificationRecord(501, 'Narcotics', ['updated_at' => '2026-10-07T12:00:00Z'])],
                'assignments' => [
                    ['user_id' => 9001, 'qualification_ids' => [501]],
                    ['user_id' => 9002, 'qualification_ids' => [501]],
                ],
            ]),
        ]);

        $this->artisan('sso:push-master-data', ['entity' => 'qualifications'])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/import')
            && in_array(['user_sso_id' => '9002', 'local_qualification_ids' => [$this->narcotics]], $request['assignments'], true));

        config(['sso.master_data.qualifications' => true]);
        $this->artisan('sso:resync-master-data', ['entity' => 'qualifications'])->assertSuccessful();

        $this->assertSame([$this->narcotics], $this->assignedIds($this->bob, $this->companyId));
        $this->assertSame([$this->narcotics], $this->assignedIds($this->alice, $this->companyId));
    }

    public function test_enabling_before_the_push_links_by_name_without_destroying_local_data(): void
    {
        Http::fake(['sso.test/*' => Http::response(['mapping' => [(string) $this->narcotics => 501]])]);

        // SSO (Crew's catalog) announces Narcotics: adopted and linked, nothing else.
        $this->postWebhook('qualification.created', [
            'company' => ['id' => 70],
            'qualification' => $this->qualificationRecord(501, 'Narcotics', ['description' => 'Crew wording', 'applies_to' => ['crew-scheduling']]),
        ])->assertJson(['result' => 'linked']);

        $row = $this->mirrored($this->companyId, 501);
        $this->assertSame($this->narcotics, (int) $row->id);
        $this->assertSame('HR wording', $row->description);
        $this->assertNull($row->applies_to);
        $this->assertTrue((bool) $row->sso_link_pending);

        // A later update and a delete for the pending row are held back too.
        $this->postWebhook('qualification.updated', [
            'company' => ['id' => 70],
            'qualification' => $this->qualificationRecord(501, 'Narcotics', ['description' => 'Crew wording v2']),
        ])->assertJson(['result' => 'pending']);
        $this->postWebhook('qualification.deleted', [
            'company' => ['id' => 70], 'qualification' => ['id' => 501],
        ])->assertJson(['result' => 'pending']);

        // SSO's set for Bob is empty (only Crew's holders exist there).
        $this->postWebhook('user.qualifications_changed', [
            'company' => ['id' => 70], 'user' => ['id' => 9002], 'qualification_ids' => [],
        ])->assertJson(['status' => 'ok', 'removed' => 0]);

        // Bob logs in: his active set from SSO does not contain Narcotics either.
        (new StubSynchronizer)->synchronize([
            'user' => ['id' => 9002, 'email' => 'user9002@example.com'],
            'companies' => [['id' => 70, 'name' => 'Agency 70', 'qualifications' => []]],
            'selectedCompany' => ['id' => 70],
        ]);

        $this->assertSame([$this->narcotics], $this->assignedIds($this->bob, $this->companyId));
        $this->assertSame('HR wording', $this->mirrored($this->companyId, 501)->description);
        $this->assertTrue((bool) $this->mirrored($this->companyId, 501)->is_active);
        Http::assertNothingSent();

        // The push still sees Bob's assignment and the HR description.
        $this->artisan('sso:push-master-data', ['entity' => 'qualifications'])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['qualifications'][0]['description'] === 'HR wording'
            && in_array(['user_sso_id' => '9002', 'local_qualification_ids' => [$this->narcotics]], $request['assignments'], true));
        $this->assertFalse((bool) $this->mirrored($this->companyId, 501)->sso_link_pending, 'the push mapping confirms the link');
    }

    public function test_login_adoption_links_without_overwriting_description_or_applies_to(): void
    {
        DB::table('qualifications')->where('id', $this->narcotics)->update(['applies_to' => '["hr"]']);

        (new StubSynchronizer)->synchronize([
            'user' => ['id' => 9001, 'email' => 'user9001@example.com'],
            'companies' => [['id' => 70, 'name' => 'Agency 70', 'qualifications' => [['id' => 501, 'name' => 'NARCOTICS']]]],
            'selectedCompany' => ['id' => 70],
        ]);

        $row = $this->mirrored($this->companyId, 501);
        $this->assertSame($this->narcotics, (int) $row->id);
        $this->assertSame('Narcotics', $row->name);
        $this->assertSame('HR wording', $row->description);
        $this->assertSame('["hr"]', $row->applies_to);
        $this->assertSame([$this->narcotics], $this->assignedIds($this->bob, $this->companyId));
    }

    public function test_a_full_resync_confirms_a_pending_row(): void
    {
        $this->postWebhook('qualification.created', [
            'company' => ['id' => 70],
            'qualification' => $this->qualificationRecord(501, 'Narcotics'),
        ])->assertJson(['result' => 'linked']);

        Http::fake(['sso.test/*' => Http::response([
            'company' => ['id' => 70],
            'qualifications' => [$this->qualificationRecord(501, 'Narcotics', ['description' => 'SSO wording'])],
            'assignments' => [['user_id' => 9001, 'qualification_ids' => [501]], ['user_id' => 9002, 'qualification_ids' => [501]]],
        ])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'qualifications'])->assertSuccessful();

        $row = $this->mirrored($this->companyId, 501);
        $this->assertFalse((bool) $row->sso_link_pending);
        $this->assertSame('SSO wording', $row->description);
    }
}
