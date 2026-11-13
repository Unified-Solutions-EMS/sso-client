<?php

declare(strict_types=1);

namespace Unified\SsoClient\Trial;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tells SSO how this app's trial seeder ended, so the trial admin's welcome
 * modal can move its setup bar and let them in. Best effort: a missing
 * config, an unreachable SSO or an error answer is logged and swallowed. SSO
 * counts a step as finished on its own after ten minutes, so a lost report
 * only delays the bar; it never blocks the person for good.
 */
class TrialSeedStatusReporter
{
    public const SEEDED = 'seeded';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public function report(int|string $ssoCompanyId, string $status): void
    {
        $baseUrl = rtrim((string) config('sso.base_url'), '/');
        $apiKey = (string) (config('app.core_api_key') ?: config('security.token'));
        $appSlug = (string) config('sso.app_slug');

        if ($baseUrl === '' || $apiKey === '' || $appSlug === '') {
            Log::warning('SSO trial seed: cannot report the seed status, SSO_BASE_URL, CORE_APP_API_KEY or SSO_APP_SLUG is not set', [
                'sso_company_id' => $ssoCompanyId,
                'status' => $status,
            ]);

            return;
        }

        $url = $baseUrl.'/api/internal/companies/'.rawurlencode((string) $ssoCompanyId).'/trial-seed-status';

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(10)
                ->post($url, ['app_slug' => $appSlug, 'status' => $status]);

            if (! $response->successful()) {
                Log::warning('SSO trial seed: SSO refused the seed status', [
                    'sso_company_id' => $ssoCompanyId,
                    'status' => $status,
                    'http_status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('SSO trial seed: could not report the seed status', [
                'sso_company_id' => $ssoCompanyId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
