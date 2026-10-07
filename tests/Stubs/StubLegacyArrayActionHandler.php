<?php

namespace Unified\SsoClient\Tests\Stubs;

use Unified\SsoClient\Contracts\SsoActionHandler;

/**
 * Declares the pre-ActionResponse `: array` return type, proving existing
 * app handlers still satisfy the widened interface.
 */
class StubLegacyArrayActionHandler implements SsoActionHandler
{
    public function handle(array $payload): array
    {
        return ['ok' => true, 'echo' => $payload['value'] ?? null];
    }
}
