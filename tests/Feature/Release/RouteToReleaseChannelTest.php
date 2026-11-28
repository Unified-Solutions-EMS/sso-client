<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature\Release;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Unified\SsoClient\Middleware\EnforceSsoSessionActions;
use Unified\SsoClient\Middleware\RouteToReleaseChannel;
use Unified\SsoClient\Models\SsoSessionAction;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\User;

class RouteToReleaseChannelTest extends ReleaseChannelTestCase
{
    private User $user;

    private Company $stableAgency;

    private Company $betaAgency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->person();
        $this->stableAgency = $this->agency(501);
        $this->betaAgency = $this->agency(502, 'beta');
    }

    public function test_it_runs_after_the_session_actions_in_the_web_group(): void
    {
        $web = app(Kernel::class)->getMiddlewareGroups()['web'];

        $this->assertContains(RouteToReleaseChannel::class, $web);
        $this->assertGreaterThan(
            array_search(EnforceSsoSessionActions::class, $web, true),
            array_search(RouteToReleaseChannel::class, $web, true),
        );
    }

    public function test_a_beta_agency_on_stable_moves_to_beta_with_path_query_and_its_sso_company(): void
    {
        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe?tab=2&q=engine')
            ->assertRedirect(self::BETA.'/probe?tab=2&q=engine&sso_company=502');
    }

    public function test_a_stable_agency_on_beta_moves_to_stable(): void
    {
        config(['sso.release.channel' => 'beta']);

        $this->asAgency($this->user, $this->stableAgency)
            ->get(self::BETA.'/probe')
            ->assertRedirect(self::STABLE.'/probe?sso_company=501');
    }

    public function test_an_agency_on_this_deployments_channel_is_served(): void
    {
        $this->asAgency($this->user, $this->stableAgency)->get(self::STABLE.'/probe')->assertOk()->assertSee('served');

        config(['sso.release.channel' => 'beta']);

        $this->asAgency($this->user, $this->betaAgency)->get(self::BETA.'/probe')->assertOk()->assertSee('served');
    }

    public function test_an_agency_without_an_sso_id_moves_without_the_parameter(): void
    {
        $unlinked = Company::query()->create(['name' => 'Unlinked', 'release_channel' => 'beta']);

        $this->asAgency($this->user, $unlinked)
            ->get(self::STABLE.'/probe?tab=1')
            ->assertRedirect(self::BETA.'/probe?tab=1');
    }

    public function test_background_requests_and_form_posts_are_not_moved(): void
    {
        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe', ['X-Livewire' => 'true'])->assertOk();
        $this->asAgency($this->user, $this->betaAgency)
            ->getJson(self::STABLE.'/probe')->assertOk();
        $this->asAgency($this->user, $this->betaAgency)
            ->withoutMiddleware(VerifyCsrfToken::class)
            ->post(self::STABLE.'/probe')->assertOk();
    }

    public function test_an_inertia_visit_gets_a_full_reload_to_the_other_deployment(): void
    {
        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe?tab=3', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', self::BETA.'/probe?tab=3&sso_company=502');
    }

    public function test_api_assistant_health_and_sign_in_paths_are_not_moved(): void
    {
        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/api/probe')->assertOk();
        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/assistant/probe')->assertOk();

        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/auth/sso/callback?state=nope')
            ->assertRedirect()
            ->assertRedirectContains('/login');
    }

    public function test_staff_admin_pages_never_move_in_either_direction(): void
    {
        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/admin')->assertOk()->assertSee('admin');
        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/admin/companies?page=2')->assertOk();

        config(['sso.release.channel' => 'beta']);

        $this->asAgency($this->user, $this->stableAgency)->get(self::BETA.'/admin')->assertOk();
        $this->asAgency($this->user, $this->stableAgency)->get(self::BETA.'/admin/companies')->assertOk();
    }

    public function test_the_never_moved_list_is_configurable(): void
    {
        config(['sso.release.never_move' => ['reports/*']]);

        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/reports/staff')->assertOk();
        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/admin')
            ->assertRedirect(self::BETA.'/admin?sso_company=502');
    }

    public function test_a_guest_is_served(): void
    {
        $this->get(self::STABLE.'/probe')->assertOk();
    }

    public function test_a_signed_in_person_with_no_agency_selected_is_served(): void
    {
        $this->actingAs($this->user)->get(self::STABLE.'/probe')->assertOk();
    }

    public function test_the_alpha_deployment_never_moves_anyone(): void
    {
        config(['sso.release.channel' => 'alpha']);

        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/probe')->assertOk();
        $this->asAgency($this->user, $this->stableAgency)->get(self::STABLE.'/probe')->assertOk();
    }

    public function test_without_a_beta_address_nothing_moves(): void
    {
        config(['sso.release.urls.beta' => null]);

        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/probe')->assertOk();
    }

    public function test_a_target_on_this_same_host_is_served_here(): void
    {
        config(['sso.release.urls.beta' => self::STABLE]);

        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/probe')->assertOk();
    }

    public function test_moving_an_agency_to_beta_moves_its_next_page_load(): void
    {
        $this->asAgency($this->user, $this->stableAgency)->get(self::STABLE.'/probe')->assertOk();

        $this->stableAgency->forceFill(['release_channel' => 'beta'])->save();

        $this->get(self::STABLE.'/probe')->assertRedirect(self::BETA.'/probe?sso_company=501');
    }

    public function test_impersonation_into_a_beta_agency_moves_the_same_request(): void
    {
        SsoSessionAction::create([
            'user_id' => $this->user->id,
            'action' => SsoSessionAction::ACTION_SET_COMPANY,
            'payload' => ['company_id' => $this->betaAgency->id],
            'expires_at' => now()->addHour(),
        ]);

        $this->asAgency($this->user, $this->stableAgency)
            ->get(self::STABLE.'/probe')
            ->assertRedirect(self::BETA.'/probe?sso_company=502');
    }

    public function test_a_browser_bouncing_between_deployments_stops_being_moved(): void
    {
        $this->asAgency($this->user, $this->betaAgency);

        for ($i = 0; $i < RouteToReleaseChannel::MAX_MOVES; $i++) {
            $this->get(self::STABLE.'/probe')->assertRedirect(self::BETA.'/probe?sso_company=502');
        }

        $this->get(self::STABLE.'/probe')->assertOk();
    }
}
