<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\Role;
use Unified\SsoClient\Tests\Stubs\Models\User;
use Unified\SsoClient\Tests\TestCase;

/**
 * SSO reports "this person was taken out of the company" as a role change
 * carrying an empty role list. That must remove the membership here rather
 * than fall through to the User default (2026-10-21: a removal re-granted
 * the person in every app).
 */
class RoleRemovalWebhookTest extends TestCase
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
        $app['config']->set('sso.app_slug', 'billing');
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
     * @return array{0: User, 1: Company}
     */
    private function seedMember(): array
    {
        $user = User::create(['name' => 'Jonathan Example', 'email' => 'jonathan@example.com', 'sso_id' => '3309']);
        $company = Company::create(['name' => 'Unified Billing', 'sso_company_id' => '31']);
        $user->companies()->attach($company->id);

        $role = Role::create(['name' => 'User', 'company_id' => $company->id]);
        DB::table('company_user_roles')->insert([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $company];
    }

    public function test_app_role_changed_with_an_empty_role_list_detaches_the_membership(): void
    {
        [$user, $company] = $this->seedMember();

        $this->postWebhook([
            'event' => 'user.app_role_changed',
            'app_slug' => 'billing',
            'user' => ['id' => 3309, 'email' => 'jonathan@example.com'],
            'company' => ['id' => 31],
            'roles' => [],
        ])->assertOk();

        $this->assertDatabaseMissing('company_user_roles', ['user_id' => $user->id, 'company_id' => $company->id]);
        $this->assertDatabaseMissing('company_user', ['user_id' => $user->id, 'company_id' => $company->id]);
    }

    public function test_company_role_changed_with_an_empty_role_list_detaches_the_membership(): void
    {
        [$user, $company] = $this->seedMember();

        $this->postWebhook([
            'event' => 'user.company_role_changed',
            'user' => ['id' => 3309, 'email' => 'jonathan@example.com'],
            'company' => ['id' => 31],
            'roles' => [],
        ])->assertOk();

        $this->assertDatabaseMissing('company_user_roles', ['user_id' => $user->id, 'company_id' => $company->id]);
        $this->assertDatabaseMissing('company_user', ['user_id' => $user->id, 'company_id' => $company->id]);
    }

    public function test_a_role_list_still_replaces_the_roles(): void
    {
        [$user, $company] = $this->seedMember();

        $this->postWebhook([
            'event' => 'user.app_role_changed',
            'app_slug' => 'billing',
            'user' => ['id' => 3309, 'email' => 'jonathan@example.com'],
            'company' => ['id' => 31],
            'roles' => ['Admin'],
        ])->assertOk();

        $adminRoleId = Role::query()->where('company_id', $company->id)->where('name', 'Admin')->value('id');
        $this->assertDatabaseHas('company_user_roles', ['user_id' => $user->id, 'company_id' => $company->id, 'role_id' => $adminRoleId]);
        $this->assertSame(1, DB::table('company_user_roles')->where('user_id', $user->id)->where('company_id', $company->id)->count());
        $this->assertDatabaseHas('company_user', ['user_id' => $user->id, 'company_id' => $company->id]);
    }

    public function test_a_payload_without_a_roles_key_keeps_the_user_default(): void
    {
        [$user, $company] = $this->seedMember();

        $this->postWebhook([
            'event' => 'user.company_role_changed',
            'user' => ['id' => 3309, 'email' => 'jonathan@example.com'],
            'company' => ['id' => 31],
        ])->assertOk();

        $this->assertDatabaseHas('company_user_roles', ['user_id' => $user->id, 'company_id' => $company->id]);
    }
}
