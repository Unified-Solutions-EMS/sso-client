<?php

declare(strict_types=1);

namespace Unified\SsoClient\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\Metrics\Contracts\MetricContextResolver;
use Unified\SsoClient\Security\Jobs\SendSecurityEventToUnified;

/**
 * Public entry point for recording security events.
 *
 * Usage:
 *   SecurityEvents::warning('internal_api.auth_failed', ['key_provided' => true]);
 *   SecurityEvents::critical('honeytoken.api_key_used');
 *   SecurityEvents::info('auth.password_reset', ['email' => $email]);
 *
 * Each call enqueues a SendSecurityEventToUnified job that POSTs to SSO's
 * /api/internal/security-events/ingest endpoint, authenticated with the
 * shared CORE_APP_API_KEY. Recording is best-effort: if the package is
 * not configured the call logs a warning and no-ops, and it never raises
 * into the calling request.
 *
 * IP, user agent, and route are captured from the current request when
 * available. Local company / user ids are translated to SSO ids via the
 * bound MetricContextResolver, same as the Metrics component.
 */
class SecurityEvents
{
    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    public function __construct(
        protected MetricContextResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $event, array $context = []): void
    {
        $this->record($event, $context, self::SEVERITY_INFO);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $event, array $context = []): void
    {
        $this->record($event, $context, self::SEVERITY_WARNING);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function critical(string $event, array $context = []): void
    {
        $this->record($event, $context, self::SEVERITY_CRITICAL);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $event, array $context = [], string $severity = self::SEVERITY_WARNING): void
    {
        if (! $this->enabled()) {
            return;
        }

        $appKey = $this->appKey();
        $endpoint = $this->endpoint();
        $token = (string) config('security.token', '');

        if ($appKey === '' || $endpoint === '' || $token === '') {
            Log::warning('Security event not sent: package not fully configured', [
                'event' => $event,
                'app_key_set' => $appKey !== '',
                'endpoint_set' => $endpoint !== '',
                'token_set' => $token !== '',
            ]);

            return;
        }

        $request = $this->currentRequest();

        $ip = $context['ip'] ?? $request?->ip();
        $userAgent = $context['user_agent'] ?? $request?->userAgent();
        $route = $context['route'] ?? ($request?->route()?->getName() ?? $request?->path());

        unset($context['ip'], $context['user_agent'], $context['route']);

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

        unset($context['sso_company_id'], $context['sso_user_id']);

        $payload = [
            'app_key' => $appKey,
            'event' => $event,
            'severity' => $severity,
            'ip' => $ip !== null ? (string) $ip : null,
            'user_agent' => $userAgent !== null ? mb_substr((string) $userAgent, 0, 255) : null,
            'route' => $route !== null ? mb_substr((string) $route, 0, 255) : null,
            'sso_company_id' => $ssoCompanyId,
            'sso_user_id' => $ssoUserId,
            'context' => $context === [] ? null : $context,
            'occurred_at' => Carbon::now()->toIso8601String(),
        ];

        $job = new SendSecurityEventToUnified($payload);

        $connection = config('security.queue_connection');
        $queue = config('security.queue');

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }
        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        dispatch($job);
    }

    /**
     * Whether the given value is one of this app's planted decoy API keys.
     */
    public function isHoneytoken(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        foreach ($this->csvConfig('security.honeytokens') as $token) {
            if (hash_equals($token, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the given email belongs to a planted canary account.
     */
    public function isCanaryEmail(?string $email): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        return in_array(
            strtolower(trim($email)),
            array_map('strtolower', $this->csvConfig('security.canary_emails')),
            true,
        );
    }

    /**
     * Recording defaults to OFF while running unit tests: on the sync queue
     * every emit would fire a real HTTP call at SSO from inside app test
     * suites (slow, and it pollutes a live security_events table). Tests
     * that exercise the pipeline itself opt back in with
     * config(['security.enabled' => true]).
     */
    protected function enabled(): bool
    {
        $enabled = config('security.enabled');

        if ($enabled !== null) {
            return (bool) $enabled;
        }

        return ! app()->runningUnitTests();
    }

    protected function appKey(): string
    {
        return (string) (config('security.app_key')
            ?: config('metrics.app_key')
            ?: config('sso.app_slug')
            ?: '');
    }

    protected function endpoint(): string
    {
        $endpoint = (string) config('security.endpoint', '');

        if ($endpoint !== '') {
            return $endpoint;
        }

        $baseUrl = (string) config('sso.base_url', '');

        return $baseUrl === ''
            ? ''
            : rtrim($baseUrl, '/').'/api/internal/security-events/ingest';
    }

    /**
     * @return list<string>
     */
    protected function csvConfig(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config($key, '')))));
    }

    protected function currentRequest(): ?Request
    {
        return app()->bound('request') ? app('request') : null;
    }
}
