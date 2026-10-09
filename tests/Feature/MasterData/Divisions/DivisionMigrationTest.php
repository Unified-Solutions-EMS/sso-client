<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Divisions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\MasterDataRegistry;

class DivisionMigrationTest extends DivisionsTestCase
{
    protected bool $runMirrorMigration = false;

    public function test_on_crews_tables_it_adds_the_link_columns_and_keeps_every_row_active(): void
    {
        $companyId = $this->company(70);
        $alpha = (int) DB::table('divisions')->insertGetId(['company_id' => $companyId, 'name' => 'Alpha Shift']);
        $userId = $this->user(9001, $companyId, $alpha);

        $mirror = $this->app->make(MasterDataRegistry::class)->mirror('divisions');
        $this->assertFalse($mirror->isInstalled());

        $this->runPublishedMirrorMigration();
        $this->runPublishedMirrorMigration();

        $this->assertTrue($mirror->isInstalled());
        $row = DB::table('divisions')->find($alpha);
        $this->assertSame('Alpha Shift', $row->name);
        $this->assertTrue((bool) $row->is_active);
        $this->assertNull($row->sso_division_id);
        $this->assertFalse((bool) $row->sso_link_pending);
        $this->assertSame($alpha, $this->divisionOf($userId, $companyId));
    }

    public function test_on_hr_without_divisions_it_creates_the_table_and_the_pivot_column(): void
    {
        Schema::drop('locations');
        Schema::drop('divisions');
        Schema::table('company_user', fn ($table) => $table->dropColumn('division_id'));

        $this->runPublishedMirrorMigration();

        $this->assertTrue(Schema::hasColumns('divisions', ['company_id', 'name', 'sso_division_id', 'code', 'is_active', 'sort_order', 'sso_updated_at', 'sso_link_pending']));
        $this->assertTrue(Schema::hasColumn('company_user', 'division_id'));
        $this->assertTrue($this->app->make(MasterDataRegistry::class)->mirror('divisions')->isInstalled());
    }

    public function test_the_mirror_commands_point_at_the_divisions_publish_tag(): void
    {
        $this->artisan('sso:resync-master-data', ['entity' => 'divisions'])
            ->expectsOutputToContain('vendor:publish --tag=sso-master-data-divisions')
            ->assertFailed();
    }

    public function test_webhooks_ack_until_the_migration_has_run(): void
    {
        $this->company(70);

        $this->postWebhook('division.created', ['company' => ['id' => 70], 'division' => $this->divisionRecord(501, 'North')])
            ->assertOk()
            ->assertJson(['status' => 'skipped', 'reason' => 'mirror_not_installed']);
    }
}
