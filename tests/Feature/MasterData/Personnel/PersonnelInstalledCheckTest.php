<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Personnel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * The install check runs on every login and SSO-authenticated API call; once
 * it has passed it costs no schema queries (Sentry CLOUDPCR-7Y / -80 / -7V).
 */
class PersonnelInstalledCheckTest extends PersonnelTestCase
{
    private function schemaQueries(callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_a_passed_check_is_not_repeated(): void
    {
        $registry = app(MasterDataRegistry::class);

        $first = $this->schemaQueries(fn () => $this->assertTrue($registry->mirror('personnel')->isInstalled()));
        $second = $this->schemaQueries(fn () => $this->assertTrue($registry->mirror('personnel')->isInstalled()));

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second);
    }

    public function test_a_failed_check_is_asked_again(): void
    {
        $registry = app(MasterDataRegistry::class);
        $licenses = $registry->mirror('personnel')->childTables()['licenses']->table();
        Schema::rename($licenses, $licenses.'_away');

        $this->assertFalse($registry->mirror('personnel')->isInstalled());

        Schema::rename($licenses.'_away', $licenses);

        $this->assertTrue($registry->mirror('personnel')->isInstalled());
    }
}
