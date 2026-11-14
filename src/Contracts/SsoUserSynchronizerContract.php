<?php

namespace Unified\SsoClient\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface SsoUserSynchronizerContract
{
    /**
     * Synchronize the SSO user payload into local database records.
     *
     * Returns a tuple of [User, Company|null].
     *
     * @param  array  $payload  The normalized user payload from SSO /api/user endpoint.
     * @return array{0: Authenticatable, 1: mixed}
     */
    public function synchronize(array $payload): array;
}
