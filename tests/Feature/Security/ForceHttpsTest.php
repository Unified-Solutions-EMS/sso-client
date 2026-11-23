<?php

namespace Unified\SsoClient\Tests\Feature\Security;

use Illuminate\Support\Facades\URL;
use Unified\SsoClient\Tests\TestCase;

class ForceHttpsTest extends TestCase
{
    private ?string $env = null;

    private mixed $force = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($this->env !== null) {
            $app['env'] = $this->env;
        }
        $app['config']->set('app.url', 'http://app.example.test');
        $app['config']->set('security-headers.force_https', $this->force);
    }

    private function boot(?string $env, mixed $force): void
    {
        $this->env = $env;
        $this->force = $force;
        URL::forceScheme(null);
        $this->refreshApplication();
    }

    public function test_urls_are_not_forced_outside_production(): void
    {
        $this->boot('local', null);

        $this->assertStringStartsWith('http://', url('/x'));
    }

    public function test_urls_are_forced_to_https_in_production(): void
    {
        $this->boot('production', null);

        $this->assertStringStartsWith('https://', url('/x'));
    }

    public function test_a_live_staging_environment_can_opt_in(): void
    {
        $this->boot('staging', 'true');

        $this->assertStringStartsWith('https://', url('/x'));
    }

    public function test_production_can_opt_out(): void
    {
        $this->boot('production', 'false');

        $this->assertStringStartsWith('http://', url('/x'));
    }
}
