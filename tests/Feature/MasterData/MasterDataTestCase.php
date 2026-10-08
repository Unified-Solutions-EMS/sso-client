<?php

namespace Unified\SsoClient\Tests\Feature\MasterData;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\MasterData\Qualifications\QualificationCatalog;
use Unified\SsoClient\SsoUserSynchronizer;
use Unified\SsoClient\Tests\TestCase;

abstract class MasterDataTestCase extends TestCase
{
    protected bool $runMirrorMigration = true;

    protected function setUp(): void
    {
        parent::setUp();

        SsoUserSynchronizer::flushSchemaCache();
        QualificationCatalog::flushSchemaCache();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.app_slug', 'crew-scheduling');
        $app['config']->set('sso.webhook_secret', 'whsec');
        $app['config']->set('app.core_api_key', 'core-key');
        $app['config']->set('sso.master_data.qualifications', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        // The shape every app has carried since its 2024_08_29_162700 migration.
        Schema::create('qualifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('company_user_qualifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('qualification_id');
            $table->timestamps();
        });

        if ($this->runMirrorMigration) {
            $this->runPublishedMirrorMigration();
        }
    }

    protected function runPublishedMirrorMigration(): void
    {
        $migration = require __DIR__.'/../../../database/master-data/2026_10_06_000000_add_sso_mirror_columns_to_qualifications_table.php';
        $migration->up();
    }

    protected function company(int $ssoCompanyId, string $name = 'Agency'): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => $name.' '.$ssoCompanyId,
            'sso_company_id' => (string) $ssoCompanyId,
        ]);
    }

    protected function user(int $ssoUserId): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => "User {$ssoUserId}",
            'email' => "user{$ssoUserId}@example.com",
            'sso_id' => (string) $ssoUserId,
        ]);
    }

    protected function localQualification(int $companyId, string $name, ?int $ssoId = null): int
    {
        return (int) DB::table('qualifications')->insertGetId(array_filter([
            'company_id' => $companyId,
            'name' => $name,
            'sso_qualification_id' => $ssoId,
            'created_at' => now(),
            'updated_at' => now(),
        ], fn ($value): bool => $value !== null));
    }

    protected function assign(int $userId, int $companyId, int $qualificationId): void
    {
        DB::table('company_user_qualifications')->insert([
            'user_id' => $userId,
            'company_id' => $companyId,
            'qualification_id' => $qualificationId,
        ]);
    }

    /**
     * @return list<int> local qualification ids the user holds in the company
     */
    protected function assignedIds(int $userId, int $companyId): array
    {
        return DB::table('company_user_qualifications')
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->orderBy('qualification_id')
            ->pluck('qualification_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    protected function mirrored(int $companyId, int $ssoId): ?object
    {
        return DB::table('qualifications')
            ->where('company_id', $companyId)
            ->where('sso_qualification_id', $ssoId)
            ->first();
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function qualificationRecord(int $id, string $name, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => $name,
            'description' => null,
            'applies_to' => [],
            'is_active' => true,
            'updated_at' => '2026-10-06T12:00:00Z',
        ], $overrides);
    }
}
