<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Unified\SsoClient\Contracts\SsoUserSynchronizerContract;
use Unified\SsoClient\SsoClient;
use Unified\SsoClient\SsoSessionState;
use Unified\SsoClient\Tests\TestCase;

class SsoCallbackRedirectTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('auth.providers.users.model', CallbackTestUser::class);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn () => 'login')->name('login');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        // Auth::login($user, true) persists a remember token; the base test
        // users table doesn't include the column.
        Schema::table('users', function (Blueprint $table) {
            $table->rememberToken();
        });

        // The EnforceSsoSessionActions middleware drains this table on every
        // authenticated request, so the callback request needs it to exist.
        Schema::create('sso_session_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('action', 32);
            $table->json('payload')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_intended_url_survives_a_user_switch_during_callback(): void
    {
        $synced = $this->bindCallbackFor(
            ssoUserId: 'sso-200',
            localUserAttributes: ['name' => 'Impersonated User', 'email' => 'crew@acme.test', 'sso_id' => 'sso-200'],
        );

        // The browser already holds a *different* user's session (the admin who
        // initiated impersonation). This is what triggers the session wipe.
        $admin = CallbackTestUser::create(['name' => 'Admin', 'email' => 'admin@acme.test']);

        $intended = 'https://wiki.test/university/my-training';

        $response = $this->actingAs($admin)
            ->withSession([
                SsoSessionState::KEY_OAUTH_STATE => 'state-abc',
                SsoSessionState::KEY_CODE_VERIFIER => 'verifier-xyz',
                SsoSessionState::KEY_INTENDED_URL => $intended,
            ])
            ->get('/auth/sso/callback?state=state-abc&code=auth-code');

        $response->assertRedirect($intended);
        $this->assertAuthenticatedAs($synced->fresh());
    }

    public function test_same_user_callback_still_honors_intended_url(): void
    {
        $synced = $this->bindCallbackFor(
            ssoUserId: 'sso-300',
            localUserAttributes: ['name' => 'Returning User', 'email' => 'me@acme.test', 'sso_id' => 'sso-300'],
        );

        $intended = 'https://wiki.test/university/my-training';

        $response = $this->actingAs($synced)
            ->withSession([
                SsoSessionState::KEY_OAUTH_STATE => 'state-abc',
                SsoSessionState::KEY_CODE_VERIFIER => 'verifier-xyz',
                SsoSessionState::KEY_INTENDED_URL => $intended,
            ])
            ->get('/auth/sso/callback?state=state-abc&code=auth-code');

        $response->assertRedirect($intended);
    }

    /**
     * Wire a fake SSO exchange + a synchronizer that returns the given local
     * user, and return that freshly-created user.
     *
     * @param  array<string, mixed>  $localUserAttributes
     */
    private function bindCallbackFor(string $ssoUserId, array $localUserAttributes): CallbackTestUser
    {
        $user = CallbackTestUser::create($localUserAttributes);

        $client = Mockery::mock(SsoClient::class);
        $client->shouldReceive('exchangeCode')->andReturn([
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
        ]);
        $client->shouldReceive('fetchUser')->andReturn([
            'user' => ['id' => $ssoUserId, 'email' => $localUserAttributes['email']],
        ]);
        $this->app->instance(SsoClient::class, $client);

        $this->app->bind(SsoUserSynchronizerContract::class, fn () => new class($user) implements SsoUserSynchronizerContract
        {
            public function __construct(private CallbackTestUser $user) {}

            public function synchronize(array $payload): array
            {
                return [$this->user, null];
            }
        });

        return $user;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

class CallbackTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = true;
}
