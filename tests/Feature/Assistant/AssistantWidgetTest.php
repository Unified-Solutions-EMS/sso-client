<?php

namespace Unified\SsoClient\Tests\Feature\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\Assistant\AssistantBundle;
use Unified\SsoClient\Tests\Stubs\Models\User;

class AssistantWidgetTest extends AssistantTestCase
{
    private const SSO_BUNDLE = 'https://sso.test/assistant/widget.js';

    private const SSO_BODY = '/* from sso */ window.UnifiedAssistant = {};';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_app_serves_the_bundle_fetched_from_sso_with_its_etag(): void
    {
        Http::fake([self::SSO_BUNDLE => Http::response(self::SSO_BODY, 200, ['ETag' => '"sso-etag"'])]);

        $response = $this->get('/assistant/widget.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertHeader('Cache-Control', 'max-age=3600, public')
            ->assertHeader('ETag', '"sso-etag"');

        $this->assertSame(self::SSO_BODY, $response->getContent());
        Http::assertSentCount(1);
    }

    public function test_app_caches_the_sso_bundle_and_answers_304_from_the_cache(): void
    {
        Http::fake([self::SSO_BUNDLE => Http::response(self::SSO_BODY, 200, ['ETag' => '"sso-etag"'])]);

        $this->get('/assistant/widget.js')->assertOk();
        $this->get('/assistant/widget.js')->assertOk()->assertHeader('ETag', '"sso-etag"');
        $this->get('/assistant/widget.js', ['If-None-Match' => '"sso-etag"'])
            ->assertStatus(304)
            ->assertHeader('Cache-Control', 'max-age=3600, public');

        Http::assertSentCount(1);
    }

    public function test_app_computes_an_etag_when_sso_sends_none(): void
    {
        Http::fake([self::SSO_BUNDLE => Http::response(self::SSO_BODY, 200)]);

        $this->get('/assistant/widget.js')
            ->assertOk()
            ->assertHeader('ETag', '"'.md5(self::SSO_BODY).'"');
    }

    public function test_app_falls_back_to_the_committed_bundle_when_sso_errors(): void
    {
        Http::fake([self::SSO_BUNDLE => Http::response('Server Error', 500)]);

        $response = $this->get('/assistant/widget.js')->assertOk();

        $this->assertSame(file_get_contents(__DIR__.'/../../../dist/assistant.js'), $response->getContent());
        $this->assertStringContainsString('UnifiedAssistant', $response->getContent());
        $this->get('/assistant/widget.js', ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
    }

    public function test_app_falls_back_to_the_committed_bundle_when_sso_is_unreachable(): void
    {
        Http::fake([self::SSO_BUNDLE => fn () => throw new ConnectionException('Connection refused')]);

        $response = $this->get('/assistant/widget.js')->assertOk();

        $this->assertSame(file_get_contents(__DIR__.'/../../../dist/assistant.js'), $response->getContent());
    }

    public function test_app_falls_back_to_the_committed_bundle_when_outbound_http_is_forbidden(): void
    {
        // App suites that call Http::preventStrayRequests() throw a RuntimeException, not a
        // ConnectionException; the committed copy must still be served.
        Http::preventStrayRequests();

        $response = $this->get('/assistant/widget.js')->assertOk();

        $this->assertSame(file_get_contents(__DIR__.'/../../../dist/assistant.js'), $response->getContent());
    }

    public function test_local_bundle_source_never_calls_sso(): void
    {
        config(['sso.assistant.bundle_source' => 'local']);
        Http::fake();

        $response = $this->get('/assistant/widget.js')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=3600, public');

        $this->assertSame(file_get_contents(__DIR__.'/../../../dist/assistant.js'), $response->getContent());
        $this->get('/assistant/widget.js', ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
        Http::assertNothingSent();
    }

    public function test_the_host_with_proxy_off_serves_its_own_bundle(): void
    {
        config(['sso.assistant.proxy' => false]);
        Http::fake();

        $this->get('/assistant/widget.js')->assertOk();

        Http::assertNothingSent();
    }

    public function test_component_renders_the_script_tag_for_a_signed_in_user(): void
    {
        $user = User::query()->create(['name' => 'Dana', 'sso_id' => '7']);
        $this->actingAs($user);

        $html = Blade::render('<x-unified-assistant />');

        $this->assertMatchesRegularExpression('#<script src="/assistant/widget\.js\?v=[0-9a-f]{12}" defer data-unified-assistant=#', $html);
        $this->assertStringContainsString('"bootstrap":"/assistant/bootstrap"', $html);
        $this->assertStringContainsString('"undo":"/assistant/actions/{action}/undo"', $html);
        $this->assertStringContainsString('"csrf"', $html);
    }

    public function test_component_versions_the_script_url_with_the_local_bundle_hash(): void
    {
        config(['sso.assistant.bundle_source' => 'local']);
        Cache::put(AssistantBundle::CACHE_KEY, ['body' => self::SSO_BODY, 'etag' => '"'.md5(self::SSO_BODY).'"'], 3600);
        $this->actingAs(User::query()->create(['name' => 'Dana', 'sso_id' => '7']));

        $version = substr(md5((string) file_get_contents(__DIR__.'/../../../dist/assistant.js')), 0, 12);

        $this->assertStringContainsString('src="/assistant/widget.js?v='.$version.'"', Blade::render('<x-unified-assistant />'));
    }

    public function test_component_versions_the_script_url_with_the_cached_sso_etag(): void
    {
        $this->actingAs(User::query()->create(['name' => 'Dana', 'sso_id' => '7']));
        $ssoEtag = md5(self::SSO_BODY);
        Cache::put(AssistantBundle::CACHE_KEY, ['body' => self::SSO_BODY, 'etag' => '"'.$ssoEtag.'"'], 3600);

        $this->assertStringContainsString('src="/assistant/widget.js?v='.substr($ssoEtag, 0, 12).'"', Blade::render('<x-unified-assistant />'));
    }

    public function test_component_uses_the_local_hash_until_the_sso_bundle_is_cached(): void
    {
        $this->actingAs(User::query()->create(['name' => 'Dana', 'sso_id' => '7']));
        Http::fake([self::SSO_BUNDLE => Http::response(self::SSO_BODY, 200, ['ETag' => '"'.md5(self::SSO_BODY).'"'])]);

        $local = substr(md5((string) file_get_contents(__DIR__.'/../../../dist/assistant.js')), 0, 12);
        $this->assertStringContainsString('widget.js?v='.$local.'"', Blade::render('<x-unified-assistant />'));
        Http::assertNothingSent();

        $this->get('/assistant/widget.js')->assertOk();

        $this->assertStringContainsString('widget.js?v='.substr(md5(self::SSO_BODY), 0, 12).'"', Blade::render('<x-unified-assistant />'));
    }

    public function test_component_hashes_an_opaque_sso_etag(): void
    {
        $this->actingAs(User::query()->create(['name' => 'Dana', 'sso_id' => '7']));
        Cache::put(AssistantBundle::CACHE_KEY, ['body' => self::SSO_BODY, 'etag' => '"sso-etag"'], 3600);

        $this->assertStringContainsString('widget.js?v='.substr(md5('"sso-etag"'), 0, 12).'"', Blade::render('<x-unified-assistant />'));
    }

    public function test_component_passes_the_setup_app_through(): void
    {
        $this->actingAs(User::query()->create(['name' => 'Dana', 'sso_id' => '7']));

        $html = Blade::render('<x-unified-assistant setup-app="crew-scheduling" />');

        $this->assertStringContainsString('"setup_app":"crew-scheduling"', $html);
        $this->assertStringNotContainsString('setup_app', Blade::render('<x-unified-assistant />'));
    }

    public function test_component_renders_nothing_when_signed_out(): void
    {
        $this->assertSame('', trim(Blade::render('<x-unified-assistant />')));
    }

    public function test_component_renders_nothing_when_disabled(): void
    {
        config(['sso.assistant.enabled' => false]);
        $this->actingAs(User::query()->create(['name' => 'Dana', 'sso_id' => '7']));

        $this->assertSame('', trim(Blade::render('<x-unified-assistant />')));
    }
}
