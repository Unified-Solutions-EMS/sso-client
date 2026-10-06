<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData;

use Illuminate\Support\Facades\Http;
use Unified\SsoClient\MasterData\Exceptions\MasterDataSyncException;

/**
 * Reads full master-data snapshots from SSO's internal API:
 * GET {sso.base_url}/api/internal/companies/{ssoCompanyId}/{entity},
 * authenticated with CORE_APP_API_KEY as a bearer token.
 */
class MasterDataClient
{
    /**
     * @return array<string, mixed>
     *
     * @throws MasterDataSyncException when SSO is not configured, unreachable,
     *                                 or answers with an error or non-object body
     */
    public function fetch(string $entity, int|string $ssoCompanyId): array
    {
        $baseUrl = rtrim((string) config('sso.base_url'), '/');
        $apiKey = (string) (config('app.core_api_key') ?: config('security.token'));

        if ($baseUrl === '' || $apiKey === '') {
            throw new MasterDataSyncException('SSO_BASE_URL or CORE_APP_API_KEY is not configured.');
        }

        $url = $baseUrl.'/api/internal/companies/'.rawurlencode((string) $ssoCompanyId).'/'.rawurlencode($entity);

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout((int) config('sso.timeout', 10))
                ->get($url);
        } catch (\Throwable $e) {
            throw new MasterDataSyncException("Could not reach SSO for {$entity}: {$e->getMessage()}", 0, $e);
        }

        if (! $response->successful()) {
            throw new MasterDataSyncException("SSO returned HTTP {$response->status()} for {$entity} of company {$ssoCompanyId}.");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new MasterDataSyncException("SSO returned a non-JSON body for {$entity} of company {$ssoCompanyId}.");
        }

        return $body;
    }
}
