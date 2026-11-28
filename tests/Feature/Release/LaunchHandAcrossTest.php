<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature\Release;

use Illuminate\Support\Facades\Log;
use Mockery;
use Unified\SsoClient\Middleware\RouteToReleaseChannel;
use Unified\SsoClient\Models\SsoSessionAction;
use Unified\SsoClient\SsoClient;
use Unified\SsoClient\SsoSessionState;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\User;

/**
 * SSO opens an app with ?sso_company=<id>. The parameter only ever decides
 * whether a sign-in handshake is needed; the agency always comes from SSO.
 */
class LaunchHandAcrossTest extends ReleaseChannelTestCase
{
    private User $user;

    private Company $agencyA;

    private Company $agencyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->person('900');
        $this->agencyA = $this->agency(10);
        $this->agencyB = $this->agency(20);
    }

    private function signInUrl(string $intended): string
    {
        return '/auth/sso/redirect?intended='.urlencode($intended);
    }

    public function test_a_guest_goes_through_sign_in_with_the_clean_url_as_intended(): void
    {
        $this->get(self::STABLE.'/probe?tab=2&sso_company=20&q=engine')
            ->assertRedirect($this->signInUrl(self::STABLE.'/probe?tab=2&q=engine'));

        $this->assertGuest();
    }

    public function test_the_same_agency_just_drops_the_parameter(): void
    {
        $this->asAgency($this->user, $this->agencyA)
            ->get(self::STABLE.'/probe?sso_company=10&tab=1')
            ->assertRedirect(self::STABLE.'/probe?tab=1');

        $this->assertSame($this->agencyA->id, session('selected_company_id'));
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_a_different_agency_goes_through_sign_in_and_the_parameter_changes_nothing(): void
    {
        $this->asAgency($this->user, $this->agencyA)
            ->get(self::STABLE.'/probe?sso_company=20')
            ->assertRedirect($this->signInUrl(self::STABLE.'/probe'));

        $this->assertSame($this->agencyA->id, session('selected_company_id'));
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_an_agency_the_person_does_not_belong_to_selects_nothing(): void
    {
        $stranger = $this->agency(99);

        $this->asAgency($this->user, $this->agencyA)
            ->get(self::STABLE.'/probe?sso_company=99')
            ->assertRedirect($this->signInUrl(self::STABLE.'/probe'));

        $this->assertSame($this->agencyA->id, session('selected_company_id'));
        $this->assertNotSame($stranger->id, session('selected_company_id'));
    }

    public function test_an_impersonation_already_applied_counts_as_the_same_agency(): void
    {
        SsoSessionAction::create([
            'user_id' => $this->user->id,
            'action' => SsoSessionAction::ACTION_SET_COMPANY,
            'payload' => ['company_id' => $this->agencyB->id],
            'expires_at' => now()->addHour(),
        ]);

        $this->asAgency($this->user, $this->agencyA)
            ->get(self::STABLE.'/probe?sso_company=20')
            ->assertRedirect(self::STABLE.'/probe');

        $this->assertSame($this->agencyB->id, session('selected_company_id'));
    }

    public function test_a_matching_beta_agency_on_stable_is_moved_with_the_parameter(): void
    {
        $this->agencyA->forceFill(['release_channel' => 'beta'])->save();

        $this->asAgency($this->user, $this->agencyA)
            ->get(self::STABLE.'/probe?sso_company=10&tab=4')
            ->assertRedirect(self::BETA.'/probe?tab=4&sso_company=10');
    }

    public function test_admin_pages_still_get_the_hand_across(): void
    {
        $this->asAgency($this->user, $this->agencyA)
            ->get(self::STABLE.'/admin?sso_company=20')
            ->assertRedirect($this->signInUrl(self::STABLE.'/admin'));
    }

    public function test_a_parameter_that_is_not_an_id_is_dropped(): void
    {
        $this->asAgency($this->user, $this->agencyA)
            ->get(self::STABLE.'/probe?sso_company=abc&tab=1')
            ->assertRedirect(self::STABLE.'/probe?tab=1');

        $this->assertSame($this->agencyA->id, session('selected_company_id'));
    }

    public function test_api_and_background_requests_ignore_the_parameter(): void
    {
        $this->asAgency($this->user, $this->agencyA)->get(self::STABLE.'/api/probe?sso_company=20')->assertOk();
        $this->asAgency($this->user, $this->agencyA)->getJson(self::STABLE.'/probe?sso_company=20')->assertOk();
    }

    public function test_when_sso_picks_another_agency_the_same_link_is_not_handed_across_twice(): void
    {
        Log::spy();

        $this->asAgency($this->user, $this->agencyA)
            ->get(self::STABLE.'/probe?sso_company=20')
            ->assertRedirect($this->signInUrl(self::STABLE.'/probe'));

        // SSO answered with agency A again; the same link comes back.
        $this->get(self::STABLE.'/probe?sso_company=20')->assertRedirect(self::STABLE.'/probe');
        $this->get(self::STABLE.'/probe')->assertOk();

        $this->assertSame($this->agencyA->id, session('selected_company_id'));
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message): bool => str_contains($message, 'different agency than the launch link'),
        )->once();
    }

    public function test_an_older_hand_across_does_not_block_a_new_one(): void
    {
        $this->asAgency($this->user, $this->agencyA)
            ->withSession([RouteToReleaseChannel::HANDOFF_KEY => [
                'url' => self::STABLE.'/probe',
                'sso_company' => '20',
                'at' => time() - RouteToReleaseChannel::HANDOFF_WINDOW_SECONDS - 1,
            ]])
            ->get(self::STABLE.'/probe?sso_company=20')
            ->assertRedirect($this->signInUrl(self::STABLE.'/probe'));
    }

    /**
     * UNI-1928: the app session outlived an agency switch in SSO. The launch
     * link now sends the browser through the handshake, and the callback
     * leaves the agency SSO selected in the session.
     */
    public function test_after_the_handshake_the_session_holds_the_agency_sso_selected(): void
    {
        $this->user->companies()->attach([$this->agencyA->id, $this->agencyB->id]);
        $this->fakeSso(selectedSsoCompanyId: 20);

        $this->asAgency($this->user, $this->agencyA)
            ->get(self::STABLE.'/probe?sso_company=20&tab=1')
            ->assertRedirect($this->signInUrl(self::STABLE.'/probe?tab=1'));

        $this->get('/auth/sso/redirect?intended='.urlencode(self::STABLE.'/probe?tab=1'))
            ->assertRedirect('https://sso.test/oauth/authorize?fake=1');

        $this->get('/auth/sso/callback?state=state-1&code=code-1')
            ->assertRedirect(self::STABLE.'/probe?tab=1');

        $this->assertSame($this->agencyB->id, (int) session(SsoSessionState::KEY_SELECTED_COMPANY_ID));
        $this->assertAuthenticatedAs($this->user->fresh());

        $this->get(self::STABLE.'/probe?tab=1')->assertOk();
    }

    public function test_a_handshake_that_returns_a_different_agency_keeps_that_agency_and_does_not_loop(): void
    {
        $this->user->companies()->attach([$this->agencyA->id, $this->agencyB->id]);
        $this->fakeSso(selectedSsoCompanyId: 10);

        $this->asAgency($this->user, $this->agencyA)
            ->get(self::STABLE.'/probe?sso_company=20')
            ->assertRedirect($this->signInUrl(self::STABLE.'/probe'));

        $this->get('/auth/sso/redirect?intended='.urlencode(self::STABLE.'/probe'));
        $this->get('/auth/sso/callback?state=state-1&code=code-1')->assertRedirect(self::STABLE.'/probe');

        $this->assertSame($this->agencyA->id, (int) session(SsoSessionState::KEY_SELECTED_COMPANY_ID));
        $this->get(self::STABLE.'/probe')->assertOk();
    }

    private function fakeSso(int $selectedSsoCompanyId): void
    {
        $client = Mockery::mock(SsoClient::class);
        $client->shouldReceive('buildAuthorizeUrl')->andReturn([
            'url' => 'https://sso.test/oauth/authorize?fake=1',
            'state' => 'state-1',
            'code_verifier' => 'verifier-1',
        ]);
        $client->shouldReceive('exchangeCode')->andReturn([
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires_in' => 3600,
        ]);
        $client->shouldReceive('fetchUser')->andReturn([
            'user' => ['id' => '900', 'email' => 'pat900@example.test', 'displayName' => 'Pat Medic'],
            'companies' => [
                ['id' => 10, 'name' => 'Agency 10', 'roles' => ['User'], 'appRole' => 'User'],
                ['id' => 20, 'name' => 'Agency 20', 'roles' => ['User'], 'appRole' => 'User'],
            ],
            'selectedCompany' => ['id' => $selectedSsoCompanyId, 'name' => "Agency {$selectedSsoCompanyId}"],
            'staffRoles' => [],
        ]);
        $this->app->instance(SsoClient::class, $client);
    }
}
