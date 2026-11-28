<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature\Release;

use Unified\SsoClient\SsoServiceProvider;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\User;

/**
 * An app's own config/sso.php may set one `release` key; the package's
 * defaults keep applying to the rest. Laravel's mergeConfigFrom() alone
 * would have replaced the whole block.
 */
class ReleaseConfigMergeTest extends ReleaseChannelTestCase
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
     * What happens at boot: the app's config file is loaded (its `release`
     * block replaced the package's, as mergeConfigFrom() leaves it), then
     * the package provider registers.
     *
     * @param  array<string, mixed>  $appRelease
     */
    private function bootWithAppRelease(array $appRelease): void
    {
        config(['sso.release' => $appRelease]);

        $provider = $this->app->getProvider(SsoServiceProvider::class);
        (fn () => $this->mergeReleaseConfig())->call($provider);
    }

    public function test_an_app_that_sets_one_key_keeps_every_other_default(): void
    {
        $this->bootWithAppRelease(['urls' => ['beta' => self::BETA]]);

        $this->assertSame('stable', config('sso.release.channel'));
        $this->assertSame(self::BETA, config('sso.release.urls.beta'));
        $this->assertArrayHasKey('stable', config('sso.release.urls'));
        $this->assertSame('beta', config('sso.release.queues.beta'));
        $this->assertArrayHasKey('stable', config('sso.release.queues'));
        $this->assertSame(['admin', 'admin/*', 'widget', 'widget/*'], config('sso.release.never_move'));
        $this->assertSame([], config('sso.release.never_move_also'));
        $this->assertSame('App\\Models\\Company', config('sso.release.company_model'));
    }

    public function test_never_move_also_adds_to_the_defaults(): void
    {
        $this->bootWithAppRelease(['never_move_also' => ['reports/*']]);
        config(['sso.release.urls' => ['stable' => self::STABLE, 'beta' => self::BETA], 'sso.release.company_model' => Company::class]);

        $this->assertSame(['admin', 'admin/*', 'widget', 'widget/*'], config('sso.release.never_move'));

        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/reports/staff')->assertOk();
        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/admin')->assertOk();
        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/probe')
            ->assertRedirect(self::BETA.'/probe?sso_company=502');
    }

    public function test_a_full_never_move_list_still_replaces_the_default(): void
    {
        $this->bootWithAppRelease(['never_move' => ['reports/*']]);
        config(['sso.release.urls' => ['stable' => self::STABLE, 'beta' => self::BETA], 'sso.release.company_model' => Company::class]);

        $this->assertSame(['reports/*'], config('sso.release.never_move'));

        $this->asAgency($this->user, $this->betaAgency)->get(self::STABLE.'/reports/staff')->assertOk();
        $this->asAgency($this->user, $this->betaAgency)
            ->get(self::STABLE.'/admin')
            ->assertRedirect(self::BETA.'/admin?sso_company=502');
    }

    public function test_a_full_copied_block_is_left_as_the_app_wrote_it(): void
    {
        $copied = [
            'channel' => 'beta',
            'urls' => ['stable' => self::STABLE, 'beta' => self::BETA],
            'queues' => ['stable' => null, 'beta' => 'beta'],
            'never_move' => ['admin', 'admin/*', 'widget', 'widget/*'],
            'company_model' => Company::class,
        ];

        $this->bootWithAppRelease($copied);

        $this->assertEquals($copied + ['never_move_also' => []], config('sso.release'));
    }

    public function test_an_app_without_a_release_block_gets_the_defaults(): void
    {
        $provider = $this->app->getProvider(SsoServiceProvider::class);
        config(['sso.release' => null]);
        (fn () => $this->mergeReleaseConfig())->call($provider);

        $this->assertSame('stable', config('sso.release.channel'));
        $this->assertSame(['admin', 'admin/*', 'widget', 'widget/*'], config('sso.release.never_move'));
    }
}
