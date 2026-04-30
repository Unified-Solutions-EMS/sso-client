<?php

declare(strict_types=1);

namespace Unified\SsoClient\Metrics\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * POSTs one metric event to SSO's /api/internal/metrics/ingest endpoint.
 *
 * Failures are logged and swallowed — usage metrics are best-effort and
 * MUST NOT raise into the calling request. Retries are deliberately
 * disabled (tries=1) for the same reason: a metric blip is acceptable;
 * a backed-up retry queue is not.
 */
class SendMetricToUnified implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        protected array $payload,
    ) {}

    public function handle(): void
    {
        $endpoint = (string) config('metrics.endpoint', '');
        $token = (string) config('metrics.token', '');

        if ($endpoint === '' || $token === '') {
            Log::warning('Metrics not sent: missing endpoint or token', [
                'metric' => $this->payload['metric'] ?? null,
                'endpoint_set' => $endpoint !== '',
                'token_set' => $token !== '',
            ]);

            return;
        }

        try {
            Http::timeout(5)
                ->withHeaders(['X-Metrics-Token' => $token])
                ->withOptions(['verify' => (bool) config('metrics.verify_ssl', true)])
                ->post($endpoint, $this->payload)
                ->throw();
        } catch (\Throwable $e) {
            Log::warning('Metrics send failed', [
                'message' => $e->getMessage(),
                'endpoint' => $endpoint,
                'metric' => $this->payload['metric'] ?? null,
            ]);
        }
    }
}
