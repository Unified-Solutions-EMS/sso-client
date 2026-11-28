<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature\Release;

use Illuminate\Support\Facades\Route;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\User;

/**
 * Livewire 4's hashed endpoint prefix is never moved; areas outside
 * `/admin` stay with `never_move_also`; `sso.release.guard` names the guard
 * whose sign-in counts.
 */
class RouteToReleaseChannelPathsTest extends ReleaseChannelTestCase
{
    private User $user;

    private Company $betaAgency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->person();
        $this->betaAgency = $this->agency(502, 'beta');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.guards.portal', ['driver' => 'session', 'provider' => 'users']);
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        Route::middleware('web')->group(function (): void {
            Route::get('/livewire-bf46fcf1/livewire.js', fn () => 'script');
            Route::get('/livewire-bf46fcf1/preview-file/{name}', fn () => 'preview');
            Route::get('/livewire-demo', fn () => 'page');
            Route::get('/staff', fn () => 'staff');
            Route::get('/staff/agencies', fn () => 'staff');
            Route::get('/portal/claims', fn () => 'portal');
        });
    }

    public function test_livewire_4_endpoints_are_never_moved_even_without_sec_fetch_dest(): void
    {
        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/livewire-bf46fcf1/livewire.js')->assertOk()->assertSee('script');
        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/livewire-bf46fcf1/preview-file/a.png')->assertOk();
        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/livewire-bf46fcf1/livewire.js?sso_company=502')->assertOk();
    }

    public function test_a_page_that_only_starts_with_livewire_is_still_moved(): void
    {
        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/livewire-demo')
            ->assertRedirect(self::BETA.'/livewire-demo?sso_company=502');
    }

    public function test_the_livewire_wildcard_workaround_keeps_working(): void
    {
        config(['sso.release.never_move_also' => ['livewire-*']]);

        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/livewire-demo')->assertOk();
        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/livewire-bf46fcf1/livewire.js')->assertOk();
    }

    public function test_a_staff_area_outside_admin_stays_with_never_move_also(): void
    {
        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/staff')->assertRedirect(self::BETA.'/staff?sso_company=502');

        config(['sso.release.never_move_also' => ['staff', 'staff/*']]);

        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/staff')->assertOk();
        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/staff/agencies')->assertOk();
    }

    public function test_by_default_only_the_default_guard_counts_as_signed_in(): void
    {
        $this->onPortalGuardOnly()
            ->withSession(['selected_company_id' => $this->betaAgency->id])
            ->get(self::STABLE.'/portal/claims')
            ->assertOk()->assertSee('portal');
    }

    public function test_the_guard_option_reads_another_guard(): void
    {
        config(['sso.release.guard' => 'portal']);

        $this->onPortalGuardOnly()
            ->withSession(['selected_company_id' => $this->betaAgency->id])
            ->get(self::STABLE.'/portal/claims')
            ->assertRedirect(self::BETA.'/portal/claims?sso_company=502');
    }

    /**
     * Signed in on the portal guard the way `auth:portal` sees it, without
     * actingAs()'s shouldUse(), which would make portal the default guard
     * (route middleware such as `auth:portal` only does that after the web
     * group has run).
     */
    private function onPortalGuardOnly(): static
    {
        $this->app['auth']->guard('portal')->setUser($this->user);

        return $this;
    }

    public function test_with_the_guard_option_the_default_guard_no_longer_counts(): void
    {
        config(['sso.release.guard' => 'portal']);

        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/probe')->assertOk();
    }
}
