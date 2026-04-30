<?php

declare(strict_types=1);

namespace Unified\SsoClient\Metrics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\Metrics\Contracts\MetricContextResolver;
use Unified\SsoClient\Metrics\Jobs\SendMetricToUnified;

/**
 * Public entry point for emitting usage metrics.
 *
 * Usage:
 *   Metrics::increment('pcr.created');
 *   Metrics::increment('pcr.exported', context: ['report_id' => 42]);
 *   Metrics::record('session.duration_minutes', 12.5);
 *
 * Each call enqueues a SendMetricToUnified job that POSTs to SSO's
 * /api/internal/metrics/ingest endpoint with the X-Metrics-Token header.
 *
 * Local company / user ids are translated to SSO ids via the bound
 * MetricContextResolver. If neither is supplied via $context, the
 * helper pulls the currently-auth'd user and the active company id
 * (from session('selected_company_id') if present).
 */
class Metrics
{
    public function __construct(
        protected MetricContextResolver $resolver,
    ) {}

    /**
     * Record a counter metric (default value 1).
     *
     * @param  array<string, mixed>  $context
     */
    public function increment(string $metric, float $value = 1.0, array $context = []): void
    {
        $this->record($metric, $value, $context);
    }

    /**
     * Record a metric with an explicit value.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(string $metric, float $value, array $context = []): void
    {
        $appKey = (string) config('metrics.app_key', '');
        $endpoint = (string) config('metrics.endpoint', '');
        $token = (string) config('metrics.token', '');

        if ($appKey === '' || $endpoint === '' || $token === '') {
            Log::warning('Metrics not sent: package not fully configured', [
                'metric' => $metric,
                'app_key_set' => $appKey !== '',
                'endpoint_set' => $endpoint !== '',
                'token_set' => $token !== '',
            ]);

            return;
        }

        // Sender-supplied context takes precedence; otherwise pull from
        // auth + session. Local ids are translated to SSO ids before
        // they leave this process.
        $localCompanyId = $context['local_company_id']
            ?? session('selected_company_id')
            ?? null;
        $localUserId = $context['local_user_id']
            ?? Auth::id()
            ?? null;

        unset($context['local_company_id'], $context['local_user_id']);

        $ssoCompanyId = $context['sso_company_id']
            ?? $this->resolver->ssoCompanyId($localCompanyId !== null ? (int) $localCompanyId : null);
        $ssoUserId = $context['sso_user_id']
            ?? $this->resolver->ssoUserId($localUserId !== null ? (int) $localUserId : null);

        $payload = [
            'app_key' => $appKey,
            'metric' => $metric,
            'value' => $value,
            'context' => array_merge($context, array_filter([
                'sso_company_id' => $ssoCompanyId,
                'sso_user_id' => $ssoUserId,
            ], static fn ($v) => $v !== null)),
            'occurred_at' => Carbon::now()->toIso8601String(),
        ];

        $job = new SendMetricToUnified($payload);

        $connection = config('metrics.queue_connection');
        $queue = config('metrics.queue');

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }
        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        dispatch($job);
    }
}
