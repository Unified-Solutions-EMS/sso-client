<?php

namespace Unified\SsoClient\Tests\Stubs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Unified\SsoClient\Contracts\ReceivesTrialContext;
use Unified\SsoClient\Trial\TrialContext;

/**
 * An app's RunTrialSeeder after the documented four-line opt-in.
 */
class StubContextRunTrialSeeder implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct(public mixed $company, public array $userSsoIds, public string $adminSsoId, public ?TrialContext $context = null) {}

    public function handle(): void
    {
        $seeder = app('App\\Services\\TrialDataSeeder');

        if ($seeder instanceof ReceivesTrialContext) {
            $seeder->withTrialContext($this->context);
        }

        $seeder->seed($this->company, $this->userSsoIds, $this->adminSsoId);
    }
}
