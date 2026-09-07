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

/**
 * UNI-438: the callback read the OAuth state with a plain get and left it in
 * the session, and the success path only calls session()->regenerate(), which
 * keeps every attribute. So the state that authorized one login stayed valid
 * forever. A browser re-navigating to the callback URL it had already used --
 * an iOS Safari tab restore, a back button, a reload -- matched that stale
 * state and went on to POST an authorization code SSO had already spent and
 * revoked, which Passport answers with 400 invalid_grant.
 */
class SsoCallbackReplayTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('auth.providers.users.model', ReplayTestUser::class);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn () => 'login')->name('login');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::table('users', function (Blueprint $table) {
            $table->rememberToken();
        });

        Schema::create('sso_session_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('action', 32);
            $table->json('payload')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_successful_callback_leaves_no_state_behind_to_replay(): void
    {
        $user = ReplayTestUser::create(['name' => 'Medic', 'email' => 'medic@acme.test']);
        $this->bindSuccessfulExchange($user);

        $response = $this->withSession([
            SsoSessionState::KEY_OAUTH_STATE => 'state-abc',
            SsoSessionState::KEY_CODE_VERIFIER => 'verifier-xyz',
        ])->get('/auth/sso/callback?state=state-abc&code=auth-code');

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user->fresh());

        // session()->regenerate() preserves attributes, so these only go away
        // because the callback consumed them.
        $response->assertSessionMissing(SsoSessionState::KEY_OAUTH_STATE);
        $response->assertSessionMissing(SsoSessionState::KEY_CODE_VERIFIER);
    }

    public function test_replaying_the_same_callback_url_never_reaches_the_token_endpoint(): void
    {
        $user = ReplayTestUser::create(['name' => 'Medic', 'email' => 'medic@acme.test']);

        // The real defect in one assertion: SSO must be asked to redeem the
        // code exactly once, however many times the browser sends the URL.
        $client = Mockery::mock(SsoClient::class);
        $client->shouldReceive('exchangeCode')->once()->andReturn([
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
        ]);
        $client->shouldReceive('fetchUser')->andReturn(['user' => ['id' => 'sso-1', 'email' => $user->email]]);
        $this->app->instance(SsoClient::class, $client);
        $this->bindSynchronizerFor($user);

        $url = '/auth/sso/callback?state=state-abc&code=auth-code';

        $first = $this->withSession([
            SsoSessionState::KEY_OAUTH_STATE => 'state-abc',
            SsoSessionState::KEY_CODE_VERIFIER => 'verifier-xyz',
        ])->get($url);

        $first->assertRedirect('/dashboard');

        // Same browser, same session, same URL a second time.
        $replay = $this->withSession($first->getSession()->all())->get($url);

        // No 500, no exception: just start the login over. SSO answers from its
        // own live session, so the user lands in the app without noticing.
        $replay->assertRedirect('/login');
    }

    public function test_a_failed_callback_also_consumes_the_state(): void
    {
        $client = Mockery::mock(SsoClient::class);
        $client->shouldReceive('exchangeCode')->once()->andThrow(new \RuntimeException('token exchange exploded'));
        $this->app->instance(SsoClient::class, $client);

        $url = '/auth/sso/callback?state=state-abc&code=auth-code';

        $failed = $this->withSession([
            SsoSessionState::KEY_OAUTH_STATE => 'state-abc',
            SsoSessionState::KEY_CODE_VERIFIER => 'verifier-xyz',
        ])->get($url);

        $failed->assertRedirect('/login');
        $failed->assertSessionMissing(SsoSessionState::KEY_OAUTH_STATE);

        // A retry of the dead URL stops at the state check rather than posting
        // the same spent code again. exchangeCode() is mocked ->once().
        $this->withSession($failed->getSession()->all())->get($url)->assertRedirect('/login');
    }

    private function bindSuccessfulExchange(ReplayTestUser $user): void
    {
        $client = Mockery::mock(SsoClient::class);
        $client->shouldReceive('exchangeCode')->andReturn([
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
        ]);
        $client->shouldReceive('fetchUser')->andReturn(['user' => ['id' => 'sso-1', 'email' => $user->email]]);
        $this->app->instance(SsoClient::class, $client);

        $this->bindSynchronizerFor($user);
    }

    private function bindSynchronizerFor(ReplayTestUser $user): void
    {
        $this->app->bind(SsoUserSynchronizerContract::class, fn () => new class($user) implements SsoUserSynchronizerContract
        {
            public function __construct(private ReplayTestUser $user) {}

            public function synchronize(array $payload): array
            {
                return [$this->user, null];
            }
        });
    }
}

class ReplayTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = true;
}
