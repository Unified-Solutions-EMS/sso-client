<?php

namespace Unified\SsoClient\Tests\Feature\MasterData;

use Illuminate\Support\Facades\DB;

class QualificationWebhookTest extends MasterDataTestCase
{
    public function test_created_event_inserts_a_mirrored_row(): void
    {
        $companyId = $this->company(70);

        $this->postWebhook('qualification.created', [
            'company' => ['id' => 70],
            'qualification' => $this->qualificationRecord(501, 'Paramedic', [
                'description' => 'ALS provider',
                'applies_to' => ['crew-scheduling', 'cloudpcr'],
            ]),
        ])->assertOk()->assertJson(['status' => 'ok', 'result' => 'created']);

        $row = $this->mirrored($companyId, 501);
        $this->assertNotNull($row);
        $this->assertSame('Paramedic', $row->name);
        $this->assertSame('ALS provider', $row->description);
        $this->assertSame(['cloudpcr', 'crew-scheduling'], json_decode($row->applies_to, true));
        $this->assertTrue((bool) $row->is_active);
    }

    public function test_updated_event_updates_the_row_and_a_repeat_is_a_no_op(): void
    {
        $companyId = $this->company(70);
        $this->postWebhook('qualification.created', [
            'company' => ['id' => 70],
            'qualification' => $this->qualificationRecord(501, 'Paramedic'),
        ]);

        $update = [
            'company' => ['id' => 70],
            'qualification' => $this->qualificationRecord(501, 'Paramedic II', ['is_active' => false]),
        ];

        $this->postWebhook('qualification.updated', $update)->assertJson(['result' => 'updated']);
        $this->postWebhook('qualification.updated', $update)->assertJson(['result' => 'unchanged']);

        $row = $this->mirrored($companyId, 501);
        $this->assertSame('Paramedic II', $row->name);
        $this->assertFalse((bool) $row->is_active);
        $this->assertSame(1, DB::table('qualifications')->count());
    }

    public function test_created_event_adopts_a_single_unlinked_row_with_the_same_name(): void
    {
        $companyId = $this->company(70);
        $existing = $this->localQualification($companyId, '  paramedic ');

        $this->postWebhook('qualification.created', [
            'company' => ['id' => 70],
            'qualification' => $this->qualificationRecord(501, 'Paramedic'),
        ])->assertJson(['result' => 'linked']);

        $this->assertSame(1, DB::table('qualifications')->count());
        $this->assertSame($existing, (int) $this->mirrored($companyId, 501)->id);
    }

    public function test_created_event_inserts_when_the_name_match_is_ambiguous(): void
    {
        $companyId = $this->company(70);
        $this->localQualification($companyId, 'Paramedic');
        $this->localQualification($companyId, 'PARAMEDIC');

        $this->postWebhook('qualification.created', [
            'company' => ['id' => 70],
            'qualification' => $this->qualificationRecord(501, 'Paramedic'),
        ])->assertJson(['result' => 'created']);

        $this->assertSame(3, DB::table('qualifications')->count());
    }

    public function test_deleted_event_deactivates_but_keeps_the_row_and_its_assignments(): void
    {
        $companyId = $this->company(70);
        $userId = $this->user(9001);
        $qualificationId = $this->localQualification($companyId, 'Paramedic', 501);
        $this->assign($userId, $companyId, $qualificationId);

        $this->postWebhook('qualification.deleted', [
            'company' => ['id' => 70],
            'qualification' => ['id' => 501],
        ])->assertOk()->assertJson(['result' => 'deactivated']);

        $row = $this->mirrored($companyId, 501);
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->is_active);
        $this->assertSame([$qualificationId], $this->assignedIds($userId, $companyId));
    }

    public function test_assignment_event_replaces_the_users_linked_set(): void
    {
        $companyId = $this->company(70);
        $userId = $this->user(9001);
        $emt = $this->localQualification($companyId, 'EMT', 500);
        $medic = $this->localQualification($companyId, 'Paramedic', 501);
        $driver = $this->localQualification($companyId, 'Driver', 502);
        $this->assign($userId, $companyId, $emt);
        $this->assign($userId, $companyId, $medic);

        $this->postWebhook('user.qualifications_changed', [
            'company' => ['id' => 70],
            'user' => ['id' => 9001],
            'qualification_ids' => [501, 502, 999],
        ])->assertOk()->assertJson(['status' => 'ok', 'added' => 1, 'removed' => 1, 'unknown' => [999]]);

        $this->assertSame([$medic, $driver], $this->assignedIds($userId, $companyId));

        $this->postWebhook('user.qualifications_changed', [
            'company' => ['id' => 70],
            'user' => ['id' => 9001],
            'qualification_ids' => [],
        ])->assertOk();

        $this->assertSame([], $this->assignedIds($userId, $companyId));
    }

    public function test_assignment_event_leaves_assignments_to_unlinked_rows_alone(): void
    {
        $companyId = $this->company(70);
        $userId = $this->user(9001);
        $legacy = $this->localQualification($companyId, 'Hazmat Tech');
        $this->localQualification($companyId, 'Paramedic', 501);
        $this->assign($userId, $companyId, $legacy);

        $this->postWebhook('user.qualifications_changed', [
            'company' => ['id' => 70],
            'user' => ['id' => 9001],
            'qualification_ids' => [],
        ])->assertOk();

        $this->assertSame([$legacy], $this->assignedIds($userId, $companyId));
    }

    public function test_assignment_event_for_an_unknown_user_is_acknowledged(): void
    {
        $this->company(70);

        $this->postWebhook('user.qualifications_changed', [
            'company' => ['id' => 70],
            'user' => ['id' => 404],
            'qualification_ids' => [501],
        ])->assertOk()->assertJson(['status' => 'skipped', 'reason' => 'user_not_found']);
    }

    public function test_events_for_an_unknown_company_are_acknowledged_without_writes(): void
    {
        $this->postWebhook('qualification.created', [
            'company' => ['id' => 999],
            'qualification' => $this->qualificationRecord(501, 'Paramedic'),
        ])->assertOk()->assertJson(['status' => 'skipped', 'reason' => 'company_not_found']);

        $this->assertSame(0, DB::table('qualifications')->count());
    }

    public function test_disabled_entity_acknowledges_and_ignores_every_event(): void
    {
        config(['sso.master_data.qualifications' => false]);
        $companyId = $this->company(70);
        $userId = $this->user(9001);
        $qualificationId = $this->localQualification($companyId, 'Paramedic', 501);
        $this->assign($userId, $companyId, $qualificationId);

        $this->postWebhook('qualification.created', [
            'company' => ['id' => 70],
            'qualification' => $this->qualificationRecord(502, 'Driver'),
        ])->assertOk()->assertJson(['status' => 'ignored']);

        $this->postWebhook('qualification.deleted', [
            'company' => ['id' => 70],
            'qualification' => ['id' => 501],
        ])->assertOk()->assertJson(['status' => 'ignored']);

        $this->postWebhook('user.qualifications_changed', [
            'company' => ['id' => 70],
            'user' => ['id' => 9001],
            'qualification_ids' => [],
        ])->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(1, DB::table('qualifications')->count());
        $this->assertTrue((bool) $this->mirrored($companyId, 501)->is_active);
        $this->assertSame([$qualificationId], $this->assignedIds($userId, $companyId));
    }

    public function test_payload_for_one_company_never_touches_another_companys_rows(): void
    {
        $companyA = $this->company(70, 'Alpha');
        $companyB = $this->company(80, 'Bravo');
        $userId = $this->user(9001);

        $bMedic = $this->localQualification($companyB, 'Paramedic');
        $bLinked = $this->localQualification($companyB, 'Driver', 502);
        $this->assign($userId, $companyB, $bMedic);
        $this->assign($userId, $companyB, $bLinked);
        $bBefore = DB::table('qualifications')->where('company_id', $companyB)->orderBy('id')->get()->toArray();

        $this->postWebhook('qualification.created', [
            'company' => ['id' => 70],
            'qualification' => $this->qualificationRecord(501, 'Paramedic'),
        ])->assertJson(['result' => 'created']);

        $this->postWebhook('qualification.updated', [
            'company' => ['id' => 70],
            'qualification' => $this->qualificationRecord(502, 'Driver (renamed)'),
        ])->assertJson(['result' => 'created']);

        $this->postWebhook('qualification.deleted', [
            'company' => ['id' => 70],
            'qualification' => ['id' => 502],
        ]);

        $this->postWebhook('user.qualifications_changed', [
            'company' => ['id' => 70],
            'user' => ['id' => 9001],
            'qualification_ids' => [501],
        ]);

        $this->assertEquals($bBefore, DB::table('qualifications')->where('company_id', $companyB)->orderBy('id')->get()->toArray());
        $this->assertSame([$bMedic, $bLinked], $this->assignedIds($userId, $companyB));
        $this->assertSame([(int) $this->mirrored($companyA, 501)->id], $this->assignedIds($userId, $companyA));
    }
}
