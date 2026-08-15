<?php

declare(strict_types=1);

namespace Unified\SsoClient\Security\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * POSTs one security event to SSO's /api/internal/security-events/ingest
 * endpoint.
 *
 * Unlike metrics, security events are worth a couple of retries — losing
 * the event that would have tripped an alert is worse than a short queue
 * backlog. Final failures are logged and swallowed so a dead SSO never
 * cascades into the sending app.
 */
class SendSecurityEventToUnified implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    public function handle(): void
    {
        $endpoint = (string) config('security.endpoint', '');

        if ($endpoint === '') {
            $baseUrl = (string) config('sso.base_url', '');
            $endpoint = $baseUrl === ''
                ? ''
                : rtrim($baseUrl, '/').'/api/internal/security-events/ingest';
        }

        $token = (string) config('security.token', '');

        if ($endpoint === '' || $token === '') {
            Log::warning('Security event not sent: missing endpoint or token', [
                'event' => $this->payload['event'] ?? null,
                'endpoint_set' => $endpoint !== '',
                'token_set' => $token !== '',
            ]);

            return;
        }

        Http::timeout(5)
            ->withToken($token)
            ->withOptions(['verify' => (bool) config('security.verify_ssl', true)])
            ->post($endpoint, $this->payload)
            ->throw();
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Security event send failed', [
            'message' => $e->getMessage(),
            'event' => $this->payload['event'] ?? null,
        ]);
    }
}
