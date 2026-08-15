<?php

namespace Unified\SsoClient\Tests\Feature\Security;

use Illuminate\Support\Facades\Queue;
use Unified\SsoClient\Middleware\ValidateCoreApiKey;
use Unified\SsoClient\Security\Jobs\SendSecurityEventToUnified;
use Unified\SsoClient\Tests\TestCase;

class ValidateCoreApiKeyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('security.enabled', true);

        $app['config']->set('app.core_api_key', 'real-key');
        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.app_slug', 'testapp');
        $app['config']->set('security.token', 'real-key');
        $app['config']->set('security.honeytokens', 'decoy-key');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(ValidateCoreApiKey::class)
            ->get('/test-internal', fn () => response()->json(['ok' => true]));
    }

    public function test_valid_key_passes_without_event(): void
    {
        Queue::fake();

        $this->withToken('real-key')->getJson('/test-internal')->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_invalid_key_records_auth_failed_event(): void
    {
        Queue::fake();

        $this->withToken('wrong-key')->getJson('/test-internal')->assertUnauthorized();

        Queue::assertPushed(SendSecurityEventToUnified::class, function (SendSecurityEventToUnified $job): bool {
            return $job->payload['event'] === 'internal_api.auth_failed'
                && $job->payload['severity'] === 'warning'
                && $job->payload['context'] === ['key_provided' => true];
        });
    }

    public function test_missing_key_records_auth_failed_event(): void
    {
        Queue::fake();

        $this->getJson('/test-internal')->assertUnauthorized();

        Queue::assertPushed(SendSecurityEventToUnified::class, function (SendSecurityEventToUnified $job): bool {
            return $job->payload['event'] === 'internal_api.auth_failed'
                && $job->payload['context'] === ['key_provided' => false];
        });
    }

    public function test_honeytoken_key_records_critical_event(): void
    {
        Queue::fake();

        $this->withToken('decoy-key')->getJson('/test-internal')->assertUnauthorized();

        Queue::assertPushed(SendSecurityEventToUnified::class, function (SendSecurityEventToUnified $job): bool {
            return $job->payload['event'] === 'honeytoken.api_key_used'
                && $job->payload['severity'] === 'critical';
        });
    }
}
