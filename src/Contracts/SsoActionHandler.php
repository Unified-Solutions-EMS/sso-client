<?php

namespace Unified\SsoClient\Contracts;

use Unified\SsoClient\Http\ActionResponse;

interface SsoActionHandler
{
    /**
     * Handle an SSO action request.
     *
     * Return an array for a 200 (an integer `http_status` key in it sets the
     * status and is stripped from the body), or an ActionResponse.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|ActionResponse
     */
    public function handle(array $payload): array|ActionResponse;
}
