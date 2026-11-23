<?php

namespace Unified\SsoClient\Tests\Feature\Security;

use Illuminate\Support\Facades\Queue;
use Unified\SsoClient\Security\Jobs\SendSecurityEventToUnified;
use Unified\SsoClient\Tests\TestCase;

class CspReportTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('security.enabled', true);
        $app['config']->set('sso.base_url', 'https://sso.example.test');
        $app['config']->set('sso.app_slug', 'testapp');
        $app['config']->set('security.token', 'real-key');
        $app['config']->set('security-headers.csp_report_only.enabled', true);
        $app['config']->set('security-headers.csp_report_only.sample_rate', 1.0);
    }

    private function postReport(string $body, string $type = 'application/csp-report')
    {
        return $this->call('POST', '/api/security/csp-report', [], [], [], ['CONTENT_TYPE' => $type], $body);
    }

    private function legacyReport(): string
    {
        return json_encode(['csp-report' => [
            'document-uri' => 'https://app.example.test/patients/123?name=Jane',
            'referrer' => 'https://app.example.test/patients',
            'blocked-uri' => 'https://evil.example.test/x.js?token=abc',
            'effective-directive' => 'script-src-elem',
            'violated-directive' => 'script-src-elem',
            'script-sample' => 'alert(1)',
        ]]);
    }

    public function test_legacy_report_records_host_and_directive_only(): void
    {
        Queue::fake();

        $this->postReport($this->legacyReport())->assertNoContent();

        Queue::assertPushed(SendSecurityEventToUnified::class, function (SendSecurityEventToUnified $job): bool {
            return $job->payload['event'] === 'csp.violation'
                && $job->payload['severity'] === 'info'
                && $job->payload['app_key'] === 'testapp'
                && $job->payload['context'] === ['blocked' => 'evil.example.test', 'directive' => 'script-src-elem'];
        });
    }

    public function test_reporting_api_batch_is_recorded_per_violation(): void
    {
        Queue::fake();

        $body = json_encode([
            ['type' => 'csp-violation', 'body' => ['blockedURL' => 'inline', 'effectiveDirective' => 'style-src-attr', 'documentURL' => 'https://app.example.test/x']],
            ['type' => 'csp-violation', 'body' => ['blockedURL' => 'data:image/png;base64,AAA', 'effectiveDirective' => 'img-src']],
            ['type' => 'deprecation', 'body' => ['id' => 'x']],
        ]);

        $this->postReport($body, 'application/reports+json')->assertNoContent();

        Queue::assertPushed(SendSecurityEventToUnified::class, 2);
        Queue::assertPushed(SendSecurityEventToUnified::class, fn ($job): bool => $job->payload['context'] === ['blocked' => 'inline', 'directive' => 'style-src-attr']);
        Queue::assertPushed(SendSecurityEventToUnified::class, fn ($job): bool => $job->payload['context'] === ['blocked' => 'data', 'directive' => 'img-src']);
    }

    public function test_zero_sample_rate_records_nothing(): void
    {
        Queue::fake();
        config(['security-headers.csp_report_only.sample_rate' => 0]);

        $this->postReport($this->legacyReport())->assertNoContent();

        Queue::assertNothingPushed();
    }

    public function test_reports_are_ignored_while_report_only_is_off(): void
    {
        Queue::fake();
        config(['security-headers.csp_report_only.enabled' => false]);

        $this->postReport($this->legacyReport())->assertNoContent();

        Queue::assertNothingPushed();
    }

    public function test_garbage_and_oversized_bodies_are_ignored(): void
    {
        Queue::fake();

        $this->postReport('not json')->assertNoContent();
        $this->postReport('')->assertNoContent();
        $this->postReport(json_encode(['csp-report' => ['blocked-uri' => str_repeat('a', 70000)]]))->assertNoContent();

        Queue::assertNothingPushed();
    }

    public function test_odd_values_are_reduced_to_safe_tokens(): void
    {
        Queue::fake();

        $this->postReport(json_encode(['csp-report' => [
            'blocked-uri' => 'Patient Jane Doe; DOB 1/1/1970',
            'violated-directive' => "script-src 'self'",
        ]]))->assertNoContent();

        Queue::assertPushed(SendSecurityEventToUnified::class, fn ($job): bool => $job->payload['context'] === ['blocked' => 'other', 'directive' => 'script-src']);
    }

    public function test_route_needs_no_csrf_token(): void
    {
        $this->assertNotContains('web', $this->app['router']->getRoutes()->getByName('sso.csp-report')->gatherMiddleware());
    }
}
