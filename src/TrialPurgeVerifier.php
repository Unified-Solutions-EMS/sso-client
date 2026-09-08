<?php

declare(strict_types=1);

namespace Unified\SsoClient;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Confirms with SSO that a company may have its trial data purged before a
 * trial.purge_data webhook is allowed to run the app's TrialDataPurger.
 *
 * SSO's GET /api/internal/companies/trials is the authoritative trial list.
 * A company is purgeable when it is a current trial, or when SSO reports a
 * pending purge intent for it (the response's purge_pending id list). The
 * intent list exists because the conversion flow flips companies.status to
 * active before the queued purge webhook is delivered, so the trials list
 * alone would reject every legitimate convert-and-erase purge.
 *
 * Fail closed: missing config, SSO unreachable, an error response, or a
 * company absent from both lists all block the purge. Verification never
 * throws into the webhook request.
 */
class TrialPurgeVerifier
{
    public const PURGEABLE = 'purgeable';

    public const NOT_CONFIGURED = 'sso_not_configured';

    public const UNREACHABLE = 'sso_unreachable';

    public const NOT_PURGEABLE = 'not_purgeable';

    public function verify(int|string $ssoCompanyId): string
    {
        $baseUrl = rtrim((string) config('sso.base_url'), '/');
        $apiKey = (string) config('app.core_api_key');

        if ($baseUrl === '' || $apiKey === '') {
            return self::NOT_CONFIGURED;
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout((int) config('sso.timeout', 10))
                ->get($baseUrl.'/api/internal/companies/trials');
        } catch (\Throwable $e) {
            Log::warning('Trial purge verification: SSO unreachable', [
                'sso_company_id' => $ssoCompanyId,
                'error' => $e->getMessage(),
            ]);

            return self::UNREACHABLE;
        }

        if (! $response->successful()) {
            Log::warning('Trial purge verification: SSO returned an error response', [
                'sso_company_id' => $ssoCompanyId,
                'status' => $response->status(),
            ]);

            return self::UNREACHABLE;
        }

        $purgeableIds = collect($response->json('data', []))
            ->pluck('id')
            ->merge($response->json('purge_pending', []))
            ->filter(fn ($id): bool => $id !== null && $id !== '')
            ->map(fn ($id): string => (string) $id);

        return $purgeableIds->contains((string) $ssoCompanyId)
            ? self::PURGEABLE
            : self::NOT_PURGEABLE;
    }
}
