<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\SsoClient;
use Unified\SsoClient\SsoSessionState;
use Unified\SsoClient\SsoSingleFlight;
use Unified\SsoClient\Tests\TestCase;

/**
 * UNI-455: sessions ending mid-shift on dashboards that poll.
 *
 * Passport ROTATES refresh tokens — redeeming one revokes it and hands back a
 * replacement. A polling dashboard has several requests in flight when the
 * access token ages out; they all read the same refresh token out of the
 * session, and every one of them that loses the race presents a token SSO has
 * already revoked. The middleware read that "no" as "signed out", cleared the
 * session, and wrote the cleared copy over the winner's freshly refreshed one.
 *
 * Same shape as UNI-438: a credential that may be spent once, guarded by a
 * session that cannot make read-then-write atomic. Same answer: settle it in
 * the cache, where the whole fleet of instances contends on one key.
 */
class SsoRefreshSingleFlightTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('auth.providers.users.model', RefreshTestUser::class);
        $app['config']->set('sso.base_url', 'https://sso.example.test');
        $app['config']->set('sso.client_id', 'client-1');
        $app['config']->set('sso.client_secret', 'secret');
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn () => 'login')->name('login');
        $router->get('/board', fn () => 'board')->middleware(['web', 'sso.session']);
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
            $table->timestamps();
        });
    }

    public function test_only_one_of_several_concurrent_polls_redeems_the_refresh_token(): void
    {
        $this->fakeSsoThatRotatesRefreshTokensOnce();

        $flight = app(SsoSingleFlight::class);
        $refresh = fn (): array => app(SsoClient::class)->refreshToken('rotating-token');

        // Four polls that all read 'rotating-token' off the session before any
        // of them wrote anything back.
        $results = collect(range(1, 4))->map(fn () => $flight->refreshTokens('rotating-token', $refresh));

        $this->assertSame(1, $this->refreshRequestCount(), 'the refresh token was redeemed more than once');

        // Every caller ends up holding the same live pair, so whichever session
        // write lands last is still correct.
        foreach ($results as $tokens) {
            $this->assertSame('fresh-access', $tokens['access_token']);
            $this->assertSame('fresh-refresh', $tokens['refresh_token']);
        }
    }

    public function test_a_losing_poll_keeps_the_session_instead_of_signing_the_user_out(): void
    {
        $user = RefreshTestUser::create(['name' => 'Dispatcher', 'email' => 'dispatch@acme.test', 'sso_id' => 'sso-1']);
        $this->fakeSsoThatRotatesRefreshTokensOnce();

        // The winning poll already refreshed and published its result. This
        // request is the loser: its session still holds the spent token.
        app(SsoSingleFlight::class)->refreshTokens(
            'rotating-token',
            fn (): array => app(SsoClient::class)->refreshToken('rotating-token'),
        );

        $response = $this->actingAs($user)->withSession([
            SsoSessionState::KEY_ACCESS_TOKEN => 'stale-access',
            SsoSessionState::KEY_REFRESH_TOKEN => 'rotating-token',
            SsoSessionState::KEY_TOKEN_EXPIRES_AT => now()->subMinute()->timestamp,
            SsoSessionState::KEY_SSO_USER_ID => 'sso-1',
            SsoSessionState::KEY_TOKEN_LAST_VALIDATED_AT => now()->timestamp,
        ])->get('/board');

        // Before the fix this request replayed the spent token, got told no,
        // and answered 302 to /auth/sso/redirect with the session wiped.
        $response->assertOk();
        $this->assertAuthenticatedAs($user);
        $response->assertSessionHas(SsoSessionState::KEY_ACCESS_TOKEN, 'fresh-access');
        $response->assertSessionHas(SsoSessionState::KEY_REFRESH_TOKEN, 'fresh-refresh');

        // Still exactly one redemption: the poll adopted the winner's pair.
        $this->assertSame(1, $this->refreshRequestCount());
    }

    public function test_a_genuinely_revoked_refresh_token_still_ends_the_session(): void
    {
        $user = RefreshTestUser::create(['name' => 'Dispatcher', 'email' => 'dispatch@acme.test', 'sso_id' => 'sso-1']);

        // The user logged out of SSO. Nothing was published, nothing to adopt:
        // this must still sign them out, or logout stops propagating.
        Http::fake([
            '*/oauth/token' => Http::response(['error' => 'invalid_request', 'hint' => 'Token has been revoked'], 401),
        ]);

        $this->actingAs($user)->withSession([
            SsoSessionState::KEY_ACCESS_TOKEN => 'stale-access',
            SsoSessionState::KEY_REFRESH_TOKEN => 'dead-token',
            SsoSessionState::KEY_TOKEN_EXPIRES_AT => now()->subMinute()->timestamp,
            SsoSessionState::KEY_SSO_USER_ID => 'sso-1',
        ])->get('/board')->assertRedirect('/auth/sso/redirect?intended='.urlencode('http://localhost/board'));

        $this->assertGuest();
    }

    public function test_an_unusable_cache_still_lets_the_refresh_through(): void
    {
        $this->fakeSsoThatRotatesRefreshTokensOnce();

        // A cache outage must degrade to the old uncoordinated behaviour, never
        // to "nobody may refresh".
        Cache::shouldReceive('store')->andThrow(new \RuntimeException('cache is down'));

        $tokens = app(SsoSingleFlight::class)->refreshTokens(
            'rotating-token',
            fn (): array => app(SsoClient::class)->refreshToken('rotating-token'),
        );

        $this->assertSame('fresh-access', $tokens['access_token']);
    }

    public function test_an_unusable_cache_still_lets_a_login_through(): void
    {
        Cache::shouldReceive('store')->andThrow(new \RuntimeException('cache is down'));

        $this->assertTrue(app(SsoSingleFlight::class)->claimOAuthState('state-abc'));
    }

    /**
     * Answer the way Passport does: the rotating token works once, and the
     * replacement is a different value.
     */
    private function fakeSsoThatRotatesRefreshTokensOnce(): void
    {
        $spent = [];

        Http::fake([
            '*/oauth/token' => function ($request) use (&$spent) {
                $token = $request->data()['refresh_token'] ?? '';

                if (isset($spent[$token])) {
                    return Http::response(['error' => 'invalid_request', 'hint' => 'Token has been revoked'], 401);
                }

                $spent[$token] = true;

                return Http::response([
                    'access_token' => 'fresh-access',
                    'refresh_token' => 'fresh-refresh',
                    'expires_in' => 900,
                ]);
            },
            '*/api/user' => Http::response(['user' => ['id' => 'sso-1', 'email' => 'dispatch@acme.test']]),
        ]);
    }

    private function refreshRequestCount(): int
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), '/oauth/token'))->count();
    }
}

class RefreshTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = true;
}
