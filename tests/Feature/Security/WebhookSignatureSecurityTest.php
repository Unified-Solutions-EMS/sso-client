<?php

namespace Unified\SsoClient\Tests\Feature\Security;

use Illuminate\Support\Facades\Queue;
use Unified\SsoClient\Security\Jobs\SendSecurityEventToUnified;
use Unified\SsoClient\Tests\TestCase;

class WebhookSignatureSecurityTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('security.enabled', true);

        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.app_slug', 'testapp');
        $app['config']->set('sso.webhook_secret', 'whsec');
        $app['config']->set('security.token', 'core-api-key');
    }

    public function test_invalid_signature_records_security_event(): void
    {
        Queue::fake();

        $this->postJson('/api/sso/provision', ['event' => 'user.updated'], [
            'X-SSO-Signature' => 'bogus',
        ])->assertForbidden();

        Queue::assertPushed(SendSecurityEventToUnified::class, function (SendSecurityEventToUnified $job): bool {
            return $job->payload['event'] === 'webhook.signature_failed'
                && $job->payload['severity'] === 'warning'
                && $job->payload['context']['sso_event'] === 'user.updated'
                && $job->payload['context']['signature_present'] === true;
        });
    }

    public function test_valid_signature_records_nothing(): void
    {
        Queue::fake();

        $body = json_encode(['event' => 'some.unknown_event']);

        $this->call('POST', '/api/sso/provision', [], [], [], [
            'HTTP_X-SSO-Signature' => hash_hmac('sha256', $body, 'whsec'),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body)->assertOk();

        Queue::assertNothingPushed();
    }
}
