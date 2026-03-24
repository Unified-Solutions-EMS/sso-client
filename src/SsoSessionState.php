<?php

namespace Unified\SsoClient;

use Illuminate\Support\Facades\Session;

class SsoSessionState
{
    public const KEY_ACCESS_TOKEN = 'sso_access_token';

    public const KEY_REFRESH_TOKEN = 'sso_refresh_token';

    public const KEY_TOKEN_EXPIRES_AT = 'sso_token_expires_at';

    public const KEY_SSO_USER_ID = 'sso_user_id';

    public const KEY_SELECTED_COMPANY_ID = 'selected_company_id';

    public const KEY_OAUTH_STATE = 'sso_oauth_state';

    public const KEY_CODE_VERIFIER = 'sso_code_verifier';

    public const KEY_INTENDED_URL = 'sso_intended_url';

    public const KEY_TOKEN_LAST_VALIDATED_AT = 'sso_token_last_validated_at';

    public function storeTokens(string $accessToken, ?string $refreshToken, int $expiresIn): void
    {
        Session::put(self::KEY_ACCESS_TOKEN, $accessToken);
        Session::put(self::KEY_TOKEN_EXPIRES_AT, now()->addSeconds($expiresIn)->timestamp);

        if ($refreshToken) {
            Session::put(self::KEY_REFRESH_TOKEN, $refreshToken);
        }
    }

    public function getAccessToken(): ?string
    {
        return Session::get(self::KEY_ACCESS_TOKEN);
    }

    public function getRefreshToken(): ?string
    {
        return Session::get(self::KEY_REFRESH_TOKEN);
    }

    public function isTokenExpired(): bool
    {
        $expiresAt = Session::get(self::KEY_TOKEN_EXPIRES_AT);

        if (! $expiresAt) {
            return true;
        }

        // Consider expired 60 seconds early to avoid edge cases
        return now()->timestamp >= ($expiresAt - 60);
    }

    public function storeSsoUserId(int|string $userId): void
    {
        Session::put(self::KEY_SSO_USER_ID, $userId);
    }

    public function getSsoUserId(): int|string|null
    {
        return Session::get(self::KEY_SSO_USER_ID);
    }

    public function storeSelectedCompanyId(int|string $companyId): void
    {
        Session::put(self::KEY_SELECTED_COMPANY_ID, $companyId);
    }

    public function getSelectedCompanyId(): int|string|null
    {
        return Session::get(self::KEY_SELECTED_COMPANY_ID);
    }

    public function storeOAuthState(string $state, string $codeVerifier): void
    {
        Session::put(self::KEY_OAUTH_STATE, $state);
        Session::put(self::KEY_CODE_VERIFIER, $codeVerifier);
    }

    public function getOAuthState(): ?string
    {
        return Session::get(self::KEY_OAUTH_STATE);
    }

    public function getCodeVerifier(): ?string
    {
        return Session::get(self::KEY_CODE_VERIFIER);
    }

    public function storeIntendedUrl(string $url): void
    {
        Session::put(self::KEY_INTENDED_URL, $url);
    }

    public function pullIntendedUrl(?string $default = '/'): string
    {
        return Session::pull(self::KEY_INTENDED_URL, $default);
    }

    public function markTokenValidated(): void
    {
        Session::put(self::KEY_TOKEN_LAST_VALIDATED_AT, now()->timestamp);
    }

    public function needsServerValidation(int $intervalSeconds = 120): bool
    {
        $lastValidated = Session::get(self::KEY_TOKEN_LAST_VALIDATED_AT);

        if (! $lastValidated) {
            return true;
        }

        return now()->timestamp >= ($lastValidated + $intervalSeconds);
    }

    public function forget(): void
    {
        Session::forget([
            self::KEY_ACCESS_TOKEN,
            self::KEY_REFRESH_TOKEN,
            self::KEY_TOKEN_EXPIRES_AT,
            self::KEY_SSO_USER_ID,
            self::KEY_SELECTED_COMPANY_ID,
            self::KEY_OAUTH_STATE,
            self::KEY_CODE_VERIFIER,
            self::KEY_INTENDED_URL,
            self::KEY_TOKEN_LAST_VALIDATED_AT,
        ]);
    }
}
