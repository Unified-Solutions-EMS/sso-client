<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\Role;
use Unified\SsoClient\Tests\Stubs\Models\User;
use Unified\SsoClient\Tests\Stubs\StubSynchronizer;
use Unified\SsoClient\Tests\TestCase;

class MembershipPruneTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! class_exists('App\\Models\\User')) {
            class_alias(User::class, 'App\\Models\\User');
            class_alias(Company::class, 'App\\Models\\Company');
            class_alias(Role::class, 'App\\Models\\Role');
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.webhook_secret', 'whsec');
    }

    private function postWebhook(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/sso/provision', [], [], [], [
            'HTTP_X-SSO-Signature' => hash_hmac('sha256', $body, 'whsec'),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    /**
     * @return array{0: User, 1: Company, 2: Company, 3: Company}
     */
    private function seedUserInThreeCompanies(): array
    {
        $user = User::create(['name' => 'Jordan Medic', 'email' => 'medic@example.com', 'sso_id' => '901']);

        $granted = Company::create(['name' => 'Granted EMS', 'sso_company_id' => '10']);
        $stale = Company::create(['name' => 'Stale EMS', 'sso_company_id' => '20']);
        $localOnly = Company::create(['name' => 'Local Only FD']);

        foreach ([$granted, $stale, $localOnly] as $company) {
            $user->companies()->attach($company->id);
        }

        $staleRole = Role::create(['name' => 'User', 'company_id' => $stale->id]);
        DB::table('company_user_roles')->insert([
            'company_id' => $stale->id,
            'user_id' => $user->id,
            'role_id' => $staleRole->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $granted, $stale, $localOnly];
    }

    public function test_user_updated_prunes_sso_linked_memberships_absent_from_payload(): void
    {
        [$user, $granted, $stale, $localOnly] = $this->seedUserInThreeCompanies();

        $this->postWebhook([
            'event' => 'user.updated',
            'user' => ['id' => 901, 'email' => 'medic@example.com'],
            'companies' => [['id' => 10, 'name' => 'Granted EMS', 'roles' => ['User']]],
        ])->assertOk();

        $this->assertDatabaseHas('company_user', ['user_id' => $user->id, 'company_id' => $granted->id]);
        $this->assertDatabaseMissing('company_user', ['user_id' => $user->id, 'company_id' => $stale->id]);
        $this->assertDatabaseMissing('company_user_roles', ['user_id' => $user->id, 'company_id' => $stale->id]);
        $this->assertDatabaseHas('company_user', ['user_id' => $user->id, 'company_id' => $localOnly->id]);
    }

    public function test_user_updated_with_empty_company_list_prunes_all_sso_linked_memberships(): void
    {
        [$user, $granted, $stale, $localOnly] = $this->seedUserInThreeCompanies();

        $this->postWebhook([
            'event' => 'user.updated',
            'user' => ['id' => 901, 'email' => 'medic@example.com'],
            'companies' => [],
        ])->assertOk();

        $this->assertDatabaseMissing('company_user', ['user_id' => $user->id, 'company_id' => $granted->id]);
        $this->assertDatabaseMissing('company_user', ['user_id' => $user->id, 'company_id' => $stale->id]);
        $this->assertDatabaseHas('company_user', ['user_id' => $user->id, 'company_id' => $localOnly->id]);
    }

    public function test_user_updated_without_companies_key_leaves_memberships_alone(): void
    {
        [$user, $granted, $stale, $localOnly] = $this->seedUserInThreeCompanies();

        $this->postWebhook([
            'event' => 'user.updated',
            'user' => ['id' => 901, 'email' => 'medic@example.com'],
        ])->assertOk();

        $this->assertDatabaseHas('company_user', ['user_id' => $user->id, 'company_id' => $granted->id]);
        $this->assertDatabaseHas('company_user', ['user_id' => $user->id, 'company_id' => $stale->id]);
        $this->assertDatabaseHas('company_user', ['user_id' => $user->id, 'company_id' => $localOnly->id]);
    }

    public function test_user_updated_with_malformed_company_entries_leaves_memberships_alone(): void
    {
        [$user, $granted, $stale, $localOnly] = $this->seedUserInThreeCompanies();

        $this->postWebhook([
            'event' => 'user.updated',
            'user' => ['id' => 901, 'email' => 'medic@example.com'],
            'companies' => [['name' => 'No Id Here']],
        ])->assertOk();

        $this->assertDatabaseHas('company_user', ['user_id' => $user->id, 'company_id' => $granted->id]);
        $this->assertDatabaseHas('company_user', ['user_id' => $user->id, 'company_id' => $stale->id]);
        $this->assertDatabaseHas('company_user', ['user_id' => $user->id, 'company_id' => $localOnly->id]);
    }

    public function test_login_sync_prunes_sso_linked_memberships_absent_from_payload(): void
    {
        [$user, $granted, $stale, $localOnly] = $this->seedUserInThreeCompanies();

        (new StubSynchronizer)->synchronize([
            'user' => ['id' => 901, 'email' => 'medic@example.com', 'displayName' => 'Jordan Medic'],
            'companies' => [['id' => 10, 'name' => 'Granted EMS', 'roles' => ['User']]],
            'selectedCompany' => ['id' => 10],
        ]);

        $this->assertDatabaseHas('company_user', ['user_id' => $user->id, 'company_id' => $granted->id]);
        $this->assertDatabaseMissing('company_user', ['user_id' => $user->id, 'company_id' => $stale->id]);
        $this->assertDatabaseMissing('company_user_roles', ['user_id' => $user->id, 'company_id' => $stale->id]);
        $this->assertDatabaseHas('company_user', ['user_id' => $user->id, 'company_id' => $localOnly->id]);
    }
}
