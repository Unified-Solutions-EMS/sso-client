<?php

namespace Unified\SsoClient\Exceptions;

use RuntimeException;

class SsoClientException extends RuntimeException
{
    public function __construct(string $message, protected ?string $oauthError = null)
    {
        parent::__construct($message);
    }

    public static function tokenExchangeFailed(string $reason, ?string $oauthError = null): static
    {
        return new static("SSO token exchange failed: {$reason}", $oauthError);
    }

    public static function userFetchFailed(string $reason): static
    {
        return new static("SSO user fetch failed: {$reason}");
    }

    public static function tokenRefreshFailed(string $reason, ?string $oauthError = null): static
    {
        return new static("SSO token refresh failed: {$reason}", $oauthError);
    }

    /**
     * The OAuth error identifier from the server's response body, when the
     * failure was an OAuth-level rejection rather than a transport problem.
     */
    public function oauthError(): ?string
    {
        return $this->oauthError;
    }

    /**
     * An `invalid_grant` names a code or refresh token the server has already
     * spent or revoked — retrying it can never succeed, and during login the
     * right move is a fresh trip through the authorize flow, not a Sentry
     * report (UNI-539).
     */
    public function isInvalidGrant(): bool
    {
        return $this->oauthError === 'invalid_grant';
    }
}
