<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Contacts;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\MasterData\Contacts\ContactCatalog;
use Unified\SsoClient\Tests\TestCase;

/**
 * The package-owned `sso_agency_contacts` table, created by the published
 * mirror migration (StandardContactProjection).
 */
abstract class ContactsTestCase extends TestCase
{
    protected bool $runMirrorMigration = true;

    protected function setUp(): void
    {
        parent::setUp();

        ContactCatalog::flushSchemaCache();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.app_slug', 'billing');
        $app['config']->set('sso.webhook_secret', 'whsec');
        $app['config']->set('app.core_api_key', 'core-key');
        $app['config']->set('sso.master_data.agency_contacts', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        if ($this->runMirrorMigration) {
            $this->runPublishedMirrorMigration();
        }
    }

    protected function runPublishedMirrorMigration(): void
    {
        (require __DIR__.'/../../../../database/master-data/2026_10_14_100000_create_or_extend_agency_contacts_mirror.php')->up();
    }

    protected function company(int $ssoCompanyId, string $name = 'Agency'): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => $name.' '.$ssoCompanyId,
            'sso_company_id' => (string) $ssoCompanyId,
        ]);
    }

    /**
     * A local contact the agency had before the cutover.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function localContact(int $companyId, string $type, string $firstName, string $lastName, array $attributes = []): int
    {
        return (int) DB::table('sso_agency_contacts')->insertGetId([
            'company_id' => $companyId,
            'name' => "{$firstName} {$lastName}",
            'type' => $type,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'created_at' => now(),
            'updated_at' => now(),
        ] + $attributes);
    }

    protected function mirrored(int $ssoId): ?object
    {
        return DB::table('sso_agency_contacts')->where('sso_agency_contact_id', $ssoId)->first();
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
     * @param  array<string, mixed>  $contact
     */
    protected function deliver(string $event, int $ssoCompanyId, array $contact): TestResponse
    {
        return $this->postWebhook($event, [
            'company' => ['id' => $ssoCompanyId, 'division_label' => 'division'],
            'agency_contact' => $contact,
            'updated_at' => $contact['updated_at'] ?? null,
        ]);
    }

    /**
     * SSO's AgencyContact::toContractArray().
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function contactRecord(int $id, string $type, string $firstName, string $lastName, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'type' => $type,
            'is_primary' => false,
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => null,
            'title' => null,
            'phones' => [],
            'emails' => [],
            'address' => [
                'street' => null,
                'street2' => null,
                'city_gnis' => null,
                'city_name' => null,
                'state' => null,
                'zip' => null,
                'county' => null,
                'country' => null,
            ],
            'website' => null,
            'md_degree' => null,
            'md_board_certifications' => [],
            'md_compensation' => null,
            'md_board_certified' => null,
            'sso_user_id' => null,
            'is_active' => true,
            'sort_order' => 1,
            'updated_at' => '2026-10-14T12:00:00+00:00',
        ], $overrides);
    }
}
