<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Unified\SsoClient\Exceptions\SsoClientException;
use Unified\SsoClient\SsoClient;
use Unified\SsoClient\Tests\TestCase;

/**
 * UNI-438: every one of the eleven production events said only
 * "SSO token exchange failed: HTTP 400". Identifying which of the four
 * different 400s Passport can return meant matching the response body's byte
 * length against each error payload. The reason string carries the answer now.
 */
class SsoTokenExchangeErrorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.base_url', 'https://sso.example.test');
        $app['config']->set('sso.client_id', 'client-1');
        $app['config']->set('sso.client_secret', 'secret');
        $app['config']->set('sso.redirect_uri', 'https://app.example.test/auth/sso/callback');
    }

    public function test_the_exchange_failure_names_the_oauth_error_and_hint(): void
    {
        // Byte-for-byte the payload league/oauth2-server returns for a code
        // that has already been redeemed.
        Http::fake([
            '*/oauth/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'The provided authorization grant (e.g., authorization code, resource owner credentials) or refresh token is invalid, expired, revoked, does not match the redirection URI used in the authorization request, or was issued to another client.',
                'hint' => 'Authorization code has been revoked',
            ], 400),
        ]);

        $this->expectException(SsoClientException::class);
        $this->expectExceptionMessage('SSO token exchange failed: HTTP 400 invalid_grant: Authorization code has been revoked');

        app(SsoClient::class)->exchangeCode('spent-code', 'verifier');
    }

    public function test_a_non_oauth_error_body_still_reports_the_status(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response('<html>502 Bad Gateway</html>', 502),
        ]);

        $this->expectException(SsoClientException::class);
        $this->expectExceptionMessage('SSO token exchange failed: HTTP 502');

        app(SsoClient::class)->exchangeCode('code', 'verifier');
    }
}
