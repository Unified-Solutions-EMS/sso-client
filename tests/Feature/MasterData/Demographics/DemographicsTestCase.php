<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Demographics;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\Tests\TestCase;

/**
 * The projected mirrors (agency, contacts, devices, state configurations)
 * on the package's standard sso_* read models, with the entity under test
 * turned on and its published migration run. A test case for a
 * CloudPCR-shaped app binds its projection and creates its tables in
 * createAppTables() first.
 */
abstract class DemographicsTestCase extends TestCase
{
    /** The registry entity under test. */
    protected string $entity;

    protected bool $runMirrorMigration = true;

    private const MIGRATIONS = [
        'agency' => '2026_10_14_100000_create_or_extend_agency_mirror.php',
        'contacts' => '2026_10_14_100100_create_or_extend_contacts_mirror.php',
        'devices' => '2026_10_14_100200_create_or_extend_devices_mirror.php',
        'state_configurations' => '2026_10_14_100300_create_or_extend_state_configurations_mirror.php',
    ];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.app_slug', 'cloudpcr');
        $app['config']->set('sso.webhook_secret', 'whsec');
        $app['config']->set('app.core_api_key', 'core-key');
        $app['config']->set("sso.master_data.{$this->entity}", true);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->createAppTables();

        if ($this->runMirrorMigration) {
            $this->migrate($this->entity);
        }
    }

    protected function createAppTables(): void {}

    protected function migrate(string $entity): void
    {
        (require __DIR__.'/../../../../database/master-data/'.self::MIGRATIONS[$entity])->up();
    }

    protected function company(int $ssoCompanyId, string $name = 'Agency'): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => $name.' '.$ssoCompanyId,
            'sso_company_id' => (string) $ssoCompanyId,
            'timezone' => 'America/New_York',
        ]);
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
     * One `<record>.created|updated|deactivated` delivery.
     *
     * @param  array<string, mixed>  $record
     */
    protected function deliver(string $event, int $ssoCompanyId, array $record): TestResponse
    {
        return $this->postWebhook($event, [
            'company' => ['id' => $ssoCompanyId],
            strstr($event, '.', true) => $record,
            'updated_at' => $record['updated_at'] ?? null,
        ]);
    }
}
