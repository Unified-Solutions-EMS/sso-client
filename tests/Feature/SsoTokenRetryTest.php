<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\Exceptions\SsoClientException;
use Unified\SsoClient\SsoClient;
use Unified\SsoClient\Tests\TestCase;

/**
 * UNI-539: a single SSO blip during the token exchange used to burn the
 * one-shot authorization code and force a full re-authorize. Transport errors
 * and 5xx responses mean Passport never processed the grant, so the same
 * credential is safe to present again. A 4xx means it DID process the grant
 * and refused — retrying one would double-redeem, so 4xx never retries.
 */
class SsoTokenRetryTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.base_url', 'https://sso.example.test');
        $app['config']->set('sso.client_id', 'client-1');
        $app['config']->set('sso.client_secret', 'secret');
        $app['config']->set('sso.redirect_uri', 'https://app.example.test/auth/sso/callback');
    }

    public function test_the_exchange_retries_a_5xx_and_succeeds(): void
    {
        Http::fakeSequence()
            ->pushStatus(502)
            ->push(['access_token' => 'token-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600]);

        $tokens = app(SsoClient::class)->exchangeCode('auth-code', 'verifier');

        $this->assertSame('token-1', $tokens['access_token']);
        Http::assertSentCount(2);
    }

    public function test_the_exchange_retries_a_connection_error_and_succeeds(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if (++$calls === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(['access_token' => 'token-1', 'expires_in' => 3600]);
        });

        $tokens = app(SsoClient::class)->exchangeCode('auth-code', 'verifier');

        $this->assertSame('token-1', $tokens['access_token']);
        $this->assertSame(2, $calls);
    }

    public function test_the_exchange_never_retries_a_4xx(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response([
                'error' => 'invalid_grant',
                'hint' => 'Authorization code has been revoked',
            ], 400),
        ]);

        try {
            app(SsoClient::class)->exchangeCode('spent-code', 'verifier');
            $this->fail('Expected an SsoClientException.');
        } catch (SsoClientException $e) {
            $this->assertTrue($e->isInvalidGrant());
            $this->assertSame('invalid_grant', $e->oauthError());
        }

        // Retrying a 400 would present the same one-shot code a second time —
        // the exact double-redeem this package exists to prevent.
        Http::assertSentCount(1);
    }

    public function test_an_exhausted_retry_still_names_the_status(): void
    {
        Http::fake(['*/oauth/token' => Http::response('<html>502 Bad Gateway</html>', 502)]);

        $this->expectException(SsoClientException::class);
        $this->expectExceptionMessage('SSO token exchange failed: HTTP 502');

        try {
            app(SsoClient::class)->exchangeCode('auth-code', 'verifier');
        } finally {
            Http::assertSentCount(2);
        }
    }

    public function test_the_refresh_retries_a_5xx_and_succeeds(): void
    {
        Http::fakeSequence()
            ->pushStatus(500)
            ->push(['access_token' => 'token-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600]);

        $tokens = app(SsoClient::class)->refreshToken('refresh-1');

        $this->assertSame('token-2', $tokens['access_token']);
        $this->assertSame('refresh-2', $tokens['refresh_token']);
        Http::assertSentCount(2);
    }

    public function test_the_refresh_never_retries_a_4xx(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response([
                'error' => 'invalid_grant',
                'hint' => 'Token has been revoked',
            ], 400),
        ]);

        try {
            app(SsoClient::class)->refreshToken('revoked-refresh');
            $this->fail('Expected an SsoClientException.');
        } catch (SsoClientException $e) {
            $this->assertTrue($e->isInvalidGrant());
        }

        Http::assertSentCount(1);
    }
}
