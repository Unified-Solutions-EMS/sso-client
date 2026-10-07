<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Contracts;

/**
 * A mirror whose pre-cutover local data can be pushed up to SSO once, so the
 * agency's existing catalog becomes the SSO catalog instead of being retyped.
 * SSO answers with a local id -> SSO id mapping that links the local rows.
 */
interface SeedsSso
{
    /**
     * @return array{payload: array<string, mixed>, skipped_users: int}
     */
    public function buildImportPayload(int $localCompanyId): array;

    /**
     * @param  array<int|string, int|string>  $mapping  SSO id keyed by local id
     * @return array{applied: int, collisions: list<array{local_id: int, sso_id: int, reason: string}>}
     */
    public function applyImportMapping(int $localCompanyId, array $mapping): array;
}
