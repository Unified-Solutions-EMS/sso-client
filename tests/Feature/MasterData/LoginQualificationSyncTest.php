<?php

namespace Unified\SsoClient\Tests\Feature\MasterData;

use Illuminate\Support\Facades\DB;
use Unified\SsoClient\Tests\Stubs\StubSynchronizer;

class LoginQualificationSyncTest extends MasterDataTestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $companies
     * @return array{0: mixed, 1: mixed}
     */
    private function login(array $companies): array
    {
        return (new StubSynchronizer)->synchronize([
            'user' => ['id' => 9001, 'email' => 'medic@example.com', 'displayName' => 'Jordan Medic'],
            'companies' => $companies,
            'selectedCompany' => ['id' => $companies[0]['id']],
        ]);
    }

    public function test_login_mirrors_the_users_qualifications_per_company(): void
    {
        $companyA = $this->company(70, 'Alpha');
        $companyB = $this->company(80, 'Bravo');
        $existing = $this->localQualification($companyA, 'Paramedic', 501);

        [$user] = $this->login([
            ['id' => 70, 'name' => 'Alpha 70', 'roles' => ['User'], 'qualifications' => [
                ['id' => 501, 'name' => 'Paramedic'],
                ['id' => 502, 'name' => 'Driver'],
            ]],
            ['id' => 80, 'name' => 'Bravo 80', 'roles' => ['User'], 'qualifications' => []],
        ]);

        $driver = $this->mirrored($companyA, 502);
        $this->assertNotNull($driver, 'a qualification named on login is created locally');
        $this->assertSame([$existing, (int) $driver->id], $this->assignedIds($user->id, $companyA));
        $this->assertSame([], $this->assignedIds($user->id, $companyB));
        $this->assertNull($this->mirrored($companyB, 501));
    }

    public function test_login_replaces_the_previous_set_and_keeps_catalog_fields(): void
    {
        $companyId = $this->company(70);
        $userId = $this->user(9001);
        $medic = $this->localQualification($companyId, 'Paramedic', 501);
        DB::table('qualifications')->where('id', $medic)->update(['description' => 'ALS provider', 'applies_to' => '["cloudpcr"]']);
        $driver = $this->localQualification($companyId, 'Driver', 502);
        $this->assign($userId, $companyId, $driver);

        $this->login([['id' => 70, 'name' => 'Agency 70', 'qualifications' => [['id' => 501, 'name' => 'Paramedic (login name)']]]]);

        $this->assertSame([$medic], $this->assignedIds($userId, $companyId));
        $row = $this->mirrored($companyId, 501);
        $this->assertSame('Paramedic', $row->name);
        $this->assertSame('ALS provider', $row->description);
    }

    public function test_login_without_the_qualifications_key_changes_nothing(): void
    {
        $companyId = $this->company(70);
        $userId = $this->user(9001);
        $medic = $this->localQualification($companyId, 'Paramedic', 501);
        $this->assign($userId, $companyId, $medic);

        $this->login([['id' => 70, 'name' => 'Agency 70', 'roles' => ['User']]]);

        $this->assertSame([$medic], $this->assignedIds($userId, $companyId));
    }

    public function test_login_is_a_no_op_when_the_entity_is_disabled(): void
    {
        config(['sso.master_data.qualifications' => false]);
        $companyId = $this->company(70);
        $userId = $this->user(9001);
        $medic = $this->localQualification($companyId, 'Paramedic', 501);
        $this->assign($userId, $companyId, $medic);

        $this->login([['id' => 70, 'name' => 'Agency 70', 'qualifications' => [['id' => 502, 'name' => 'Driver']]]]);

        $this->assertSame([$medic], $this->assignedIds($userId, $companyId));
        $this->assertNull($this->mirrored($companyId, 502));
    }
}
