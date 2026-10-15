<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\MasterData\Contracts\EntityMirror;
use Unified\SsoClient\MasterData\Contracts\FetchesSnapshot;
use Unified\SsoClient\MasterData\Exceptions\MasterDataSyncException;

/**
 * Talks to SSO's master-data internal API, authenticated with
 * CORE_APP_API_KEY as a bearer token:
 * - GET  /api/internal/companies/{ssoCompanyId}/{entity}         full snapshot
 * - POST /api/internal/companies/{ssoCompanyId}/{entity}/import  one-time upward seed
 * - GET  /api/internal/companies/{ssoCompanyId}/{entity}/reviews/{id}  a code list's cutover review
 * - GET / PATCH /api/internal/companies/{ssoCompanyId}/personnel[/{ssoUserId}]  paged personnel
 *   snapshots, one person, and the write-through (get() / patch())
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
     * The snapshot a resync reads: the mirror's own when it pages
     * (FetchesSnapshot), otherwise the plain fetch().
     *
     * @return array<string, mixed>
     *
     * @throws MasterDataSyncException
     */
    public function snapshot(EntityMirror $mirror, int|string $ssoCompanyId): array
    {
        return $mirror instanceof FetchesSnapshot
            ? $mirror->fetchSnapshot($ssoCompanyId)
            : $this->fetch($mirror->entity(), $ssoCompanyId);
    }

    /**
     * Any GET under the entity's path, with a query string and, for calls
     * made while a user waits (a login pull), a shorter timeout than the
     * master-data one.
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     *
     * @throws MasterDataSyncException
     */
    public function get(string $entity, int|string $ssoCompanyId, string $suffix = '', array $query = [], ?int $timeout = null): array
    {
        return $this->send('get', $entity, $ssoCompanyId, $suffix, [], $query, $timeout);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws MasterDataSyncException
     */
    public function patch(string $entity, int|string $ssoCompanyId, string $suffix, array $payload, ?int $timeout = null): array
    {
        return $this->send('patch', $entity, $ssoCompanyId, $suffix, $payload, [], $timeout);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws MasterDataSyncException
     */
    public function import(string $entity, int|string $ssoCompanyId, array $payload, ?int $timeout = null): array
    {
        return $this->send('post', $entity, $ssoCompanyId, '/import', $payload, [], $timeout);
    }

    /**
     * The state of a code list's cutover review (`{id, status, completed_at,
     * mapping: {app_slug: [{local_id, sso_id|null}]}}`).
     *
     * @return array<string, mixed>
     *
     * @throws MasterDataSyncException
     */
    public function review(string $entity, int|string $ssoCompanyId, int $reviewId): array
    {
        return $this->send('get', $entity, $ssoCompanyId, '/reviews/'.$reviewId);
    }

    /**
     * @param  'get'|'post'|'patch'  $method
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    private function send(string $method, string $entity, int|string $ssoCompanyId, string $suffix, array $payload = [], array $query = [], ?int $timeout = null): array
    {
        $baseUrl = rtrim((string) config('sso.base_url'), '/');
        $apiKey = (string) (config('app.core_api_key') ?: config('security.token'));

        if ($baseUrl === '' || $apiKey === '') {
            throw new MasterDataSyncException('SSO_BASE_URL or CORE_APP_API_KEY is not configured.');
        }

        $url = $baseUrl.'/api/internal/companies/'.rawurlencode((string) $ssoCompanyId).'/'.rawurlencode($entity).$suffix;

        $timeout ??= (int) config('sso.master_data.timeout', 120);
        $query = array_filter($query, fn (mixed $value): bool => $value !== null && $value !== '');

        try {
            $request = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($timeout);

            /** @var Response $response */
            $response = match ($method) {
                'post' => $request->post($url, $payload),
                'patch' => $request->patch($url, $payload),
                default => $request->get($url, $query),
            };
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
