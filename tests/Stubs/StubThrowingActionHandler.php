<?php

namespace Unified\SsoClient\Tests\Stubs;

use RuntimeException;
use Unified\SsoClient\Contracts\SsoActionHandler;

class StubThrowingActionHandler implements SsoActionHandler
{
    public function handle(array $payload): array
    {
        throw new RuntimeException('SQLSTATE[42S02] secret internals for patient Jane Doe');
    }
}
