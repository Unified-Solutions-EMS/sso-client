<?php

namespace Unified\SsoClient\Tests\Stubs;

use Closure;

class StubTrialDataPurger
{
    /** @var list<array{company_id: mixed, admin: string}> */
    public static array $calls = [];

    public static ?Closure $onPurge = null;

    public function purge(mixed $company, string $adminSsoId): void
    {
        self::$calls[] = ['company_id' => $company->getKey(), 'admin' => $adminSsoId];

        if (self::$onPurge !== null) {
            (self::$onPurge)($company);
        }
    }

    public static function reset(): void
    {
        self::$calls = [];
        self::$onPurge = null;
    }
}
