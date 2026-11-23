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
        $app['config']->set('sso.core_api_key_previous', 'old-key');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(ValidateCoreApiKey::class)
            ->get('/test-internal', fn () => response()->json(['ok' => true]))
            ->name('test.internal');
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

    public function test_previous_key_is_accepted_and_records_info_event(): void
    {
        Queue::fake();

        $this->withToken('old-key')->getJson('/test-internal')->assertOk();

        Queue::assertPushed(SendSecurityEventToUnified::class, 1);
        Queue::assertPushed(SendSecurityEventToUnified::class, function (SendSecurityEventToUnified $job): bool {
            return $job->payload['event'] === 'internal_api.previous_key_used'
                && $job->payload['severity'] === 'info'
                && $job->payload['app_key'] === 'testapp'
                && $job->payload['route'] === 'test.internal'
                && $job->payload['context'] === null;
        });
    }

    public function test_previous_key_in_x_api_key_header_is_accepted(): void
    {
        Queue::fake();

        $this->getJson('/test-internal', ['X-API-KEY' => 'old-key'])->assertOk();
    }

    public function test_previous_key_event_is_recorded_once_per_route_per_window(): void
    {
        Queue::fake();

        $this->withToken('old-key')->getJson('/test-internal')->assertOk();
        $this->withToken('old-key')->getJson('/test-internal')->assertOk();
        $this->withToken('old-key')->getJson('/test-internal')->assertOk();

        Queue::assertPushed(SendSecurityEventToUnified::class, 1);
    }

    public function test_current_key_still_passes_without_event_while_previous_is_set(): void
    {
        Queue::fake();

        $this->getJson('/test-internal', ['X-API-KEY' => 'real-key'])->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_wrong_key_is_rejected_while_previous_is_set(): void
    {
        Queue::fake();

        $this->withToken('neither-key')->getJson('/test-internal')->assertUnauthorized();

        Queue::assertPushed(SendSecurityEventToUnified::class, function (SendSecurityEventToUnified $job): bool {
            return $job->payload['event'] === 'internal_api.auth_failed'
                && $job->payload['context'] === ['key_provided' => true];
        });
        Queue::assertNotPushed(SendSecurityEventToUnified::class, function (SendSecurityEventToUnified $job): bool {
            return $job->payload['event'] === 'internal_api.previous_key_used';
        });
    }

    public function test_empty_previous_key_never_matches_a_missing_or_empty_header(): void
    {
        Queue::fake();
        config(['sso.core_api_key_previous' => '']);

        $this->getJson('/test-internal')->assertUnauthorized();
        $this->getJson('/test-internal', ['X-API-KEY' => ''])->assertUnauthorized();
        $this->getJson('/test-internal', ['Authorization' => 'Bearer '])->assertUnauthorized();

        Queue::assertNotPushed(SendSecurityEventToUnified::class, function (SendSecurityEventToUnified $job): bool {
            return $job->payload['event'] === 'internal_api.previous_key_used';
        });
    }

    public function test_unset_previous_key_never_matches_a_missing_or_empty_header(): void
    {
        Queue::fake();
        config(['sso.core_api_key_previous' => null]);

        $this->getJson('/test-internal')->assertUnauthorized();
        $this->getJson('/test-internal', ['X-API-KEY' => ''])->assertUnauthorized();
    }

    public function test_previous_key_is_refused_once_removed(): void
    {
        Queue::fake();
        config(['sso.core_api_key_previous' => null]);

        $this->withToken('old-key')->getJson('/test-internal')->assertUnauthorized();
    }

    public function test_honeytoken_still_trips_while_previous_is_set(): void
    {
        Queue::fake();

        $this->getJson('/test-internal', ['X-API-KEY' => 'decoy-key'])->assertUnauthorized();

        Queue::assertPushed(SendSecurityEventToUnified::class, function (SendSecurityEventToUnified $job): bool {
            return $job->payload['event'] === 'honeytoken.api_key_used'
                && $job->payload['severity'] === 'critical';
        });
    }

    public function test_unconfigured_current_key_refuses_even_the_previous_key(): void
    {
        config(['app.core_api_key' => '']);

        $this->withToken('old-key')->getJson('/test-internal')->assertStatus(503);
        $this->getJson('/test-internal')->assertStatus(503);
    }
}
