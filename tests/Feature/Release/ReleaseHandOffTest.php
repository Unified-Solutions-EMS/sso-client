<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature\Release;

use Unified\SsoClient\Contracts\ReleaseHandOff;
use Unified\SsoClient\Middleware\RouteToReleaseChannel;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\User;
use Unified\SsoClient\Tests\Stubs\StubReleaseHandOff;

/**
 * `sso.release.hand_off`: an app that holds work in the browser under the
 * address the person is on may keep pages in place and answer the move
 * itself. Unset, every answer is the package's own (the other Release tests
 * run that way; the first cases here pin it explicitly).
 */
class ReleaseHandOffTest extends ReleaseChannelTestCase
{
    private User $user;

    private Company $stableAgency;

    private Company $betaAgency;

    protected function setUp(): void
    {
        parent::setUp();

        StubReleaseHandOff::reset();
        $this->user = $this->person();
        $this->stableAgency = $this->agency(501);
        $this->betaAgency = $this->agency(502, 'beta');
    }

    protected function tearDown(): void
    {
        StubReleaseHandOff::reset();

        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware('web')->get('/charts/edit', fn () => 'chart');
    }

    private function bindHandOff(): void
    {
        config(['sso.release.hand_off' => StubReleaseHandOff::class]);
    }

    public function test_the_default_is_no_hand_off(): void
    {
        $this->assertNull(config('sso.release.hand_off'));
    }

    public function test_without_a_hand_off_every_answer_is_the_packages_own(): void
    {
        $this->asAgency($this->user, $this->betaAgency);

        $this->get(self::STABLE.'/probe')->assertRedirect(self::BETA.'/probe?sso_company=502');
        $this->get(self::STABLE.'/probe', ['X-Livewire-Navigate' => '', 'Sec-Fetch-Dest' => 'empty', 'Accept' => '*/*'])
            ->assertOk()
            ->assertHeader(RouteToReleaseChannel::FULL_PAGE_LOAD_HEADER, self::BETA.'/probe?sso_company=502');
        $this->get(self::STABLE.'/probe', ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', self::BETA.'/probe?sso_company=502');
        $this->assertSame([], StubReleaseHandOff::$calls);
    }

    public function test_a_class_that_is_not_a_hand_off_is_ignored(): void
    {
        config(['sso.release.hand_off' => Company::class]);

        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe')
            ->assertRedirect(self::BETA.'/probe?sso_company=502');
    }

    public function test_a_page_load_is_answered_by_the_app(): void
    {
        $this->bindHandOff();

        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe?tab=2')
            ->assertOk()
            ->assertSee('hand-off redirect to '.self::BETA.'/probe?tab=2&sso_company=502', false);

        $this->assertSame([['kind' => ReleaseHandOff::KIND_REDIRECT, 'target' => self::BETA.'/probe?tab=2&sso_company=502']], StubReleaseHandOff::$calls);
    }

    public function test_a_wire_navigate_fetch_is_answered_by_the_app(): void
    {
        $this->bindHandOff();

        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe', ['X-Livewire-Navigate' => '', 'Sec-Fetch-Dest' => 'empty', 'Accept' => '*/*'])
            ->assertOk()
            ->assertHeader('X-Stub-Hand-Off', self::BETA.'/probe?sso_company=502')
            ->assertHeaderMissing(RouteToReleaseChannel::FULL_PAGE_LOAD_HEADER);

        $this->assertSame(ReleaseHandOff::KIND_NAVIGATE, StubReleaseHandOff::$calls[0]['kind']);
    }

    public function test_an_inertia_visit_is_answered_by_the_app(): void
    {
        $this->bindHandOff();

        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe', ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Stub-Hand-Off', self::BETA.'/probe?sso_company=502')
            ->assertHeaderMissing('X-Inertia-Location');

        $this->assertSame(ReleaseHandOff::KIND_INERTIA, StubReleaseHandOff::$calls[0]['kind']);
    }

    public function test_a_null_answer_falls_back_to_the_packages_own(): void
    {
        $this->bindHandOff();
        StubReleaseHandOff::$answer = false;

        $this->asAgency($this->user, $this->betaAgency);

        $this->get(self::STABLE.'/probe')->assertRedirect(self::BETA.'/probe?sso_company=502');
        $this->get(self::STABLE.'/probe', ['X-Livewire-Navigate' => '', 'Sec-Fetch-Dest' => 'empty', 'Accept' => '*/*'])
            ->assertHeader(RouteToReleaseChannel::FULL_PAGE_LOAD_HEADER, self::BETA.'/probe?sso_company=502');
        $this->get(self::STABLE.'/probe', ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', self::BETA.'/probe?sso_company=502');
    }

    public function test_a_page_the_app_keeps_in_place_is_served_here_in_every_form(): void
    {
        $this->bindHandOff();
        StubReleaseHandOff::$keep = ['charts/*'];

        $this->asAgency($this->user, $this->betaAgency);

        $this->get(self::STABLE.'/charts/edit')->assertOk()->assertSee('chart');
        $this->get(self::STABLE.'/charts/edit', ['Sec-Fetch-Dest' => 'iframe'])->assertOk()->assertSee('chart');
        $this->get(self::STABLE.'/charts/edit', ['X-Livewire-Navigate' => '', 'Sec-Fetch-Dest' => 'empty', 'Accept' => '*/*'])->assertOk()->assertSee('chart');
        $this->assertSame([], StubReleaseHandOff::$calls);

        $this->get(self::STABLE.'/probe')->assertSee('hand-off redirect', false);
    }

    public function test_a_kept_page_does_not_count_toward_the_loop_breaker(): void
    {
        $this->bindHandOff();
        StubReleaseHandOff::$keep = ['charts/*'];

        $this->asAgency($this->user, $this->betaAgency);

        for ($i = 0; $i < RouteToReleaseChannel::MAX_MOVES + 2; $i++) {
            $this->get(self::STABLE.'/charts/edit')->assertSee('chart');
        }

        $this->get(self::STABLE.'/probe')->assertSee('hand-off redirect', false);
    }

    public function test_the_app_is_only_asked_about_people_who_would_be_moved(): void
    {
        $this->bindHandOff();

        $this->asAgency($this->user, $this->stableAgency)->get(self::STABLE.'/probe')->assertSee('served');
        $this->get(self::STABLE.'/admin')->assertSee('admin');

        $this->assertSame([], StubReleaseHandOff::$keepAsked);
        $this->assertSame([], StubReleaseHandOff::$calls);
    }

    public function test_answers_from_the_app_still_count_toward_the_loop_breaker(): void
    {
        $this->bindHandOff();
        $this->asAgency($this->user, $this->betaAgency);

        for ($i = 0; $i < RouteToReleaseChannel::MAX_MOVES; $i++) {
            $this->get(self::STABLE.'/probe')->assertSee('hand-off redirect', false);
        }

        $this->get(self::STABLE.'/probe')->assertOk()->assertSee('served');
    }

    public function test_a_launch_link_for_the_selected_agency_on_the_other_channel_goes_through_the_app(): void
    {
        $this->bindHandOff();

        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe?tab=4&sso_company=502')
            ->assertOk()
            ->assertSee('hand-off redirect to '.self::BETA.'/probe?tab=4&sso_company=502', false);
    }

    public function test_the_reverse_direction_is_answered_by_the_app_too(): void
    {
        config(['sso.release.channel' => 'beta']);
        $this->bindHandOff();

        $this->asAgency($this->user, $this->stableAgency)
            ->get(self::BETA.'/probe')
            ->assertOk()
            ->assertSee('hand-off redirect to '.self::STABLE.'/probe?sso_company=501', false);
    }

    public function test_nothing_is_asked_while_routing_is_not_active(): void
    {
        config(['sso.release.urls.beta' => null]);
        $this->bindHandOff();

        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/probe')->assertSee('served');

        $this->assertSame([], StubReleaseHandOff::$keepAsked);
    }
}
