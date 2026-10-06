<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Contracts;

/**
 * A read-only local copy of one SSO master-data entity.
 *
 * SSO is canonical: the mirror only ever writes what SSO sent, keyed by the
 * SSO id held in {@see ssoIdColumn()}. Every write is pinned to the local
 * company the caller resolved from an authoritative SSO company id (HMAC
 * webhook payload, or the company a resync was asked for), never user input.
 */
interface EntityMirror
{
    /**
     * Config key under `sso.master_data` and the path segment of the SSO
     * internal API (`/api/internal/companies/{id}/{entity}`).
     */
    public function entity(): string;

    /**
     * Webhook events this mirror consumes.
     *
     * @return list<string>
     */
    public static function events(): array;

    public function table(): string;

    public function ssoIdColumn(): string;

    /**
     * Whether the app has run this entity's mirror migration. Webhooks and
     * login syncs are skipped (and logged) until it has.
     */
    public function isInstalled(): bool;

    /**
     * Apply one webhook delivery for a company already resolved locally.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function applyWebhook(string $event, int $localCompanyId, array $payload): array;

    /**
     * Reconcile the company against a full snapshot from the SSO internal API.
     * Must be idempotent: a second run with the same snapshot changes nothing.
     * Rows that vanished from SSO are deactivated, never hard-deleted.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, int>
     */
    public function resync(int $localCompanyId, array $snapshot): array;

    /**
     * Cutover helper: link existing unlinked local rows to SSO rows by name
     * without creating, merging or deleting anything.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *     linked: list<array{local_id: int, sso_id: int, name: string}>,
     *     ambiguous: list<array{name: string, local_ids: list<int>, sso_ids: list<int>}>,
     *     unmatched_local: list<array{id: int, name: string}>,
     *     unmatched_sso: list<array{id: int, name: string}>
     * }
     */
    public function linkByName(int $localCompanyId, array $snapshot): array;
}
