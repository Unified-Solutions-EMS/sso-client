<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Contacts;

/**
 * Turned on but not migrated: deliveries are acknowledged and skipped.
 */
class ContactNotInstalledTest extends ContactsTestCase
{
    protected bool $runMirrorMigration = false;

    public function test_a_delivery_is_skipped_with_a_200(): void
    {
        $this->company(70);

        $this->deliver('agency_contact.created', 70, $this->contactRecord(901, 'director', 'Sam', 'Ortiz'))
            ->assertOk()
            ->assertJson(['status' => 'skipped', 'reason' => 'mirror_not_installed']);
    }
}
