<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Divisions;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\MasterData\Divisions\DivisionCatalog;
use Unified\SsoClient\SsoUserSynchronizer;
use Unified\SsoClient\Tests\TestCase;

/**
 * Crew-Scheduling's shape by default: an existing `divisions(company_id, name)`
 * table and `company_user.division_id`. Set $crewShape = false for HR's
 * (neither exists; the published migration creates both).
 */
abstract class DivisionsTestCase extends TestCase
{
    protected bool $crewShape = true;

    protected bool $runMirrorMigration = true;

    protected function setUp(): void
    {
        parent::setUp();

        SsoUserSynchronizer::flushSchemaCache();
        DivisionCatalog::flushSchemaCache();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.app_slug', $this->crewShape ? 'crew-scheduling' : 'hr');
        $app['config']->set('sso.webhook_secret', 'whsec');
        $app['config']->set('app.core_api_key', 'core-key');
        $app['config']->set('sso.master_data.divisions', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        if ($this->crewShape) {
            // Crew's 2024_11_05 tables: no active flag, no SSO link.
            Schema::create('divisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('name');
                $table->timestamps();
            });

            Schema::table('company_user', function (Blueprint $table) {
                $table->unsignedBigInteger('division_id')->nullable();
            });

            // A child that cascades with its division, like Crew's locations.
            Schema::create('locations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('division_id')->constrained('divisions')->cascadeOnDelete();
                $table->string('name');
            });
        }

        if ($this->runMirrorMigration) {
            $this->runPublishedMirrorMigration();
        }
    }

    protected function runPublishedMirrorMigration(): void
    {
        $migration = require __DIR__.'/../../../../database/master-data/2026_10_09_000000_create_or_extend_divisions_mirror.php';
        $migration->up();
    }

    protected function company(int $ssoCompanyId, string $name = 'Agency'): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => $name.' '.$ssoCompanyId,
            'sso_company_id' => (string) $ssoCompanyId,
        ]);
    }

    protected function user(int $ssoUserId, ?int $companyId = null, ?int $divisionId = null): int
    {
        $id = (int) DB::table('users')->insertGetId([
            'name' => "User {$ssoUserId}",
            'email' => "user{$ssoUserId}@example.com",
            'sso_id' => (string) $ssoUserId,
        ]);

        if ($companyId !== null) {
            $this->member($id, $companyId, $divisionId);
        }

        return $id;
    }

    protected function member(int $userId, int $companyId, ?int $divisionId = null): void
    {
        DB::table('company_user')->insert(['company_id' => $companyId, 'user_id' => $userId, 'division_id' => $divisionId]);
    }

    protected function localDivision(int $companyId, string $name, ?int $ssoId = null, bool $pending = false): int
    {
        return (int) DB::table('divisions')->insertGetId(array_filter([
            'company_id' => $companyId,
            'name' => $name,
            'sso_division_id' => $ssoId,
            'sso_link_pending' => $pending ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ], fn ($value): bool => $value !== null));
    }

    protected function divisionOf(int $userId, int $companyId): ?int
    {
        $id = DB::table('company_user')->where('user_id', $userId)->where('company_id', $companyId)->value('division_id');

        return $id === null ? null : (int) $id;
    }

    protected function mirrored(int $companyId, int $ssoId): ?object
    {
        return DB::table('divisions')->where('company_id', $companyId)->where('sso_division_id', $ssoId)->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postWebhook(string $event, array $payload): TestResponse
    {
        $body = json_encode(['event' => $event, 'timestamp' => now()->toIso8601String()] + $payload);

        return $this->call('POST', '/api/sso/provision', [], [], [], [
            'HTTP_X-SSO-Signature' => hash_hmac('sha256', $body, 'whsec'),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    /**
     * SSO's Division::toContractArray().
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function divisionRecord(int $id, string $name, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => $name,
            'code' => null,
            'is_active' => true,
            'sort_order' => 1,
            'updated_at' => '2026-10-09T12:00:00+00:00',
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $divisions
     * @param  list<array{user_id: int, division_id: int}>  $assignments
     * @return array<string, mixed>
     */
    protected function snapshot(int $ssoCompanyId, array $divisions, array $assignments = []): array
    {
        return [
            'company' => ['id' => $ssoCompanyId, 'division_label' => 'division'],
            'divisions' => $divisions,
            'assignments' => $assignments,
        ];
    }
}
