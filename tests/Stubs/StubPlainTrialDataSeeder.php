<?php

namespace Unified\SsoClient\Tests\Stubs;

/**
 * An app seeder that has not opted into the trial context.
 */
class StubPlainTrialDataSeeder
{
    /** @var list<array{company_id: mixed, users: array<string>, admin: string}> */
    public static array $calls = [];

    public function seed(mixed $company, array $userSsoIds, string $adminSsoId): void
    {
        self::$calls[] = ['company_id' => $company->getKey(), 'users' => $userSsoIds, 'admin' => $adminSsoId];
    }
}
