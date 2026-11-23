<?php

namespace Unified\SsoClient\Tests\Feature;

use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\Role;
use Unified\SsoClient\Tests\Stubs\Models\User;
use Unified\SsoClient\Tests\TestCase;

class RosterMembersEndpointTest extends TestCase
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

        $app['config']->set('app.core_api_key', 'core-key');
    }

    public function test_the_route_requires_the_core_api_key(): void
    {
        $this->getJson('/api/internal/roster/10')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/internal/roster/10')->assertUnauthorized();
    }

    public function test_it_lists_the_sso_ids_of_the_company_members_only(): void
    {
        $company = Company::create(['name' => 'Granted EMS', 'sso_company_id' => '10']);
        $other = Company::create(['name' => 'Other EMS', 'sso_company_id' => '20']);

        $medic = User::create(['name' => 'Jordan Medic', 'email' => 'medic@example.com', 'sso_id' => '902']);
        $driver = User::create(['name' => 'Casey Driver', 'email' => 'driver@example.com', 'sso_id' => '901']);
        $localOnly = User::create(['name' => 'Local Only', 'email' => 'local@example.com']);
        $elsewhere = User::create(['name' => 'Elsewhere', 'email' => 'else@example.com', 'sso_id' => '903']);

        $medic->companies()->attach($company->id);
        $driver->companies()->attach($company->id);
        $localOnly->companies()->attach($company->id);
        $elsewhere->companies()->attach($other->id);

        $this->withToken('core-key')->getJson('/api/internal/roster/10')
            ->assertOk()
            ->assertExactJson(['provisioned' => true, 'sso_user_ids' => [901, 902]]);
    }

    public function test_a_company_this_app_does_not_have_is_a_normal_answer(): void
    {
        $this->withToken('core-key')->getJson('/api/internal/roster/77')
            ->assertOk()
            ->assertExactJson(['provisioned' => false, 'sso_user_ids' => []]);
    }

    public function test_a_company_with_nobody_yet_is_provisioned_and_empty(): void
    {
        Company::create(['name' => 'New EMS', 'sso_company_id' => '30']);

        $this->withToken('core-key')->getJson('/api/internal/roster/30')
            ->assertOk()
            ->assertExactJson(['provisioned' => true, 'sso_user_ids' => []]);
    }
}
