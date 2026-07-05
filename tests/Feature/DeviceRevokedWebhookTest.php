<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Testing\TestResponse;
use Unified\SsoClient\Device\DeviceGuard;
use Unified\SsoClient\Tests\TestCase;

class DeviceRevokedWebhookTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('sso.webhook_secret', 'webhook-secret');
    }

    private function signedWebhook(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call(
            'POST',
            '/api/sso/provision',
            [], [], [],
            [
                'HTTP_X_SSO_SIGNATURE' => hash_hmac('sha256', $body, 'webhook-secret'),
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $body,
        );
    }

    public function test_device_revoked_blacklists_the_device(): void
    {
        $guard = app(DeviceGuard::class);
        $this->assertFalse($guard->deviceIsRevoked(42));

        $this->signedWebhook([
            'event' => 'device.revoked',
            'company' => ['id' => 70],
            'device' => ['id' => 42, 'key_id' => 'k1', 'label' => 'Front desk', 'revoked_at' => now()->toIso8601String()],
        ])->assertOk()->assertJsonPath('action', 'device.revoked');

        $this->assertTrue($guard->deviceIsRevoked(42));
    }

    public function test_a_payload_without_a_device_id_is_skipped(): void
    {
        $this->signedWebhook(['event' => 'device.revoked', 'device' => []])
            ->assertOk()
            ->assertJsonPath('skipped', true);
    }

    public function test_an_unsigned_request_is_rejected(): void
    {
        $this->postJson('/api/sso/provision', ['event' => 'device.revoked', 'device' => ['id' => 42]])
            ->assertStatus(403);

        $this->assertFalse(app(DeviceGuard::class)->deviceIsRevoked(42));
    }
}
