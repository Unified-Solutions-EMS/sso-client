<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature\Release;

use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\Middleware\RouteToReleaseChannel;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\User;

/**
 * Livewire's wire:navigate fetches the next page in the background
 * (`X-Livewire-Navigate`, '' on Livewire 3, '1' on Livewire 4) and swaps it
 * in. A cross-origin redirect fails inside that fetch, so a move answers
 * with a page that makes the browser load the other deployment itself.
 * The press of a link and a hover prefetch send the same request, so
 * neither counts toward the loop breaker nor starts a sign-in.
 */
class LivewireNavigateTest extends ReleaseChannelTestCase
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

    /**
     * @param  array<string, string>  $headers
     */
    private function navigate(string $url, string $value = '', array $headers = []): TestResponse
    {
        return $this->get($url, ['X-Livewire-Navigate' => $value, 'Sec-Fetch-Dest' => 'empty', 'Accept' => '*/*'] + $headers);
    }

    private function assertLoadsInFull(TestResponse $response, string $url): void
    {
        $response->assertOk()
            ->assertHeader(RouteToReleaseChannel::FULL_PAGE_LOAD_HEADER, $url)
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $this->assertStringContainsString('window.location.replace('.json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES).')', (string) $response->getContent());
        $this->assertStringContainsString('<meta http-equiv="refresh" content="'.RouteToReleaseChannel::REFRESH_FALLBACK_SECONDS.';url='.e($url).'">', (string) $response->getContent());
        $this->assertStringContainsString('<a href="'.e($url).'">', (string) $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_click_on_livewire_3_loads_the_other_deployment_in_full(): void
    {
        $this->asAgency($this->user, $this->betaAgency);

        $this->assertLoadsInFull($this->navigate(self::STABLE.'/probe?tab=2'), self::BETA.'/probe?tab=2&sso_company=502');
    }

    public function test_a_click_on_livewire_4_loads_the_other_deployment_in_full(): void
    {
        $this->asAgency($this->user, $this->betaAgency);

        $this->assertLoadsInFull($this->navigate(self::STABLE.'/probe', '1'), self::BETA.'/probe?sso_company=502');
    }

    public function test_a_page_for_an_agency_on_this_deployment_is_served_for_the_swap(): void
    {
        $this->asAgency($this->user, $this->stableAgency)
            ->navigate(self::STABLE.'/probe')
            ->assertOk()
            ->assertSee('served')
            ->assertHeaderMissing(RouteToReleaseChannel::FULL_PAGE_LOAD_HEADER);
    }

    public function test_hover_prefetches_never_count_toward_the_loop_breaker(): void
    {
        $this->asAgency($this->user, $this->betaAgency);

        for ($i = 0; $i < RouteToReleaseChannel::MAX_MOVES * 3; $i++) {
            $this->assertLoadsInFull($this->navigate(self::STABLE.'/probe'), self::BETA.'/probe?sso_company=502');
        }

        $this->assertEmpty(session(RouteToReleaseChannel::MOVES_KEY, []));

        // The full page load the browser makes next is moved (and counted) as usual.
        $this->get(self::STABLE.'/probe', ['Sec-Fetch-Dest' => 'document'])
            ->assertRedirect(self::BETA.'/probe?sso_company=502');
        $this->assertCount(1, session(RouteToReleaseChannel::MOVES_KEY));
    }

    public function test_a_navigate_fetch_never_starts_the_sso_sign_in(): void
    {
        // A guest, and a session with another agency: the page load that
        // follows does the hand-across, the fetch never does.
        $this->assertLoadsInFull($this->navigate(self::STABLE.'/probe?sso_company=502'), self::STABLE.'/probe?sso_company=502');
        $this->assertNull(session(RouteToReleaseChannel::HANDOFF_KEY));

        $this->asAgency($this->user, $this->stableAgency);
        $this->assertLoadsInFull($this->navigate(self::STABLE.'/probe?sso_company=502'), self::STABLE.'/probe?sso_company=502');
        $this->assertNull(session(RouteToReleaseChannel::HANDOFF_KEY));
        $this->assertEmpty(session(RouteToReleaseChannel::MOVES_KEY, []));
    }

    public function test_admin_pages_are_served_for_the_swap(): void
    {
        $this->asAgency($this->user, $this->betaAgency)
            ->navigate(self::STABLE.'/admin')
            ->assertOk()
            ->assertSee('admin');
    }

    public function test_the_address_is_escaped_in_the_page(): void
    {
        $this->asAgency($this->user, $this->betaAgency);

        $response = $this->navigate(self::STABLE.'/probe?q='.rawurlencode('"></script><b>x'));

        $this->assertStringNotContainsString('</script><b>', (string) $response->getContent());
        $this->assertStringNotContainsString('"><b>', (string) $response->getContent());
    }

    public function test_the_script_carries_the_apps_content_security_nonce(): void
    {
        Vite::useCspNonce('n0nce');

        $this->asAgency($this->user, $this->betaAgency)
            ->navigate(self::STABLE.'/probe')
            ->assertSee('<script nonce="n0nce">', false);
    }

    public function test_without_a_beta_address_nothing_changes(): void
    {
        config(['sso.release.urls.beta' => null]);

        $this->asAgency($this->user, $this->betaAgency)
            ->navigate(self::STABLE.'/probe')
            ->assertOk()
            ->assertSee('served');
    }
}
