<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature\Release;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Unified\SsoClient\Middleware\RouteToReleaseChannel;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\User;

/**
 * Only a page (or a frame) is moved or handed across. The browser says
 * what a request is for in `Sec-Fetch-Dest`; without it the old rules
 * decide.
 */
class FetchDestinationTest extends ReleaseChannelTestCase
{
    private User $user;

    private Company $betaAgency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->person();
        $this->agency(501);
        $this->betaAgency = $this->agency(502, 'beta');
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        Route::middleware('web')->group(function (): void {
            Route::get('/widget', fn () => 'widget');
            Route::get('/widget/fleet-dashboard.js', fn () => 'widget script');
        });
    }

    public function test_a_page_load_is_moved(): void
    {
        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe', ['Sec-Fetch-Dest' => 'document'])
            ->assertRedirect(self::BETA.'/probe?sso_company=502');
    }

    public function test_without_the_header_a_plain_get_is_still_moved(): void
    {
        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe')
            ->assertRedirect(self::BETA.'/probe?sso_company=502');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notPages(): array
    {
        return [
            'plain fetch()' => ['empty'],
            'script the dashboard loads' => ['script'],
            'image or file preview' => ['image'],
            'stylesheet' => ['style'],
            'embedded object' => ['object'],
        ];
    }

    #[DataProvider('notPages')]
    public function test_what_is_not_a_page_is_served_here_and_not_counted(string $destination): void
    {
        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe', ['Sec-Fetch-Dest' => $destination])
            ->assertOk()
            ->assertSee('served');

        $this->assertEmpty(session(RouteToReleaseChannel::MOVES_KEY, []));
    }

    #[DataProvider('notPages')]
    public function test_what_is_not_a_page_never_starts_the_hand_across(string $destination): void
    {
        $this->get(self::STABLE.'/probe?sso_company=502', ['Sec-Fetch-Dest' => $destination])
            ->assertOk()
            ->assertSee('served');

        $this->assertNull(session(RouteToReleaseChannel::HANDOFF_KEY));
    }

    public function test_the_header_value_is_read_without_regard_to_case(): void
    {
        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe', ['Sec-Fetch-Dest' => 'Script'])
            ->assertOk();

        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe', ['Sec-Fetch-Dest' => 'DOCUMENT'])
            ->assertRedirect(self::BETA.'/probe?sso_company=502');
    }

    public function test_a_frame_is_moved_to_the_agencys_deployment(): void
    {
        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe', ['Sec-Fetch-Dest' => 'iframe'])
            ->assertRedirect(self::BETA.'/probe?sso_company=502');
    }

    public function test_a_frame_with_the_matching_agency_drops_the_parameter_or_moves(): void
    {
        $stable = Company::query()->where('sso_company_id', '501')->firstOrFail();

        $this->asAgency($this->user, $stable)
            ->get(self::STABLE.'/probe?sso_company=501', ['Sec-Fetch-Dest' => 'iframe'])
            ->assertRedirect(self::STABLE.'/probe');

        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe?sso_company=502', ['Sec-Fetch-Dest' => 'iframe'])
            ->assertRedirect(self::BETA.'/probe?sso_company=502');
    }

    public function test_a_frame_never_goes_through_the_sso_sign_in(): void
    {
        // A guest: SSO's hidden warm-up frame before the person has a session here.
        $this->get(self::STABLE.'/probe?sso_company=502', ['Sec-Fetch-Dest' => 'iframe'])
            ->assertOk()
            ->assertSee('served');
        $this->assertNull(session(RouteToReleaseChannel::HANDOFF_KEY));

        // Signed in with another agency selected.
        $stable = Company::query()->where('sso_company_id', '501')->firstOrFail();

        $this->asAgency($this->user, $stable)
            ->get(self::STABLE.'/probe?sso_company=502', ['Sec-Fetch-Dest' => 'frame'])
            ->assertOk()
            ->assertSee('served');
        $this->assertNull(session(RouteToReleaseChannel::HANDOFF_KEY));
        $this->assertSame($stable->id, session('selected_company_id'));
    }

    public function test_a_page_load_with_another_agency_still_goes_through_the_sso_sign_in(): void
    {
        $this->get(self::STABLE.'/probe?sso_company=502', ['Sec-Fetch-Dest' => 'document'])
            ->assertRedirect('/auth/sso/redirect?intended='.urlencode(self::STABLE.'/probe'));
    }

    public function test_widget_paths_are_never_moved(): void
    {
        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/widget/fleet-dashboard.js')
            ->assertOk()
            ->assertSee('widget script');

        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/widget', ['Sec-Fetch-Dest' => 'document'])
            ->assertOk()
            ->assertSee('widget');
    }

    public function test_a_browser_prefetch_of_a_page_is_moved_but_not_counted(): void
    {
        $this->asAgency($this->user, $this->betaAgency);

        for ($i = 0; $i <= RouteToReleaseChannel::MAX_MOVES; $i++) {
            $this->get(self::STABLE.'/probe', ['Sec-Fetch-Dest' => 'document', 'Sec-Purpose' => 'prefetch'])
                ->assertRedirect(self::BETA.'/probe?sso_company=502');
        }

        $this->assertEmpty(session(RouteToReleaseChannel::MOVES_KEY, []));
    }

    public function test_a_browser_prefetch_never_goes_through_the_sso_sign_in(): void
    {
        $this->get(self::STABLE.'/probe?sso_company=502', ['Sec-Fetch-Dest' => 'document', 'Sec-Purpose' => 'prefetch;prerender'])
            ->assertOk()
            ->assertSee('served');

        $this->assertNull(session(RouteToReleaseChannel::HANDOFF_KEY));
    }
}
