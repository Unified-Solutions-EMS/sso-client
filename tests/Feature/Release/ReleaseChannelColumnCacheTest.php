<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature\Release;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\Release\ReleaseChannel;
use Unified\SsoClient\SsoUserSynchronizer;

/**
 * A long-running worker (Octane, queue) started before the migration must
 * pick the column up without a restart, and once the column is there the
 * check must cost nothing.
 */
class ReleaseChannelColumnCacheTest extends ReleaseChannelTestCase
{
    private function dropColumn(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(ReleaseChannel::COLUMN);
        });
    }

    private function addColumn(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string(ReleaseChannel::COLUMN, 16)->default('stable');
        });
    }

    private function queriesDuring(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        return $count;
    }

    public function test_once_the_column_is_found_it_is_remembered_with_no_further_query(): void
    {
        $this->assertTrue(ReleaseChannel::hasColumn());

        $this->assertSame(0, $this->queriesDuring(function (): void {
            for ($i = 0; $i < 5; $i++) {
                $this->assertTrue(ReleaseChannel::hasColumn());
            }
            $this->travel(2)->hours();
            $this->assertTrue(ReleaseChannel::hasColumn());
        }));
    }

    public function test_a_missing_column_is_asked_again_after_a_minute(): void
    {
        $this->dropColumn();
        ReleaseChannel::flushSchemaCache();

        $this->assertFalse(ReleaseChannel::hasColumn());

        $this->addColumn();

        // Within the minute the answer is remembered, without a query.
        $this->assertSame(0, $this->queriesDuring(fn () => $this->assertFalse(ReleaseChannel::hasColumn())));

        $this->travel(ReleaseChannel::MISSING_COLUMN_RECHECK_SECONDS + 1)->seconds();

        $this->assertTrue(ReleaseChannel::hasColumn());
    }

    public function test_a_worker_started_before_the_migration_sees_beta_agencies_after_it(): void
    {
        $this->dropColumn();
        ReleaseChannel::flushSchemaCache();
        $companyId = $this->company(77);

        $this->assertSame('stable', ReleaseChannel::forCompany($companyId));

        $this->addColumn();
        DB::table('companies')->where('id', $companyId)->update([ReleaseChannel::COLUMN => 'beta']);
        $this->travel(ReleaseChannel::MISSING_COLUMN_RECHECK_SECONDS + 1)->seconds();

        $this->assertSame('beta', ReleaseChannel::forCompany($companyId));
        $this->assertSame('beta', ReleaseChannel::queueForCompany($companyId));
    }

    public function test_the_sign_in_sync_starts_writing_the_column_without_a_restart(): void
    {
        $this->dropColumn();
        ReleaseChannel::flushSchemaCache();
        $signIn = fn () => app(SsoUserSynchronizer::class)->synchronize([
            'user' => ['id' => '900', 'email' => 'pat900@example.test', 'displayName' => 'Pat Medic'],
            'companies' => [['id' => 78, 'name' => 'Agency 78', 'roles' => ['User'], 'releaseChannel' => 'beta']],
            'staffRoles' => [],
        ]);

        $signIn();
        $this->addColumn();
        $this->travel(ReleaseChannel::MISSING_COLUMN_RECHECK_SECONDS + 1)->seconds();
        $signIn();

        $this->assertSame('beta', DB::table('companies')->where('sso_company_id', '78')->value(ReleaseChannel::COLUMN));
    }
}
