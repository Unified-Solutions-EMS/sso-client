<?php

namespace Unified\SsoClient\Tests\Feature\Assistant;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\User;
use Unified\SsoClient\Tests\TestCase;

abstract class AssistantTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.core_api_key', 'core-key');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('sso.app_slug', 'crew-scheduling');
        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('metrics.company_model', Company::class);
        $app['config']->set('metrics.user_model', User::class);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('security.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::table('users', function (Blueprint $table): void {
            $table->rememberToken();
        });

        Schema::create('sso_session_actions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('action', 32);
            $table->json('payload')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return array{0: User, 1: Company}
     */
    protected function seedMember(int $ssoUserId = 7, int $ssoCompanyId = 42): array
    {
        $company = Company::query()->create(['name' => 'Gotham EMS', 'sso_company_id' => (string) $ssoCompanyId]);
        $user = User::query()->create(['name' => 'Dana Admin', 'email' => 'dana@example.test', 'sso_id' => (string) $ssoUserId]);
        $user->companies()->attach($company->id);

        return [$user, $company];
    }
}
