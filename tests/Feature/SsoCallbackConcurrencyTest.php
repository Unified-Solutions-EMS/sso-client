<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\Contracts\SsoUserSynchronizerContract;
use Unified\SsoClient\SsoSessionState;
use Unified\SsoClient\Tests\TestCase;

/**
 * UNI-438, reopened. Consuming the state closed the SEQUENTIAL replay and
 * nothing else, because a session cannot enforce "once": Laravel reads the
 * payload at the start of a request and writes it back at the end, so two
 * callbacks that overlap both find the state intact and both redeem the same
 * authorization code.
 *
 * Production confirmed the shape before this test existed. Reading SSO's own
 * oauth tables around each logged failure showed a successful access token
 * issued one to three seconds BEFORE it — one code, two redemptions, the
 * second answered with 400 invalid_grant:
 *
 *   crew-scheduling, user 2716, 2026-09-09
 *     code issued 16:20:02 -> access token created 16:20:05
 *                          -> Sentry failure    16:20:07.689
 *     code issued 16:20:08 -> access token created 16:20:09
 *                          -> Sentry failure    16:20:11.264
 *
 * Each failure then redirected to login, SSO answered instantly from its live
 * session, and the whole race ran again four seconds later.
 *
 * `withSession()` reproduces the overlap faithfully: it seeds the second
 * request from the payload as it stood before the first request wrote, which
 * is exactly what the second request would have read off the shared session
 * store while the first was still busy exchanging.
 */
class SsoCallbackConcurrencyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('auth.providers.users.model', ConcurrentCallbackUser::class);
        $app['config']->set('sso.base_url', 'https://sso.example.test');
        $app['config']->set('sso.client_id', 'client-1');
        $app['config']->set('sso.client_secret', 'secret');
        $app['config']->set('sso.redirect_uri', 'https://app.example.test/auth/sso/callback');
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

    public function test_two_overlapping_callbacks_redeem_the_code_exactly_once(): void
    {
        Exceptions::fake();

        $user = ConcurrentCallbackUser::create(['name' => 'Medic', 'email' => 'medic@acme.test']);
        $this->bindSynchronizerFor($user);
        $this->fakeSsoThatSpendsACodeOnce();

        // Both requests were handed the session as it looked before either of
        // them touched it — the lost update the old code could not see.
        $session = [
            SsoSessionState::KEY_OAUTH_STATE => 'state-abc',
            SsoSessionState::KEY_CODE_VERIFIER => 'verifier-xyz',
        ];
        $url = '/auth/sso/callback?state=state-abc&code=auth-code';

        $winner = $this->withSession($session)->get($url);
        $loser = $this->withSession($session)->get($url);

        $winner->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user->fresh());

        // The whole defect in one assertion. Before the fix the loser also
        // reached the token endpoint, drew the 400, and this count was 2.
        $this->assertSame(1, $this->tokenRequestCount(), 'the authorization code was redeemed more than once');

        // Ordinary browser behaviour, not a fault: quietly start over rather
        // than raise. SSO answers login from its own live session.
        $loser->assertRedirect('/login');
        Exceptions::assertNothingReported();
    }

    public function test_the_loser_does_not_count_toward_the_callback_loop_breaker(): void
    {
        $user = ConcurrentCallbackUser::create(['name' => 'Medic', 'email' => 'medic@acme.test']);
        $this->bindSynchronizerFor($user);
        $this->fakeSsoThatSpendsACodeOnce();

        $session = [
            SsoSessionState::KEY_OAUTH_STATE => 'state-abc',
            SsoSessionState::KEY_CODE_VERIFIER => 'verifier-xyz',
        ];
        $url = '/auth/sso/callback?state=state-abc&code=auth-code';

        $this->withSession($session)->get($url)->assertRedirect('/dashboard');

        // A browser that duplicates its callback tends to keep doing it. If a
        // stand-down counted as a failure, the third one would render the
        // sign-in-failed page with a 500 — an error screen for a login that
        // actually worked.
        foreach (range(1, 4) as $ignored) {
            $this->withSession($session)->get($url)->assertRedirect('/login');
        }

        $this->assertSame(1, $this->tokenRequestCount());
    }

    public function test_a_different_login_is_not_blocked_by_an_earlier_claim(): void
    {
        $user = ConcurrentCallbackUser::create(['name' => 'Medic', 'email' => 'medic@acme.test']);
        $this->bindSynchronizerFor($user);
        $this->fakeSsoThatSpendsACodeOnce();

        $this->withSession([
            SsoSessionState::KEY_OAUTH_STATE => 'state-abc',
            SsoSessionState::KEY_CODE_VERIFIER => 'verifier-xyz',
        ])->get('/auth/sso/callback?state=state-abc&code=auth-code')->assertRedirect('/dashboard');

        // Every trip through /auth/sso/redirect mints a fresh 40-character
        // state, so a claim never stands in the way of the next real login.
        $this->withSession([
            SsoSessionState::KEY_OAUTH_STATE => 'state-def',
            SsoSessionState::KEY_CODE_VERIFIER => 'verifier-uvw',
        ])->get('/auth/sso/callback?state=state-def&code=second-code')->assertRedirect('/dashboard');

        $this->assertSame(2, $this->tokenRequestCount());
    }

    /**
     * Answer the way Passport does: the first redemption of a code succeeds,
     * every later one is 400 invalid_grant.
     */
    private function fakeSsoThatSpendsACodeOnce(): void
    {
        $spent = [];

        Http::fake([
            '*/oauth/token' => function ($request) use (&$spent) {
                $code = $request->data()['code'] ?? '';

                if (isset($spent[$code])) {
                    return Http::response([
                        'error' => 'invalid_grant',
                        'hint' => 'Authorization code has been revoked',
                    ], 400);
                }

                $spent[$code] = true;

                return Http::response([
                    'access_token' => 'access-token',
                    'refresh_token' => 'refresh-token',
                    'expires_in' => 3600,
                ]);
            },
            '*/api/user' => Http::response(['user' => ['id' => 'sso-1', 'email' => 'medic@acme.test']]),
        ]);
    }

    private function tokenRequestCount(): int
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), '/oauth/token'))->count();
    }

    private function bindSynchronizerFor(ConcurrentCallbackUser $user): void
    {
        $this->app->bind(SsoUserSynchronizerContract::class, fn () => new class($user) implements SsoUserSynchronizerContract
        {
            public function __construct(private ConcurrentCallbackUser $user) {}

            public function synchronize(array $payload): array
            {
                return [$this->user, null];
            }
        });
    }
}

class ConcurrentCallbackUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = true;
}
