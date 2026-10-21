<?php

namespace Unified\SsoClient\Tests\Feature\Trial;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\StubPlainTrialDataSeeder;
use Unified\SsoClient\Tests\Stubs\StubTrialDataSeeder;
use Unified\SsoClient\Tests\TestCase;
use Unified\SsoClient\Trial\AppTrialJobs;
use Unified\SsoClient\Trial\TrialContext;

/**
 * An app with `App\Services\TrialDataSeeder` and no `App\Jobs\RunTrialSeeder`:
 * AppTrialJobs calls the seeder itself. Separate processes, because the other
 * trial tests alias RunTrialSeeder for the whole run.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class TrialContextDirectSeedTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(AppTrialJobs::SEEDER)) {
            class_alias(StubTrialDataSeeder::class, AppTrialJobs::SEEDER);
        }

        parent::setUp();

        StubTrialDataSeeder::reset();
        StubPlainTrialDataSeeder::$calls = [];
        $this->assertFalse(class_exists(AppTrialJobs::SEEDER_JOB));
    }

    private function company(): Company
    {
        return (new Company)->forceFill(['id' => 5, 'sso_company_id' => '77']);
    }

    public function test_the_seeder_is_handed_the_context_before_it_seeds(): void
    {
        $context = TrialContext::fromArray(require __DIR__.'/../../Fixtures/trial_seed_context.php');

        app(AppTrialJobs::class)->seed($this->company(), ['admin-uuid', 'fake-1'], 'admin-uuid', $context);

        $this->assertCount(1, StubTrialDataSeeder::$calls);
        $this->assertSame($context, StubTrialDataSeeder::$calls[0]['context']);
        $this->assertSame(['admin-uuid', 'fake-1'], StubTrialDataSeeder::$calls[0]['users']);
    }

    public function test_no_context_hands_the_seeder_null(): void
    {
        app(AppTrialJobs::class)->seed($this->company(), [], 'admin-uuid');

        $this->assertSame([null], StubTrialDataSeeder::$contexts);
        $this->assertCount(1, StubTrialDataSeeder::$calls);
    }

    public function test_a_seeder_without_the_interface_still_seeds(): void
    {
        $this->app->bind(AppTrialJobs::SEEDER, StubPlainTrialDataSeeder::class);

        app(AppTrialJobs::class)->seed($this->company(), [], 'admin-uuid', TrialContext::fromArray([]));

        $this->assertCount(1, StubPlainTrialDataSeeder::$calls);
    }
}
