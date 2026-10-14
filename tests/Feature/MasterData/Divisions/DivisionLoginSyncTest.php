<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Divisions;

use Illuminate\Support\Facades\DB;
use Unified\SsoClient\Tests\Stubs\StubSynchronizer;

/**
 * /api/user (and the roster endpoint sso:sync-users reads) carries
 * companies[].division: {id, name} or null.
 */
class DivisionLoginSyncTest extends DivisionsTestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
    }

    /**
     * @param  array<string, mixed>|null  $division
     */
    private function login(int $ssoUserId, mixed $division, bool $withKey = true): int
    {
        $company = ['id' => 70, 'name' => 'Agency 70'];

        if ($withKey) {
            $company['division'] = $division;
        }

        [$user] = (new StubSynchronizer)->synchronize([
            'user' => ['id' => $ssoUserId, 'email' => "user{$ssoUserId}@example.com"],
            'companies' => [$company],
            'selectedCompany' => ['id' => 70],
        ]);

        return (int) $user->id;
    }

    public function test_login_creates_the_division_and_places_the_person(): void
    {
        $userId = $this->login(9001, ['id' => 501, 'name' => 'North']);

        $row = $this->mirrored($this->companyId, 501);
        $this->assertSame('North', $row->name);
        $this->assertSame((int) $row->id, $this->divisionOf($userId, $this->companyId));
    }

    public function test_null_clears_a_confirmed_division_and_a_missing_key_changes_nothing(): void
    {
        $north = $this->localDivision($this->companyId, 'North', 501);
        $userId = $this->user(9001, $this->companyId, $north);

        $this->login(9001, ['id' => 501, 'name' => 'North'], withKey: false);
        $this->assertSame($north, $this->divisionOf($userId, $this->companyId));

        $this->login(9001, null);
        $this->assertNull($this->divisionOf($userId, $this->companyId));
    }

    public function test_login_adopts_a_same_name_local_division_without_renaming_it_or_moving_other_people(): void
    {
        $alpha = $this->localDivision($this->companyId, 'Alpha Shift');
        $bob = $this->user(9002, $this->companyId, $alpha);

        $alice = $this->login(9001, ['id' => 501, 'name' => 'alpha shift']);

        $row = DB::table('divisions')->find($alpha);
        $this->assertSame('Alpha Shift', $row->name);
        $this->assertTrue((bool) $row->sso_link_pending);
        $this->assertSame($alpha, $this->divisionOf($alice, $this->companyId));
        $this->assertSame($alpha, $this->divisionOf($bob, $this->companyId));
        $this->assertSame(1, DB::table('divisions')->count());
    }

    public function test_login_never_replaces_an_unconfirmed_local_division(): void
    {
        $bravo = $this->localDivision($this->companyId, 'Bravo Shift');
        $userId = $this->user(9001, $this->companyId, $bravo);

        $this->login(9001, ['id' => 501, 'name' => 'North']);

        $this->assertSame($bravo, $this->divisionOf($userId, $this->companyId));
    }

    public function test_nothing_happens_while_the_entity_is_disabled(): void
    {
        config(['sso.master_data.divisions' => false]);

        $userId = $this->login(9001, ['id' => 501, 'name' => 'North']);

        $this->assertSame(0, DB::table('divisions')->count());
        $this->assertNull($this->divisionOf($userId, $this->companyId));
    }
}
