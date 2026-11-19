<?php

namespace Unified\SsoClient\Tests\Feature\Assistant;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Unified\SsoClient\Assistant\AssistantVisitor;
use Unified\SsoClient\Contracts\AssistantToolProvider;
use Unified\SsoClient\Tests\Fixtures\FakeVisitorAssistantToolProvider;

/**
 * The Assistant on the public website (sso.assistant.visitor): guests get the
 * drawer and are stamped as an anonymous visitor from the encrypted
 * `unified_visitor` cookie, and SSO can run the website's visitor tools.
 */
class AssistantVisitorTest extends AssistantTestCase
{
    private const VISITOR = '0b6d6f3e-6f0a-4c39-9a51-7d2f1c2e9a10';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.app_slug', 'public-website');
        $app['config']->set('sso.assistant.middleware', ['web', 'auth']);
    }

    private function visitorMode(bool $on = true): void
    {
        config()->set('sso.assistant.visitor', $on);
    }

    private function fakeSso(): void
    {
        Http::fake(['https://sso.test/api/internal/assistant/*' => Http::response(['enabled' => true, 'reach' => 'visitor'], 200)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentQuery(): array
    {
        $sent = Http::recorded()->first();
        $this->assertNotNull($sent);
        parse_str((string) parse_url($sent[0]->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_visitor_mode_is_off_by_default_and_guests_are_refused_as_before(): void
    {
        $this->fakeSso();

        $this->assertFalse(config('sso.assistant.visitor'));
        $this->getJson('/assistant/bootstrap')->assertUnauthorized()->assertCookieMissing(AssistantVisitor::COOKIE);
        $this->assertSame('', trim(Blade::render('<x-unified-assistant />')));

        Http::assertNothingSent();
    }

    public function test_a_guest_gets_a_visitor_id_minted_into_an_encrypted_cookie_and_stamped(): void
    {
        $this->visitorMode();
        $this->fakeSso();

        $response = $this->getJson('/assistant/bootstrap')->assertOk()->assertJsonPath('reach', 'visitor');

        $cookie = $response->getCookie(AssistantVisitor::COOKIE, false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertGreaterThan(now()->addDays(364)->getTimestamp(), $cookie->getExpiresTime());
        $this->assertNotSame($cookie->getValue(), $response->getCookie(AssistantVisitor::COOKIE)?->getValue(), 'the cookie is encrypted');

        $minted = (string) $response->getCookie(AssistantVisitor::COOKIE)?->getValue();
        $this->assertTrue(Str::isUuid($minted));

        $query = $this->sentQuery();
        $this->assertSame($minted, $query['visitor_id']);
        $this->assertSame('public-website', $query['app_slug']);
        $this->assertSame('0', $query['impersonating']);
        $this->assertArrayNotHasKey('sso_user_id', $query);
        $this->assertArrayNotHasKey('sso_company_id', $query);
    }

    public function test_the_cookie_id_is_reused_and_not_reissued(): void
    {
        $this->visitorMode();
        $this->fakeSso();

        $this->withCredentials()->withCookie(AssistantVisitor::COOKIE, self::VISITOR)
            ->getJson('/assistant/bootstrap')
            ->assertOk()
            ->assertCookieMissing(AssistantVisitor::COOKIE);

        $this->assertSame(self::VISITOR, $this->sentQuery()['visitor_id']);
    }

    public function test_a_cookie_that_is_not_a_uuid_is_replaced(): void
    {
        $this->visitorMode();
        $this->fakeSso();

        $response = $this->withCredentials()->withCookie(AssistantVisitor::COOKIE, 'not-a-uuid')->getJson('/assistant/bootstrap')->assertOk();

        $minted = (string) $response->getCookie(AssistantVisitor::COOKIE)?->getValue();
        $this->assertTrue(Str::isUuid($minted));
        $this->assertSame($minted, $this->sentQuery()['visitor_id']);
    }

    public function test_the_body_cannot_set_or_override_identity(): void
    {
        $this->visitorMode();
        $this->fakeSso();

        $this->withCredentials()->withCookie(AssistantVisitor::COOKIE, self::VISITOR)->postJson('/assistant/messages', [
            'message' => 'What does CloudPCR cost?',
            'visitor_id' => '11111111-1111-4111-8111-111111111111',
            'sso_user_id' => 1,
            'sso_company_id' => 1,
            'impersonating' => true,
            'app_slug' => 'sso',
        ])->assertOk();

        Http::assertSent(function (ClientRequest $request): bool {
            $data = $request->data();

            return $data['visitor_id'] === self::VISITOR
                && $data['app_slug'] === 'public-website'
                && $data['impersonating'] === false
                && ! array_key_exists('sso_user_id', $data)
                && ! array_key_exists('sso_company_id', $data)
                && $data['message'] === 'What does CloudPCR cost?';
        });
    }

    public function test_a_visitor_can_add_a_file_with_the_same_limits(): void
    {
        $this->visitorMode();
        $this->fakeSso();

        $this->withCredentials()->withCookie(AssistantVisitor::COOKIE, self::VISITOR)
            ->post('/assistant/uploads', ['file' => UploadedFile::fake()->create('roster.csv', 10, 'text/csv')], ['Accept' => 'application/json'])
            ->assertOk();

        $this->withCredentials()->withCookie(AssistantVisitor::COOKIE, self::VISITOR)
            ->post('/assistant/uploads', ['file' => UploadedFile::fake()->create('huge.pdf', 20481, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        Http::assertSentCount(1);
    }

    public function test_visitors_are_limited_to_sixty_requests_a_minute(): void
    {
        $this->visitorMode();
        $this->fakeSso();

        for ($i = 0; $i < 60; $i++) {
            $this->withCredentials()->withCookie(AssistantVisitor::COOKIE, self::VISITOR)->getJson('/assistant/bootstrap')->assertOk();
        }

        $this->withCredentials()->withCookie(AssistantVisitor::COOKIE, self::VISITOR)->getJson('/assistant/bootstrap')->assertStatus(429);

        Http::assertSentCount(60);
    }

    public function test_a_signed_in_user_keeps_the_member_identity_in_visitor_mode(): void
    {
        $this->visitorMode();
        $this->fakeSso();
        [$user, $company] = $this->seedMember();

        $this->actingAs($user)->withSession(['selected_company_id' => $company->id])
            ->getJson('/assistant/bootstrap')
            ->assertOk()
            ->assertCookieMissing(AssistantVisitor::COOKIE);

        $query = $this->sentQuery();
        $this->assertSame('7', $query['sso_user_id']);
        $this->assertSame('42', $query['sso_company_id']);
        $this->assertArrayNotHasKey('visitor_id', $query);
    }

    public function test_a_signed_in_user_with_no_company_still_gets_204_in_visitor_mode(): void
    {
        $this->visitorMode();
        $this->fakeSso();
        [$user] = $this->seedMember();

        $this->actingAs($user)->getJson('/assistant/bootstrap')->assertNoContent();

        Http::assertNothingSent();
    }

    public function test_the_component_renders_for_a_guest_with_visitor_true(): void
    {
        $this->visitorMode();

        $html = Blade::render('<x-unified-assistant />');

        $this->assertStringContainsString('/assistant/widget.js?v=', $html);
        $this->assertStringContainsString('"visitor":true', $html);
    }

    public function test_the_component_does_not_mark_a_signed_in_user_as_a_visitor(): void
    {
        $this->visitorMode();
        [$user] = $this->seedMember();
        $this->actingAs($user);

        $html = Blade::render('<x-unified-assistant />');

        $this->assertStringContainsString('/assistant/widget.js?v=', $html);
        $this->assertStringNotContainsString('visitor', $html);
    }

    private function bindProvider(): FakeVisitorAssistantToolProvider
    {
        $provider = new FakeVisitorAssistantToolProvider;
        $this->app->instance(AssistantToolProvider::class, $provider);

        return $provider;
    }

    public function test_a_visitor_catalog_lists_only_visitor_tools_and_signs_nobody_in(): void
    {
        $this->visitorMode();
        $provider = $this->bindProvider();

        $this->withToken('core-key')
            ->getJson('/api/internal/assistant/tools?visitor_id='.self::VISITOR)
            ->assertOk()
            ->assertExactJson(['app' => 'public-website', 'tools' => [[
                'name' => 'find_product_info',
                'kind' => 'read',
                'definition' => ['description' => 'Looks up a product.', 'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]],
            ]]]);

        $this->assertTrue($provider->lastActor?->isVisitor());
        $this->assertSame(self::VISITOR, $provider->lastActor?->visitorId);
        $this->assertNull($provider->lastActor?->user);
        $this->assertNull($provider->lastActor?->company);
        $this->assertNull($provider->preparedActor);
        $this->assertNull(Auth::user());
        $this->assertFalse(session()->has('selected_company_id'));
    }

    public function test_a_visitor_runs_a_visitor_tool(): void
    {
        $this->visitorMode();
        $this->bindProvider();

        $this->withToken('core-key')
            ->postJson('/api/internal/assistant/tools/find_product_info', ['visitor_id' => self::VISITOR, 'args' => ['query' => 'pricing']])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('content.visitor', self::VISITOR)
            ->assertJsonPath('content.query', 'pricing');

        $this->assertNull(Auth::user());
    }

    public function test_a_tool_that_needs_a_user_refuses_a_visitor_without_running(): void
    {
        $this->visitorMode();
        $provider = $this->bindProvider();

        $this->withToken('core-key')
            ->postJson('/api/internal/assistant/tools/edit_page', ['visitor_id' => self::VISITOR, 'args' => []])
            ->assertOk()
            ->assertExactJson(['ok' => false, 'message' => 'Sign in to do that.']);

        $this->assertFalse($provider->memberToolRan);
    }

    public function test_visitor_tool_calls_are_refused_when_visitor_mode_is_off(): void
    {
        $this->bindProvider();

        $this->withToken('core-key')
            ->getJson('/api/internal/assistant/tools?visitor_id='.self::VISITOR)
            ->assertOk()
            ->assertExactJson(['app' => 'public-website', 'tools' => []]);

        $this->withToken('core-key')
            ->postJson('/api/internal/assistant/tools/find_product_info', ['visitor_id' => self::VISITOR])
            ->assertNotFound();
    }

    public function test_a_tool_call_needs_a_member_or_a_valid_visitor_id(): void
    {
        $this->visitorMode();
        $this->bindProvider();

        $this->withToken('core-key')->postJson('/api/internal/assistant/tools/find_product_info', ['args' => []])->assertUnprocessable();
        $this->withToken('core-key')->postJson('/api/internal/assistant/tools/find_product_info', ['visitor_id' => 'abc'])->assertUnprocessable();
        $this->withToken('core-key')->getJson('/api/internal/assistant/tools?visitor_id=abc')->assertUnprocessable();
    }
}
