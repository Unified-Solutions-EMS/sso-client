<?php

namespace Unified\SsoClient\Tests\Stubs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class StubRunLegacyCadMigration implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public mixed $company, public string $legacyTenantId) {}

    public function handle(): void {}
}
