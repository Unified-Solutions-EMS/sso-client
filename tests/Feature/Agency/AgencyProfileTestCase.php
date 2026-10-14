<?php

namespace Unified\SsoClient\Tests\Feature\Agency;

use Illuminate\Testing\TestResponse;
use Unified\SsoClient\SsoUserSynchronizer;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\Role;
use Unified\SsoClient\Tests\Stubs\Models\User;
use Unified\SsoClient\Tests\Stubs\StubSynchronizer;
use Unified\SsoClient\Tests\TestCase;

abstract class AgencyProfileTestCase extends TestCase
{
    protected bool $runProfileMigration = true;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! class_exists('App\\Models\\User')) {
            class_alias(User::class, 'App\\Models\\User');
            class_alias(Company::class, 'App\\Models\\Company');
            class_alias(Role::class, 'App\\Models\\Role');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        SsoUserSynchronizer::flushSchemaCache();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.webhook_secret', 'whsec');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        if ($this->runProfileMigration) {
            (require __DIR__.'/../../../database/company/2026_10_14_000000_add_sso_profile_to_companies_table.php')->up();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $companies
     */
    protected function login(array $companies): void
    {
        (new StubSynchronizer)->synchronize([
            'user' => ['id' => 5001, 'email' => 'medic@example.com', 'displayName' => 'Jordan Medic', 'username' => 'jmedic'],
            'companies' => $companies,
            'selectedCompany' => ['id' => $companies[0]['id']],
        ]);
    }

    /**
     * The compact block `/api/user` sends in companies[].profile.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function compactProfile(array $overrides = []): array
    {
        return array_replace([
            'state' => '36',
            'state_agency_id' => '1234',
            'agency_number' => 'A-77',
            'npi' => '1234567893',
            'address' => ['street' => '12 Main St', 'street2' => null, 'city_name' => 'Saranac Lake', 'state' => '36', 'zip' => '12983'],
            'phone' => '+15185550100',
            'fax' => null,
            'email' => 'office@agency.example',
            'website' => 'https://agency.example',
        ], $overrides);
    }

    /**
     * SSO's AgencyProfile::toContractArray().
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function fullProfile(array $overrides = []): array
    {
        return array_replace([
            'state' => '36',
            'state_agency_id' => '1234',
            'agency_number' => 'A-77',
            'npi' => '1234567893',
            'tax_id' => '123456789',
            'fire_department_id' => null,
            'neris_department_id' => null,
            'country' => 'US',
            'primary_service_type' => '9920001',
            'other_service_types' => [],
            'level_of_service' => '9917007',
            'organization_status' => '1016001',
            'organization_type' => '9912003',
            'tax_status' => '1018001',
            'address_street' => '12 Main St',
            'address_street2' => null,
            'address_city_gnis' => '978016',
            'address_city_name' => 'Saranac Lake',
            'address_state' => '36',
            'address_zip' => '12983',
            'address_county' => '36033',
            'address_country' => 'US',
            'latitude' => 44.3247,
            'longitude' => -74.1313,
            'mailing_same_as_physical' => false,
            'mailing_street' => 'PO Box 9',
            'mailing_street2' => null,
            'mailing_city_name' => 'Saranac Lake',
            'mailing_state' => '36',
            'mailing_zip' => '12983',
            'phone' => '+15185550100',
            'fax' => '+15185550101',
            'email' => 'office@agency.example',
            'website' => 'https://agency.example',
            'updated_at' => '2026-10-14T12:00:00+00:00',
        ], $overrides);
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
     * @param  array<string, mixed>  $profile
     */
    protected function deliverProfile(int $ssoCompanyId, array $profile): TestResponse
    {
        return $this->postWebhook('agency_profile.updated', [
            'company' => ['id' => $ssoCompanyId, 'division_label' => 'division'],
            'agency_profile' => $profile,
            'updated_at' => $profile['updated_at'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function storedProfile(int $ssoCompanyId): ?array
    {
        $value = Company::query()->where('sso_company_id', $ssoCompanyId)->value('sso_profile');

        return $value === null ? null : json_decode((string) $value, true);
    }
}
