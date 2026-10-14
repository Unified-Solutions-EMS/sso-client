<?php

namespace Unified\SsoClient\Tests\Stubs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class StubRunTrialSeeder implements ShouldQueue
{
    use Dispatchable;

    public function __construct(public mixed $company, public array $userSsoIds, public string $adminSsoId) {}

    public function handle(): void
    {
        app('App\\Services\\TrialDataSeeder')->seed($this->company, $this->userSsoIds, $this->adminSsoId);
    }
}
