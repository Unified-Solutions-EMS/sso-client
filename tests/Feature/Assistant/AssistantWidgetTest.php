<?php

namespace Unified\SsoClient\Tests\Feature\Assistant;

use Illuminate\Support\Facades\Blade;
use Unified\SsoClient\Tests\Stubs\Models\User;

class AssistantWidgetTest extends AssistantTestCase
{
    public function test_widget_bundle_is_served_cacheable_with_an_etag(): void
    {
        $response = $this->get('/assistant/widget.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertHeader('Cache-Control', 'max-age=86400, public');

        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('UnifiedAssistant', $response->getContent());

        $this->get('/assistant/widget.js', ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_component_renders_the_script_tag_for_a_signed_in_user(): void
    {
        $user = User::query()->create(['name' => 'Dana', 'sso_id' => '7']);
        $this->actingAs($user);

        $html = Blade::render('<x-unified-assistant />');

        $this->assertStringContainsString('<script src="/assistant/widget.js" defer data-unified-assistant=', $html);
        $this->assertStringContainsString('"bootstrap":"/assistant/bootstrap"', $html);
        $this->assertStringContainsString('"undo":"/assistant/actions/{action}/undo"', $html);
        $this->assertStringContainsString('"csrf"', $html);
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
