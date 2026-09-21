<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Testing\TestResponse;
use Mockery;
use Unified\SsoClient\Exceptions\SsoClientException;
use Unified\SsoClient\SsoClient;
use Unified\SsoClient\SsoSessionState;
use Unified\SsoClient\Tests\TestCase;

/**
 * UNI-539: `invalid_grant` on the callback is the tail end of a duplicate
 * callback or an aged-out code, not an application fault. Reporting it fed
 * Sentry a daily stream of noise (CLOUDPCR-6E, CREW-SCHEDULING-10) for logins
 * that recovered on their own — the authorize flow re-issues instantly from
 * SSO's live session. So: no report, redirect back through the flow, but the
 * loop breaker still counts so a deterministic invalid_grant loop breaks.
 */
class SsoCallbackInvalidGrantTest extends TestCase
{
    /** @var list<\Throwable> */
    private array $reported = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn () => 'login')->name('login');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->andReturnUsing(function ($e) {
            $this->reported[] = $e;
        });
        $handler->shouldReceive('shouldReport')->andReturn(true);
        $handler->shouldReceive('render')->andReturnUsing(fn ($request, $e) => throw $e);
        $this->app->instance(ExceptionHandler::class, $handler);

        $client = Mockery::mock(SsoClient::class);
        $client->shouldReceive('exchangeCode')->andThrow(SsoClientException::tokenExchangeFailed(
            'HTTP 400 invalid_grant: Authorization code has been revoked',
            'invalid_grant',
        ));
        $this->app->instance(SsoClient::class, $client);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_an_invalid_grant_redirects_back_through_the_flow_without_reporting(): void
    {
        $response = $this->attemptWithFreshState([], 'state-one');

        $response->assertRedirect('/login');
        $this->assertCount(0, $this->reported, 'invalid_grant must not reach the exception handler.');
    }

    public function test_an_invalid_grant_still_counts_toward_the_loop_breaker(): void
    {
        $session = [];

        $first = $this->attemptWithFreshState($session, 'state-one');
        $first->assertRedirect('/login');
        $first->assertSessionHas(SsoSessionState::KEY_CALLBACK_FAILURES, 1);
        $session = array_merge($session, $first->getSession()->all());

        $second = $this->attemptWithFreshState($session, 'state-two');
        $second->assertRedirect('/login');
        $second->assertSessionHas(SsoSessionState::KEY_CALLBACK_FAILURES, 2);
        $session = array_merge($session, $second->getSession()->all());

        $third = $this->attemptWithFreshState($session, 'state-three');

        $third->assertStatus(500);
        $third->assertSee('We could not sign you in');
        $this->assertCount(0, $this->reported, 'Even a tripped breaker must not report invalid_grant.');
    }

    private function attemptWithFreshState(array $session, string $state): TestResponse
    {
        return $this->withSession(array_merge($session, [
            SsoSessionState::KEY_OAUTH_STATE => $state,
            SsoSessionState::KEY_CODE_VERIFIER => 'verifier-'.$state,
        ]))->get('/auth/sso/callback?state='.$state.'&code=auth-code');
    }
}
