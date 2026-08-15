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
 * Failures are logged and swallowed, same contract as SendMetricToUnified:
 * on a sync queue this job runs INSIDE the calling request (login paths,
 * auth middleware), so an unreachable SSO must never raise. That rules out
 * exception-driven retries — a lost event is acceptable; a 500 on an
 * unrelated request is not.
 */
class SendSecurityEventToUnified implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

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

        try {
            Http::timeout(5)
                ->withToken($token)
                ->withOptions(['verify' => (bool) config('security.verify_ssl', true)])
                ->post($endpoint, $this->payload)
                ->throw();
        } catch (\Throwable $e) {
            Log::warning('Security event send failed', [
                'message' => $e->getMessage(),
                'event' => $this->payload['event'] ?? null,
            ]);
        }
    }
}
