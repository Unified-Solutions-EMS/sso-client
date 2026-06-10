<?php

namespace Unified\SsoClient\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\Role;
use Unified\SsoClient\Tests\Stubs\Models\User;
use Unified\SsoClient\Tests\Stubs\StubSynchronizer;
use Unified\SsoClient\Tests\TestCase;

class SsoUserSynchronizerTest extends TestCase
{
    private function sync(array $payload): array
    {
        return (new StubSynchronizer)->synchronize($payload);
    }

    /**
     * @param  array<int, array{id: int, name?: string, roles?: array<int, string>, legacyTenantId?: int|string|null}>  $companies
     */
    private function payload(array $userOverrides, array $companies, ?array $selected = null): array
    {
        return [
            'user' => array_merge([
                'id' => 5001,
                'email' => 'medic@example.com',
                'displayName' => 'Jordan Medic',
                'username' => 'jmedic',
            ], $userOverrides),
            'companies' => $companies,
            'selectedCompany' => $selected,
        ];
    }

    public function test_it_creates_user_company_membership_and_roles_on_first_login(): void
    {
        [$user, $selected] = $this->sync($this->payload(
            [],
            [['id' => 70, 'name' => 'Acme EMS', 'roles' => ['Admin']]],
            ['id' => 70],
        ));

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('medic@example.com', $user->email);
        $this->assertSame('5001', $user->sso_id);

        $company = Company::where('sso_company_id', 70)->first();
        $this->assertNotNull($company);
        $this->assertNotNull($selected);
        $this->assertSame($company->id, $selected->id);

        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $user->id]);

        $adminRole = Role::where('name', 'Admin')->where('company_id', $company->id)->first();
        $this->assertNotNull($adminRole);
        $this->assertDatabaseHas('company_user_roles', [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role_id' => $adminRole->id,
        ]);
    }

    public function test_it_attaches_a_user_to_every_company_in_the_payload(): void
    {
        $companies = [];
        for ($i = 1; $i <= 4; $i++) {
            $companies[] = ['id' => 100 + $i, 'name' => "Agency {$i}", 'roles' => ['User']];
        }

        [$user] = $this->sync($this->payload([], $companies, ['id' => 102]));

        $this->assertSame(4, DB::table('company_user')->where('user_id', $user->id)->count());
        $this->assertSame(4, Company::count());
    }

    public function test_it_links_an_existing_company_by_sso_company_id_without_duplicating(): void
    {
        $existing = Company::create(['name' => 'Pre-existing', 'sso_company_id' => 70]);

        $this->sync($this->payload([], [['id' => 70, 'name' => 'Renamed', 'roles' => ['User']]]));

        $this->assertSame(1, Company::where('sso_company_id', 70)->count());
        $this->assertSame(1, Company::count());
        $this->assertDatabaseHas('company_user', ['company_id' => $existing->id]);
    }

    public function test_it_links_an_existing_company_by_core_tenant_id(): void
    {
        $existing = Company::create(['name' => 'Legacy Co', 'core_tenant_id' => '3875']);

        $this->sync($this->payload([], [
            ['id' => 88, 'name' => 'Legacy Co', 'legacyTenantId' => 3875, 'roles' => ['User']],
        ]));

        $existing->refresh();
        $this->assertSame('88', (string) $existing->sso_company_id);
        $this->assertSame(1, Company::count());
    }

    public function test_it_matches_an_existing_user_by_sso_id(): void
    {
        $user = User::create(['name' => 'Old Name', 'email' => 'old@example.com', 'sso_id' => '5001']);

        [$synced] = $this->sync($this->payload(
            ['id' => 5001, 'email' => 'new@example.com', 'displayName' => 'New Name'],
            [['id' => 70, 'roles' => ['User']]],
        ));

        $this->assertSame($user->id, $synced->id);
        $this->assertSame('new@example.com', $synced->email);
        $this->assertSame('New Name', $synced->name);
        $this->assertSame(1, User::count());
    }

    public function test_it_removes_roles_that_are_no_longer_in_the_payload(): void
    {
        // First login: Admin + User
        $this->sync($this->payload([], [['id' => 70, 'roles' => ['Admin', 'User']]]));
        $company = Company::where('sso_company_id', 70)->first();
        $user = User::first();
        $this->assertSame(2, DB::table('company_user_roles')
            ->where('company_id', $company->id)->where('user_id', $user->id)->count());

        // Second login: downgraded to User only
        $this->sync($this->payload([], [['id' => 70, 'roles' => ['User']]]));

        $userRole = Role::where('name', 'User')->where('company_id', $company->id)->first();
        $remaining = DB::table('company_user_roles')
            ->where('company_id', $company->id)->where('user_id', $user->id)->pluck('role_id');
        $this->assertSame([$userRole->id], $remaining->all());
    }

    public function test_arbitrary_role_names_flow_through(): void
    {
        // The Admin/User whitelist was removed: the role->permission meaning is
        // app-owned, so any role name SSO sends is synced verbatim.
        $this->sync($this->payload([], [['id' => 70, 'roles' => ['Wizard', 'Pirate']]]));

        $company = Company::where('sso_company_id', 70)->first();
        $roleNames = Role::where('company_id', $company->id)->pluck('name')->sort()->values()->all();

        $this->assertSame(['Pirate', 'Wizard'], $roleNames);
    }

    public function test_empty_roles_fall_back_to_user(): void
    {
        $this->sync($this->payload([], [['id' => 70, 'roles' => []]]));

        $company = Company::where('sso_company_id', 70)->first();
        $roleNames = Role::where('company_id', $company->id)->pluck('name')->all();

        $this->assertSame(['User'], $roleNames);
    }

    public function test_it_selects_the_single_company_when_none_is_explicitly_selected(): void
    {
        [, $selected] = $this->sync($this->payload([], [['id' => 70, 'roles' => ['User']]]));

        $this->assertNotNull($selected);
        $this->assertSame('70', (string) $selected->sso_company_id);
    }

    public function test_sync_query_count_does_not_scale_with_the_number_of_companies(): void
    {
        $build = function (int $userId, int $base, int $count): array {
            $companies = [];
            for ($i = 1; $i <= $count; $i++) {
                $companies[] = ['id' => $base + $i, 'name' => "Co{$base}-{$i}", 'roles' => ['Admin', 'User']];
            }

            return $this->payload(['id' => $userId, 'email' => "u{$userId}@example.com"], $companies, ['id' => $base + 1]);
        };

        $measureSteadyState = function (array $payload): int {
            $this->sync($payload); // warm: create everything

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->sync($payload); // measure a repeat login

            return count(DB::getQueryLog());
        };

        $small = $measureSteadyState($build(1, 1000, 3));
        $large = $measureSteadyState($build(2, 2000, 12));

        // A repeat login must cost the same whether the user has 3 companies or
        // 12 — that constant count is the whole point of the batching.
        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(12, $large);
    }

    public function test_it_returns_no_user_when_payload_has_neither_id_nor_email(): void
    {
        [$user, $selected] = $this->sync([
            'user' => ['displayName' => 'Ghost'],
            'companies' => [],
        ]);

        $this->assertNull($user);
        $this->assertNull($selected);
    }
}
