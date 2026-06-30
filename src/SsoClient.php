<?php

namespace Unified\SsoClient;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Unified\SsoClient\Exceptions\SsoClientException;

class SsoClient
{
    /**
     * Build the OAuth2 authorization URL to redirect users to the SSO login.
     */
    public function buildAuthorizeUrl(?string $state = null): array
    {
        $state = $state ?? Str::random(40);
        $codeVerifier = Str::random(128);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $url = rtrim(config('sso.base_url'), '/').'/oauth/authorize?'.http_build_query([
            'client_id' => config('sso.client_id'),
            'redirect_uri' => config('sso.redirect_uri'),
            'response_type' => 'code',
            'scope' => '*',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return [
            'url' => $url,
            'state' => $state,
            'code_verifier' => $codeVerifier,
        ];
    }

    /**
     * Exchange an authorization code for access and refresh tokens.
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        $baseUrl = rtrim(config('sso.base_url'), '/');

        try {
            $response = Http::timeout(config('sso.timeout', 10))
                ->asForm()
                ->post($baseUrl.'/oauth/token', [
                    'grant_type' => 'authorization_code',
                    'client_id' => config('sso.client_id'),
                    'client_secret' => config('sso.client_secret'),
                    'redirect_uri' => config('sso.redirect_uri'),
                    'code' => $code,
                    'code_verifier' => $codeVerifier,
                ]);
        } catch (\Throwable $e) {
            Log::error('SSO token exchange HTTP error', ['message' => $e->getMessage()]);
            throw SsoClientException::tokenExchangeFailed($e->getMessage());
        }

        if ($response->failed()) {
            Log::warning('SSO token exchange failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);
            throw SsoClientException::tokenExchangeFailed("HTTP {$response->status()}");
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in' => $data['expires_in'] ?? 3600,
            'token_type' => $data['token_type'] ?? 'Bearer',
        ];
    }

    /**
     * Refresh an expired access token using a refresh token.
     */
    public function refreshToken(string $refreshToken): array
    {
        $baseUrl = rtrim(config('sso.base_url'), '/');

        try {
            $response = Http::timeout(config('sso.timeout', 10))
                ->asForm()
                ->post($baseUrl.'/oauth/token', [
                    'grant_type' => 'refresh_token',
                    'client_id' => config('sso.client_id'),
                    'client_secret' => config('sso.client_secret'),
                    'refresh_token' => $refreshToken,
                ]);
        } catch (\Throwable $e) {
            Log::error('SSO token refresh HTTP error', ['message' => $e->getMessage()]);
            throw SsoClientException::tokenRefreshFailed($e->getMessage());
        }

        if ($response->failed()) {
            Log::warning('SSO token refresh failed', [
                'status' => $response->status(),
            ]);
            throw SsoClientException::tokenRefreshFailed("HTTP {$response->status()}");
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_in' => $data['expires_in'] ?? 3600,
        ];
    }

    /**
     * Fetch the authenticated user's profile from the SSO server.
     */
    public function fetchUser(string $accessToken): array
    {
        $baseUrl = rtrim(config('sso.base_url'), '/');

        try {
            $response = Http::timeout(config('sso.timeout', 10))
                ->withToken($accessToken)
                ->acceptJson()
                ->get($baseUrl.'/api/user');
        } catch (\Throwable $e) {
            Log::error('SSO user fetch HTTP error', ['message' => $e->getMessage()]);
            throw SsoClientException::userFetchFailed($e->getMessage());
        }

        if ($response->failed()) {
            Log::warning('SSO user fetch failed', [
                'status' => $response->status(),
            ]);
            throw SsoClientException::userFetchFailed("HTTP {$response->status()}");
        }

        return $response->json();
    }

    /**
     * Fetch a company's device-lock policy from SSO.
     *
     * Returns null when the policy can't be retrieved (network/auth error) so
     * callers can apply their own fail-open / fail-closed decision.
     *
     * @return array{mode: string, locked_app_slugs: array<int, string>, grace_until: ?string}|null
     */
    public function fetchDevicePolicy(int|string $ssoCompanyId): ?array
    {
        return $this->internalRequest('get', "/api/internal/companies/{$ssoCompanyId}/device-policy");
    }

    /**
     * Ask SSO for a single-use challenge for the device to sign.
     *
     * @return array{challenge: string, expires_at: string}|null
     */
    public function requestDeviceChallenge(?string $keyId = null): ?array
    {
        return $this->internalRequest('post', '/api/internal/device/challenge', ['key_id' => $keyId]);
    }

    /**
     * Verify a signed challenge against SSO's device registry.
     *
     * @return array{verified: bool, device?: array<string, mixed>}|null
     */
    public function verifyDevice(string $keyId, string $challenge, string $signature): ?array
    {
        return $this->internalRequest('post', '/api/internal/device/verify', [
            'key_id' => $keyId,
            'challenge' => $challenge,
            'signature' => $signature,
        ]);
    }

    /**
     * Register a device's public key with SSO (code or admin mode).
     *
     * @param  array<string, mixed>  $payload
     * @return array{device: array<string, mixed>}|null
     */
    public function registerDevice(array $payload): ?array
    {
        return $this->internalRequest('post', '/api/internal/device/register', $payload);
    }

    /**
     * Call an SSO internal endpoint with the shared CORE_APP_API_KEY. Returns
     * the decoded body on success, or null on any failure (logged).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function internalRequest(string $method, string $path, array $data = []): ?array
    {
        $baseUrl = rtrim(config('sso.base_url'), '/');
        $apiKey = config('sso.core_api_key') ?? config('app.core_api_key');

        if (empty($apiKey)) {
            Log::warning('SSO device call skipped: CORE_APP_API_KEY not configured');

            return null;
        }

        try {
            $request = Http::timeout(config('sso.timeout', 10))
                ->withHeaders(['X-API-KEY' => $apiKey])
                ->acceptJson();

            $response = $method === 'get'
                ? $request->get($baseUrl.$path, $data)
                : $request->post($baseUrl.$path, $data);
        } catch (\Throwable $e) {
            Log::warning('SSO device call HTTP error', ['path' => $path, 'message' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('SSO device call failed', ['path' => $path, 'status' => $response->status()]);

            return null;
        }

        return $response->json();
    }

    /**
     * Build the SSO logout redirect URL.
     */
    public function buildLogoutUrl(?string $redirectUri = null): string
    {
        $baseUrl = rtrim(config('sso.base_url'), '/');
        $params = [];

        if ($redirectUri) {
            $params['redirect_uri'] = $redirectUri;
        }

        return $baseUrl.'/auth/logout'.($params ? '?'.http_build_query($params) : '');
    }
}
