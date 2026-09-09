<?php

namespace Unified\SsoClient\Tests\Stubs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class StubRunTrialPurger implements ShouldQueue
{
    use Dispatchable;

    public function __construct(public mixed $company, public string $adminSsoId) {}

    public function handle(): void {}
}
