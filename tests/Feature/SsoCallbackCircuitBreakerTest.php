<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Unified\SsoClient\Contracts\SsoUserSynchronizerContract;
use Unified\SsoClient\SsoClient;
use Unified\SsoClient\SsoSessionState;
use Unified\SsoClient\Tests\TestCase;

/**
 * UNI-416: the callback's catch-all redirected to the login route, which
 * re-entered the SSO flow, which succeeded instantly against the user's live
 * SSO session, which came straight back to the still-failing callback. The
 * browser gave up with ERR_TOO_MANY_REDIRECTS and Sentry never heard about it.
 */
class SsoCallbackCircuitBreakerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('auth.providers.users.model', BreakerTestUser::class);
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

    public function test_the_first_failures_still_redirect_then_the_breaker_renders_an_error_page(): void
    {
        $this->bindFailingExchange();

        $session = [
            SsoSessionState::KEY_OAUTH_STATE => 'state-abc',
            SsoSessionState::KEY_CODE_VERIFIER => 'verifier-xyz',
        ];

        $first = $this->withSession($session)->get('/auth/sso/callback?state=state-abc&code=auth-code');
        $first->assertRedirect('/login');
        $first->assertSessionHas(SsoSessionState::KEY_CALLBACK_FAILURES, 1);
        $session = array_merge($session, $first->getSession()->all());

        $second = $this->withSession($session)->get('/auth/sso/callback?state=state-abc&code=auth-code');
        $second->assertRedirect('/login');
        $second->assertSessionHas(SsoSessionState::KEY_CALLBACK_FAILURES, 2);
        $session = array_merge($session, $second->getSession()->all());

        $third = $this->withSession($session)->get('/auth/sso/callback?state=state-abc&code=auth-code');

        $third->assertStatus(500);
        $third->assertSee('We could not sign you in');
        $this->assertNull($third->headers->get('Location'), 'The loop must stop, not redirect again.');
    }

    public function test_the_state_mismatch_branch_carries_the_same_breaker(): void
    {
        $session = [];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = $this->withSession($session)->get('/auth/sso/callback?state=whatever&code=auth-code');
            $response->assertRedirect('/login');
            $session = array_merge($session, $response->getSession()->all());
        }

        $tripped = $this->withSession($session)->get('/auth/sso/callback?state=whatever&code=auth-code');

        $tripped->assertStatus(500);
        $tripped->assertSee('We could not sign you in');
    }

    public function test_a_successful_login_clears_the_counter(): void
    {
        $user = BreakerTestUser::create(['name' => 'Medic', 'email' => 'medic@acme.test']);
        $this->bindSuccessfulExchange($user);

        $response = $this->withSession([
            SsoSessionState::KEY_OAUTH_STATE => 'state-abc',
            SsoSessionState::KEY_CODE_VERIFIER => 'verifier-xyz',
            // Two failures already banked from moments ago.
            SsoSessionState::KEY_CALLBACK_FAILURES => 2,
            SsoSessionState::KEY_CALLBACK_FAILED_AT => now()->timestamp,
        ])->get('/auth/sso/callback?state=state-abc&code=auth-code');

        $response->assertRedirect('/dashboard');
        $response->assertSessionMissing(SsoSessionState::KEY_CALLBACK_FAILURES);
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_failures_older_than_the_window_do_not_accumulate(): void
    {
        $this->bindFailingExchange();

        $response = $this->withSession([
            SsoSessionState::KEY_OAUTH_STATE => 'state-abc',
            SsoSessionState::KEY_CODE_VERIFIER => 'verifier-xyz',
            SsoSessionState::KEY_CALLBACK_FAILURES => 2,
            SsoSessionState::KEY_CALLBACK_FAILED_AT => now()->subMinutes(10)->timestamp,
        ])->get('/auth/sso/callback?state=state-abc&code=auth-code');

        $response->assertRedirect('/login');
        $response->assertSessionHas(SsoSessionState::KEY_CALLBACK_FAILURES, 1);
    }

    public function test_the_underlying_exception_reaches_the_apps_exception_handler(): void
    {
        $reported = [];
        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->andReturnUsing(function ($e) use (&$reported) {
            $reported[] = $e;
        });
        $handler->shouldReceive('shouldReport')->andReturn(true);
        $handler->shouldReceive('render')->andReturnUsing(fn ($request, $e) => throw $e);
        $this->app->instance(ExceptionHandler::class, $handler);

        $this->bindFailingExchange();

        $this->withSession([
            SsoSessionState::KEY_OAUTH_STATE => 'state-abc',
            SsoSessionState::KEY_CODE_VERIFIER => 'verifier-xyz',
        ])->get('/auth/sso/callback?state=state-abc&code=auth-code');

        $this->assertCount(1, $reported);
        $this->assertInstanceOf(RuntimeException::class, $reported[0]);
        $this->assertSame('token exchange exploded', $reported[0]->getMessage());
    }

    private function bindFailingExchange(): void
    {
        $client = Mockery::mock(SsoClient::class);
        $client->shouldReceive('exchangeCode')->andThrow(new RuntimeException('token exchange exploded'));
        $this->app->instance(SsoClient::class, $client);
    }

    private function bindSuccessfulExchange(BreakerTestUser $user): void
    {
        $client = Mockery::mock(SsoClient::class);
        $client->shouldReceive('exchangeCode')->andReturn([
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
        ]);
        $client->shouldReceive('fetchUser')->andReturn(['user' => ['id' => 'sso-1', 'email' => $user->email]]);
        $this->app->instance(SsoClient::class, $client);

        $this->app->bind(SsoUserSynchronizerContract::class, fn () => new class($user) implements SsoUserSynchronizerContract
        {
            public function __construct(private BreakerTestUser $user) {}

            public function synchronize(array $payload): array
            {
                return [$this->user, null];
            }
        });
    }
}

class BreakerTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = true;
}
