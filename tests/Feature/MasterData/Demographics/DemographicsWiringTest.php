<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Demographics;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\Agency\AgencyMirror;
use Unified\SsoClient\MasterData\Contacts\ContactMirror;
use Unified\SsoClient\MasterData\Devices\DeviceMirror;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\StateConfigurations\StateConfigurationMirror;
use Unified\SsoClient\Settings\SettingEntity;
use Unified\SsoClient\Trial\TrialMasterDataSync;

/**
 * What ties the four demographics entities into the package: the registry
 * and its flags, the webhook routing, the "Edit in Settings" paths, the
 * migration on an existing table, and the trial seed sync.
 */
class DemographicsWiringTest extends DemographicsTestCase
{
    protected string $entity = 'contacts';

    protected bool $runMirrorMigration = false;

    protected function createAppTables(): void
    {
        // An app that already keeps contacts in the standard table's shape.
        Schema::create('sso_agency_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('type')->nullable();
            $table->string('app_notes')->nullable();
            $table->timestamps();
        });
    }

    public function test_the_registry_knows_the_four_entities_off_by_default(): void
    {
        $registry = app(MasterDataRegistry::class);

        foreach ([
            'agency' => AgencyMirror::class,
            'contacts' => ContactMirror::class,
            'devices' => DeviceMirror::class,
            'state_configurations' => StateConfigurationMirror::class,
        ] as $entity => $class) {
            $this->assertInstanceOf($class, $registry->mirror($entity));
            $this->assertSame($entity, $registry->mirror($entity)->entity());
        }

        foreach (['agency', 'devices', 'state_configurations'] as $entity) {
            $this->assertFalse($registry->enabled($entity), "{$entity} should default to off");
        }

        $this->assertSame('agency', $registry->entityForEvent('agency.updated'));
        $this->assertSame('contacts', $registry->entityForEvent('contact.batch_updated'));
        $this->assertSame('devices', $registry->entityForEvent('device.deactivated'));
        $this->assertSame('state_configurations', $registry->entityForEvent('state_configuration.batch_updated'));
        $this->assertNull($registry->entityForEvent('device.revoked'), 'Session device revocation is not the devices list.');
    }

    public function test_the_migration_extends_an_existing_table_without_touching_its_rows_and_reruns_cleanly(): void
    {
        $companyId = $this->company(70);
        $row = (int) DB::table('sso_agency_contacts')->insertGetId(['company_id' => $companyId, 'last_name' => 'Smith', 'app_notes' => 'kept']);

        $this->migrate('contacts');
        $this->migrate('contacts');

        $this->assertTrue(Schema::hasColumns('sso_agency_contacts', ['sso_contact_id', 'is_active', 'sort_order', 'sso_updated_at', 'sso_link_pending']));
        $this->assertTrue(Schema::hasIndex('sso_agency_contacts', 'sso_agency_contacts_sso_contact_id_unique'));
        $this->assertTrue((bool) DB::table('sso_agency_contacts')->where('id', $row)->value('is_active'));
        $this->assertSame('kept', DB::table('sso_agency_contacts')->where('id', $row)->value('app_notes'));
        $this->assertTrue(app(MasterDataRegistry::class)->mirror('contacts')->isInstalled());

        (require __DIR__.'/../../../../database/master-data/2026_10_14_100100_create_or_extend_contacts_mirror.php')->down();

        $this->assertFalse(Schema::hasColumn('sso_agency_contacts', 'sso_contact_id'));
        $this->assertTrue(Schema::hasColumn('sso_agency_contacts', 'is_active'), 'The list columns stay.');
        $this->assertSame(1, DB::table('sso_agency_contacts')->count());
    }

    public function test_an_unmigrated_mirror_acknowledges_its_webhooks(): void
    {
        $this->company(70);

        $this->deliver('contact.created', 70, ['id' => 501, 'first_name' => 'Jane', 'last_name' => 'Smith', 'type' => 'medical_director'])
            ->assertOk()->assertJson(['status' => 'skipped', 'reason' => 'mirror_not_installed']);
    }

    public function test_every_entity_names_its_settings_tab(): void
    {
        $this->assertSame('/system/agency', SettingEntity::Agency->settingsPath());
        $this->assertSame('/system/contacts', SettingEntity::Contacts->settingsPath());
        $this->assertSame('/system/devices', SettingEntity::Devices->settingsPath());
        $this->assertSame('/system/state-configuration', SettingEntity::StateConfigurations->settingsPath());
        $this->assertSame('https://sso.test/system/devices', SettingEntity::from('devices')->settingsUrl());
        $this->assertSame('/system/locations', SettingEntity::Station->settingsPath());
    }

    public function test_the_trial_seed_sync_resyncs_an_enabled_demographics_mirror_and_checks_its_count(): void
    {
        $this->migrate('contacts');
        $companyId = $this->company(70);

        Http::fake(['sso.test/api/internal/companies/70/contacts' => Http::response(['company' => ['id' => 70], 'contacts' => [
            ['id' => 501, 'first_name' => 'Jane', 'last_name' => 'Smith', 'type' => 'medical_director', 'is_active' => true, 'updated_at' => '2026-10-14T12:00:00+00:00'],
            ['id' => 502, 'first_name' => 'Ann', 'last_name' => 'Lee', 'type' => 'administrator', 'is_active' => true, 'updated_at' => '2026-10-14T12:00:00+00:00'],
        ]])]);

        $this->assertSame(['contacts' => 2], app(TrialMasterDataSync::class)->sync($companyId, 70, ['ready' => true, 'counts' => ['contacts' => 2]]));
    }
}
