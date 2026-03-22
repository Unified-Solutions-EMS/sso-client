<?php

namespace Unified\SsoClient\Contracts;

interface SsoActionHandler
{
    /**
     * Handle an SSO action request.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array;
}
