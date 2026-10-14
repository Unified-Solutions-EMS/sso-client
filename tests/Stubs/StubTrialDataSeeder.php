<?php

namespace Unified\SsoClient\Tests\Stubs;

use Closure;

class StubTrialDataSeeder
{
    /** @var list<array{company_id: mixed, users: array<string>, admin: string}> */
    public static array $calls = [];

    public static ?Closure $onSeed = null;

    public function seed(mixed $company, array $userSsoIds, string $adminSsoId): void
    {
        self::$calls[] = ['company_id' => $company->getKey(), 'users' => $userSsoIds, 'admin' => $adminSsoId];

        if (self::$onSeed !== null) {
            (self::$onSeed)($company);
        }
    }

    public static function reset(): void
    {
        self::$calls = [];
        self::$onSeed = null;
    }
}
