<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\MasterData\Exceptions\MasterDataSyncException;

/**
 * Talks to SSO's master-data internal API, authenticated with
 * CORE_APP_API_KEY as a bearer token:
 * - GET  /api/internal/companies/{ssoCompanyId}/{entity}         full snapshot
 * - POST /api/internal/companies/{ssoCompanyId}/{entity}/import  one-time upward seed
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
        return $this->send('get', $entity, $ssoCompanyId, '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws MasterDataSyncException
     */
    public function import(string $entity, int|string $ssoCompanyId, array $payload): array
    {
        return $this->send('post', $entity, $ssoCompanyId, '/import', $payload);
    }

    /**
     * @param  'get'|'post'  $method
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $entity, int|string $ssoCompanyId, string $suffix, array $payload = []): array
    {
        $baseUrl = rtrim((string) config('sso.base_url'), '/');
        $apiKey = (string) (config('app.core_api_key') ?: config('security.token'));

        if ($baseUrl === '' || $apiKey === '') {
            throw new MasterDataSyncException('SSO_BASE_URL or CORE_APP_API_KEY is not configured.');
        }

        $url = $baseUrl.'/api/internal/companies/'.rawurlencode((string) $ssoCompanyId).'/'.rawurlencode($entity).$suffix;

        $timeout = (int) config('sso.master_data.timeout', 120);

        try {
            $request = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($timeout);

            /** @var Response $response */
            $response = $method === 'post' ? $request->post($url, $payload) : $request->get($url);
        } catch (ConnectionException $e) {
            if (str_contains($e->getMessage(), 'cURL error 28') || stripos($e->getMessage(), 'timed out') !== false) {
                throw new MasterDataSyncException(
                    "SSO did not answer {$entity}{$suffix} for company {$ssoCompanyId} within {$timeout}s; nothing was changed locally. "
                    .'SSO may still finish the request. Rerun it (it is idempotent) or raise SSO_MASTER_DATA_TIMEOUT.',
                    0,
                    $e,
                );
            }

            throw new MasterDataSyncException("Could not reach SSO for {$entity}: {$e->getMessage()}", 0, $e);
        } catch (\Throwable $e) {
            throw new MasterDataSyncException("Could not reach SSO for {$entity}: {$e->getMessage()}", 0, $e);
        }

        if (! $response->successful()) {
            throw new MasterDataSyncException("SSO returned HTTP {$response->status()} for {$entity}{$suffix} of company {$ssoCompanyId}.");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new MasterDataSyncException("SSO returned a non-JSON body for {$entity}{$suffix} of company {$ssoCompanyId}.");
        }

        return $body;
    }
}
