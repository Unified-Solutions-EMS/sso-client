<?php

declare(strict_types=1);

namespace Unified\SsoClient\Agency;

use Illuminate\Support\Facades\Schema;

/**
 * Whether a companies table has run the `sso-company-profile` migration.
 * Memoized per table because the login sync asks on every sign-in; shared by
 * the login sync and the webhook so one flush resets both.
 */
final class ProfileColumns
{
    public const PROFILE = 'sso_profile';

    public const UPDATED_AT = 'sso_profile_updated_at';

    /**
     * @var array<string, bool>
     */
    private static array $supported = [];

    public static function supported(string $table): bool
    {
        return self::$supported[$table] ??= Schema::hasColumns($table, [self::PROFILE, self::UPDATED_AT]);
    }

    public static function flush(): void
    {
        self::$supported = [];
    }
}
