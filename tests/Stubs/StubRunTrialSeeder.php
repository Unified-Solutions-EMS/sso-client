<?php

namespace Unified\SsoClient\Tests\Stubs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class StubRunTrialSeeder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public mixed $company, public array $userSsoIds, public string $adminSsoId) {}

    public function handle(): void
    {
        app('App\\Services\\TrialDataSeeder')->seed($this->company, $this->userSsoIds, $this->adminSsoId);
    }
}
