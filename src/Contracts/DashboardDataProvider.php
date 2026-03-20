<?php

namespace Unified\SsoClient\Contracts;

interface DashboardDataProvider
{
    /**
     * Return dashboard widget data for the given user and company.
     *
     * @return array<string, mixed>
     */
    public function getData(int $ssoUserId, int $ssoCompanyId): array;
}
