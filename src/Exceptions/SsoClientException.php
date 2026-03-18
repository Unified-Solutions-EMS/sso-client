<?php

namespace Unified\SsoClient\Exceptions;

use RuntimeException;

class SsoClientException extends RuntimeException
{
    public static function tokenExchangeFailed(string $reason): static
    {
        return new static("SSO token exchange failed: {$reason}");
    }

    public static function userFetchFailed(string $reason): static
    {
        return new static("SSO user fetch failed: {$reason}");
    }

    public static function tokenRefreshFailed(string $reason): static
    {
        return new static("SSO token refresh failed: {$reason}");
    }
}
