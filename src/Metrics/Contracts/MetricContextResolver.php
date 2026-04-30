<?php

declare(strict_types=1);

namespace Unified\SsoClient\Metrics\Contracts;

interface MetricContextResolver
{
    /**
     * Translate a consuming app's local company id to the SSO company id.
     * Returns null if the local id can't be mapped.
     */
    public function ssoCompanyId(?int $localCompanyId): ?int;

    /**
     * Translate a consuming app's local user id to the SSO user id.
     * Returns null if the local id can't be mapped.
     */
    public function ssoUserId(?int $localUserId): ?int;
}
