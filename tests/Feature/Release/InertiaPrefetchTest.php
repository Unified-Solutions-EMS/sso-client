<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature\Release;

use Illuminate\Testing\TestResponse;
use Unified\SsoClient\Middleware\RouteToReleaseChannel;
use Unified\SsoClient\SsoSessionState;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\User;

/**
 * Inertia visits are moved with Inertia's 409 full reload. A prefetch
 * (`Purpose: prefetch`) gets the same answer, which Inertia keeps until the
 * link is clicked, but is never counted. A visit made with old assets is
 * answered by Inertia's own version reload of this same address, so it is
 * not counted either: the full page load that follows is.
 */
class InertiaPrefetchTest extends ReleaseChannelTestCase
{
    private User $user;

    private Company $betaAgency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->person();
        $this->betaAgency = $this->agency(502, 'beta');
    }

    /**
     * Inertia is not a dependency of the package: stand in for the version
     * its middleware sets when it runs around this one.
     */
    private function inertiaVersion(?string $version): void
    {
        $this->app->bind(RouteToReleaseChannel::class, fn ($app) => new class($app->make(SsoSessionState::class), $version) extends RouteToReleaseChannel
        {
            public function __construct(SsoSessionState $state, private ?string $version)
            {
                parent::__construct($state);
            }

            protected function inertiaAssetVersion(): ?string
            {
                return $this->version;
            }
        });
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function visit(array $headers = []): TestResponse
    {
        return $this->get(self::STABLE.'/probe?tab=3', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Sec-Fetch-Dest' => 'empty'] + $headers);
    }

    public function test_a_prefetch_gets_the_full_reload_answer_and_is_never_counted(): void
    {
        $this->asAgency($this->user, $this->betaAgency);

        for ($i = 0; $i <= RouteToReleaseChannel::MAX_MOVES * 2; $i++) {
            $this->visit(['Purpose' => 'prefetch'])
                ->assertStatus(409)
                ->assertHeader('X-Inertia-Location', self::BETA.'/probe?tab=3&sso_company=502');
        }

        $this->assertEmpty(session(RouteToReleaseChannel::MOVES_KEY, []));
    }

    public function test_a_visit_is_counted(): void
    {
        $this->asAgency($this->user, $this->betaAgency)->visit()->assertStatus(409);

        $this->assertCount(1, session(RouteToReleaseChannel::MOVES_KEY));
    }

    public function test_a_visit_with_old_assets_is_not_counted(): void
    {
        $this->inertiaVersion('new-assets');

        $this->asAgency($this->user, $this->betaAgency)
            ->visit(['X-Inertia-Version' => 'old-assets'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', self::BETA.'/probe?tab=3&sso_company=502');

        $this->assertEmpty(session(RouteToReleaseChannel::MOVES_KEY, []));
    }

    public function test_a_visit_with_current_assets_is_counted(): void
    {
        $this->inertiaVersion('same');

        $this->asAgency($this->user, $this->betaAgency)->visit(['X-Inertia-Version' => 'same'])->assertStatus(409);

        $this->assertCount(1, session(RouteToReleaseChannel::MOVES_KEY));
    }

    public function test_before_inertias_middleware_has_run_the_visit_is_counted(): void
    {
        // Version not set yet: this middleware's answer is the one the browser gets.
        $this->inertiaVersion('');

        $this->asAgency($this->user, $this->betaAgency)->visit(['X-Inertia-Version' => 'abc'])->assertStatus(409);

        $this->assertCount(1, session(RouteToReleaseChannel::MOVES_KEY));
    }

    public function test_a_prefetch_for_an_agency_on_this_deployment_is_served(): void
    {
        $stable = $this->agency(501);

        $this->asAgency($this->user, $stable)->visit(['Purpose' => 'prefetch'])->assertOk()->assertSee('served');
    }
}
