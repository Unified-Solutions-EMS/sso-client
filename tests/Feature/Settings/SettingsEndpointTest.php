<?php

namespace Unified\SsoClient\Tests\Feature\Settings;

use Unified\SsoClient\Contracts\SettingsProvider;
use Unified\SsoClient\Settings\SettingsActorSource;
use Unified\SsoClient\Tests\Fixtures\FakeSettingsProvider;
use Unified\SsoClient\Tests\TestCase;

class SettingsEndpointTest extends TestCase
{
    private FakeSettingsProvider $provider;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.core_api_key', 'core-key');
        $app['config']->set('sso.app_slug', 'cad');
    }

    private function bindProvider(): FakeSettingsProvider
    {
        $this->provider = new FakeSettingsProvider;
        $this->app->instance(SettingsProvider::class, $this->provider);

        return $this->provider;
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function body(array $patch): array
    {
        return ['patch' => $patch, 'actor' => ['sso_user_id' => 7, 'name' => 'Dana Admin', 'source' => 'sso']];
    }

    public function test_routes_require_the_core_api_key(): void
    {
        $this->bindProvider();

        $this->getJson('/api/internal/settings/42')->assertUnauthorized();
        $this->withToken('wrong')->patchJson('/api/internal/settings/42', $this->body([]))->assertUnauthorized();
    }

    public function test_get_without_provider_is_a_normal_unsupported_answer(): void
    {
        $this->withToken('core-key')->getJson('/api/internal/settings/42')
            ->assertOk()
            ->assertExactJson(['app_slug' => 'cad', 'supported' => false, 'schema' => null, 'values' => null]);
    }

    public function test_patch_without_provider_is_unsupported(): void
    {
        $this->withToken('core-key')->patchJson('/api/internal/settings/42', $this->body(['alerts.pre_pickup' => true]))
            ->assertOk()
            ->assertJson(['app_slug' => 'cad', 'supported' => false])
            ->assertJsonPath('results', []);
    }

    public function test_get_returns_schema_and_values_with_defaults_filled(): void
    {
        $this->bindProvider()->stored[42] = ['alerts.pre_pickup' => true, 'not.in.schema' => 'x'];

        $response = $this->withToken('core-key')->getJson('/api/internal/settings/42')->assertOk();

        $response->assertJsonPath('app_slug', 'cad')
            ->assertJsonPath('supported', true)
            ->assertJsonPath('schema.groups.0.key', 'dispatch')
            ->assertJsonPath('schema.groups.1.settings.1.danger', true);

        $values = $response->json('values');
        $this->assertTrue($values['alerts.pre_pickup']);
        $this->assertSame(15, $values['alerts.pre_pickup_minutes']);
        $this->assertSame('yearly', $values['numbering.reset']);
        $this->assertArrayNotHasKey('not.in.schema', $values);
    }

    public function test_non_numeric_company_id_is_not_routed(): void
    {
        $this->bindProvider();

        $this->withToken('core-key')->getJson('/api/internal/settings/abc')->assertNotFound();
    }

    public function test_patch_reports_per_key_results_with_200(): void
    {
        $provider = $this->bindProvider();
        $provider->blockedKeys = ['dispatch.default_station' => 'Add a station first.'];

        $this->withToken('core-key')->patchJson('/api/internal/settings/42', $this->body([
            'alerts.pre_pickup' => true,
            'alerts.pre_pickup_minutes' => 500,
            'alerts.quiet_start' => '25:99',
            'numbering.reset' => 'monthly',
            'numbering.service_types' => ['ems', 'police'],
            'numbering.prefix' => 'CAD',
            'dispatch.default_station' => 3,
            'bogus.key' => 'x',
        ]))
            ->assertOk()
            ->assertExactJson([
                'app_slug' => 'cad',
                'supported' => true,
                'results' => [
                    'alerts.pre_pickup' => ['status' => 'saved'],
                    'alerts.pre_pickup_minutes' => ['status' => 'invalid', 'message' => 'The Minutes before pickup field must not be greater than 120.'],
                    'alerts.quiet_start' => ['status' => 'invalid', 'message' => 'The Quiet hours start field must match the format H:i.'],
                    'numbering.reset' => ['status' => 'invalid', 'message' => 'The selected Reset numbering is invalid.'],
                    'numbering.service_types' => ['status' => 'invalid', 'message' => 'The selected Numbered service types is invalid.'],
                    'numbering.prefix' => ['status' => 'saved'],
                    'dispatch.default_station' => ['status' => 'blocked', 'message' => 'Add a station first.'],
                    'bogus.key' => ['status' => 'unknown_key'],
                ],
            ]);

        $this->assertCount(1, $provider->applyCalls);
        $this->assertSame(
            ['alerts.pre_pickup' => true, 'numbering.prefix' => 'CAD', 'dispatch.default_station' => 3],
            $provider->applyCalls[0]['patch'],
        );
        $this->assertSame(42, $provider->applyCalls[0]['company']);
    }

    public function test_actor_is_passed_to_the_provider(): void
    {
        $provider = $this->bindProvider();

        $this->withToken('core-key')->patchJson('/api/internal/settings/42', [
            'patch' => ['alerts.pre_pickup' => false],
            'actor' => ['sso_user_id' => 9, 'name' => 'Setup assistant', 'source' => 'ai'],
        ])->assertOk();

        $actor = $provider->applyCalls[0]['actor'];
        $this->assertSame(9, $actor->ssoUserId);
        $this->assertSame('Setup assistant', $actor->name);
        $this->assertSame(SettingsActorSource::Ai, $actor->source);
    }

    public function test_provider_is_not_called_when_nothing_passes_validation(): void
    {
        $provider = $this->bindProvider();

        $this->withToken('core-key')->patchJson('/api/internal/settings/42', $this->body(['nope' => 1, 'alerts.pre_pickup' => 'maybe']))
            ->assertOk()
            ->assertJsonPath('results.nope.status', 'unknown_key');

        $this->assertSame([], $provider->applyCalls);
    }

    public function test_patch_is_idempotent(): void
    {
        $provider = $this->bindProvider();

        foreach ([1, 2] as $attempt) {
            $this->withToken('core-key')->patchJson('/api/internal/settings/42', $this->body(['numbering.prefix' => 'CAD']))
                ->assertOk()
                ->assertJsonPath('results', ['numbering.prefix' => ['status' => 'saved']]);
        }

        $this->assertSame(['numbering.prefix' => 'CAD'], $provider->stored[42]);
    }

    public function test_null_clears_an_optional_setting(): void
    {
        $this->bindProvider();

        $this->withToken('core-key')->patchJson('/api/internal/settings/42', $this->body(['numbering.prefix' => null]))
            ->assertOk()
            ->assertJsonPath('results', ['numbering.prefix' => ['status' => 'saved']]);
    }

    public function test_key_the_provider_does_not_report_is_blocked_not_saved(): void
    {
        $this->bindProvider()->silentKeys = ['numbering.prefix'];

        $this->withToken('core-key')->patchJson('/api/internal/settings/42', $this->body(['numbering.prefix' => 'CAD']))
            ->assertOk()
            ->assertJsonPath('results', ['numbering.prefix' => ['status' => 'blocked', 'message' => 'The app did not confirm this change.']]);
    }

    public function test_malformed_body_is_422_json(): void
    {
        $this->bindProvider();

        $this->withToken('core-key')->patch('/api/internal/settings/42', ['patch' => 'nope', 'actor' => ['name' => 'X', 'source' => 'robot']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['patch', 'actor.source']);

        $this->withToken('core-key')->patchJson('/api/internal/settings/42', ['patch' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['actor']);
    }

    public function test_danger_flag_passes_through_unchanged_and_does_not_gate_the_patch(): void
    {
        $this->bindProvider();

        $this->withToken('core-key')->getJson('/api/internal/settings/42')
            ->assertJsonPath('schema.groups.1.settings.1.key', 'numbering.reset')
            ->assertJsonPath('schema.groups.1.settings.1.danger', true)
            ->assertJsonPath('schema.groups.0.settings.0.danger', false);

        $this->withToken('core-key')->patchJson('/api/internal/settings/42', $this->body(['numbering.reset' => 'never']))
            ->assertJsonPath('results', ['numbering.reset' => ['status' => 'saved']]);
    }

    public function test_get_masks_secret_values(): void
    {
        $provider = $this->bindProvider();
        $provider->stored[42] = ['integrations.bryx_token' => 'tok_live_secret'];
        $provider->stored[43] = [];

        $response = $this->withToken('core-key')->getJson('/api/internal/settings/42')->assertOk();

        $this->assertSame(['value' => null, 'has_value' => true], $response->json('values')['integrations.bryx_token']);
        $this->assertStringNotContainsString('tok_live_secret', $response->getContent());

        $empty = $this->withToken('core-key')->getJson('/api/internal/settings/43')->json('values');
        $this->assertSame(['value' => null, 'has_value' => false], $empty['integrations.bryx_token']);
    }

    public function test_patch_sets_a_secret_with_a_non_empty_string(): void
    {
        $provider = $this->bindProvider();

        $this->withToken('core-key')->patchJson('/api/internal/settings/42', $this->body(['integrations.bryx_token' => 'tok_new_value']))
            ->assertOk()
            ->assertJsonPath('results', ['integrations.bryx_token' => ['status' => 'saved']]);

        $this->assertSame('tok_new_value', $provider->stored[42]['integrations.bryx_token']);
    }

    public function test_patch_clears_a_secret_with_null(): void
    {
        $provider = $this->bindProvider();
        $provider->stored[42] = ['integrations.bryx_token' => 'tok_live_secret'];

        $this->withToken('core-key')->patchJson('/api/internal/settings/42', $this->body(['integrations.bryx_token' => null]))
            ->assertOk()
            ->assertJsonPath('results', ['integrations.bryx_token' => ['status' => 'saved']]);

        $this->assertNull($provider->stored[42]['integrations.bryx_token']);
    }

    public function test_empty_string_or_absent_secret_leaves_it_unchanged(): void
    {
        $provider = $this->bindProvider();
        $provider->stored[42] = ['integrations.bryx_token' => 'tok_live_secret'];

        $this->withToken('core-key')->patchJson('/api/internal/settings/42', $this->body([
            'integrations.bryx_token' => '',
            'numbering.prefix' => 'CAD',
        ]))
            ->assertOk()
            ->assertJsonPath('results', ['numbering.prefix' => ['status' => 'saved']]);

        $this->withToken('core-key')->patchJson('/api/internal/settings/42', $this->body(['numbering.prefix' => 'EMS']))
            ->assertOk();

        $this->assertSame('tok_live_secret', $provider->stored[42]['integrations.bryx_token']);
        foreach ($provider->applyCalls as $call) {
            $this->assertArrayNotHasKey('integrations.bryx_token', $call['patch']);
        }
    }

    public function test_secret_value_is_still_validated(): void
    {
        $provider = $this->bindProvider();

        $this->withToken('core-key')->patchJson('/api/internal/settings/42', $this->body(['integrations.bryx_token' => 'short']))
            ->assertOk()
            ->assertJsonPath('results', ['integrations.bryx_token' => ['status' => 'invalid', 'message' => 'The Bryx API token field must be at least 8 characters.']]);

        $this->assertSame([], $provider->applyCalls);
    }
}
