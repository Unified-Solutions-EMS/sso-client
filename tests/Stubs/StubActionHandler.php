<?php

namespace Unified\SsoClient\Tests\Stubs;

use Unified\SsoClient\Contracts\SsoActionHandler;
use Unified\SsoClient\Http\ActionResponse;

class StubActionHandler implements SsoActionHandler
{
    /** @var array<string, mixed>|ActionResponse */
    public static array|ActionResponse $result = [];

    /** @var array<string, mixed>|null */
    public static ?array $receivedPayload = null;

    public function handle(array $payload): array|ActionResponse
    {
        static::$receivedPayload = $payload;

        return static::$result;
    }
}
