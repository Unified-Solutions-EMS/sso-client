<?php

namespace Unified\SsoClient\Tests\Stubs;

use Closure;
use Unified\SsoClient\Contracts\ReceivesTrialContext;
use Unified\SsoClient\Trial\TrialContext;

class StubTrialDataSeeder implements ReceivesTrialContext
{
    /** @var list<array{company_id: mixed, users: array<string>, admin: string, context: ?TrialContext}> */
    public static array $calls = [];

    /** @var list<?TrialContext> every context handed over, in order */
    public static array $contexts = [];

    public static ?Closure $onSeed = null;

    private ?TrialContext $context = null;

    public function withTrialContext(?TrialContext $context): void
    {
        $this->context = $context;
        self::$contexts[] = $context;
    }

    public function seed(mixed $company, array $userSsoIds, string $adminSsoId): void
    {
        self::$calls[] = ['company_id' => $company->getKey(), 'users' => $userSsoIds, 'admin' => $adminSsoId, 'context' => $this->context];

        if (self::$onSeed !== null) {
            (self::$onSeed)($company);
        }
    }

    public static function reset(): void
    {
        self::$calls = [];
        self::$contexts = [];
        self::$onSeed = null;
    }
}
