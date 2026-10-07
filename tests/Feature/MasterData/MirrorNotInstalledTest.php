<?php

namespace Unified\SsoClient\Tests\Feature\MasterData;

use Illuminate\Support\Facades\DB;

class MirrorNotInstalledTest extends MasterDataTestCase
{
    protected bool $runMirrorMigration = false;

    public function test_webhooks_are_skipped_until_the_mirror_migration_runs(): void
    {
        $this->company(70);

        $this->postWebhook('qualification.created', [
            'company' => ['id' => 70],
            'qualification' => $this->qualificationRecord(501, 'Paramedic'),
        ])->assertOk()->assertJson(['status' => 'skipped', 'reason' => 'mirror_not_installed']);

        $this->assertSame(0, DB::table('qualifications')->count());
    }

    public function test_resync_refuses_until_the_mirror_migration_runs(): void
    {
        $this->artisan('sso:resync-master-data', ['entity' => 'qualifications'])
            ->expectsOutputToContain('vendor:publish --tag=sso-master-data')
            ->assertFailed();
    }

    public function test_push_refuses_until_the_mirror_migration_runs(): void
    {
        $this->artisan('sso:push-master-data', ['entity' => 'qualifications'])
            ->expectsOutputToContain('vendor:publish --tag=sso-master-data')
            ->assertFailed();
    }

    public function test_the_published_migration_is_safe_to_run_twice(): void
    {
        $this->runPublishedMirrorMigration();
        $this->runPublishedMirrorMigration();

        $this->company(70);

        $this->postWebhook('qualification.created', [
            'company' => ['id' => 70],
            'qualification' => $this->qualificationRecord(501, 'Paramedic'),
        ])->assertOk()->assertJson(['status' => 'ok', 'result' => 'created']);
    }
}
