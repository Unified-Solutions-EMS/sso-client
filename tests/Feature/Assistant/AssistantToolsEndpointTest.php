<?php

namespace Unified\SsoClient\Tests\Feature\Assistant;

use Illuminate\Support\Facades\Auth;
use Unified\SsoClient\Contracts\AssistantToolProvider;
use Unified\SsoClient\Tests\Fixtures\FakeAssistantToolProvider;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\User;

class AssistantToolsEndpointTest extends AssistantTestCase
{
    private function bindProvider(): FakeAssistantToolProvider
    {
        $provider = new FakeAssistantToolProvider;
        $this->app->instance(AssistantToolProvider::class, $provider);

        return $provider;
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(array $extra = []): array
    {
        return ['sso_user_id' => 7, 'sso_company_id' => 42, 'impersonating' => false, ...$extra];
    }

    public function test_routes_require_the_core_api_key(): void
    {
        $this->bindProvider();

        $this->getJson('/api/internal/assistant/tools?sso_user_id=7&sso_company_id=42')->assertUnauthorized();
        $this->withToken('nope')->postJson('/api/internal/assistant/tools/find_shifts', $this->identity())->assertUnauthorized();
    }

    public function test_catalog_without_provider_is_empty(): void
    {
        $this->withToken('core-key')->getJson('/api/internal/assistant/tools?sso_user_id=7&sso_company_id=42')
            ->assertOk()
            ->assertExactJson(['app' => 'crew-scheduling', 'tools' => []]);
    }

    public function test_catalog_is_empty_when_company_is_not_provisioned_here(): void
    {
        $this->bindProvider();
        User::query()->create(['name' => 'Dana', 'sso_id' => '7']);

        $this->withToken('core-key')->getJson('/api/internal/assistant/tools?sso_user_id=7&sso_company_id=42')
            ->assertOk()
            ->assertExactJson(['app' => 'crew-scheduling', 'tools' => []]);
    }

    public function test_catalog_lists_visible_tools_with_kind_and_definition(): void
    {
        $provider = $this->bindProvider();
        $this->seedMember();

        $response = $this->withToken('core-key')
            ->getJson('/api/internal/assistant/tools?sso_user_id=7&sso_company_id=42&impersonating=1')
            ->assertOk()
            ->assertJsonPath('app', 'crew-scheduling')
            ->assertJsonCount(2, 'tools')
            ->assertJsonPath('tools.0.name', 'find_shifts')
            ->assertJsonPath('tools.0.kind', 'read')
            ->assertJsonPath('tools.0.definition.description', 'Finds shifts on a date.')
            ->assertJsonPath('tools.1.name', 'assign_shift')
            ->assertJsonPath('tools.1.kind', 'write');

        $this->assertNotContains('unassign_by_assignment', array_column($response->json('tools'), 'name'));

        $this->assertNotNull($provider->lastActor);
        $this->assertSame(7, $provider->lastActor->ssoUserId);
        $this->assertSame(42, $provider->lastActor->ssoCompanyId);
        $this->assertTrue($provider->lastActor->impersonating);
        $this->assertSame('Dana Admin', $provider->lastActor->user->getAttribute('name'));
        $this->assertSame('Gotham EMS', $provider->lastActor->company->getAttribute('name'));
    }

    public function test_catalog_can_include_hidden_tools(): void
    {
        $this->bindProvider();
        $this->seedMember();

        $this->withToken('core-key')
            ->getJson('/api/internal/assistant/tools?sso_user_id=7&sso_company_id=42&include_hidden=1')
            ->assertOk()
            ->assertJsonCount(3, 'tools')
            ->assertJsonPath('tools.2.name', 'unassign_by_assignment');
    }

    public function test_user_who_is_not_a_member_of_the_company_is_refused(): void
    {
        $this->bindProvider();
        Company::query()->create(['name' => 'Gotham EMS', 'sso_company_id' => '42']);
        User::query()->create(['name' => 'Stranger', 'sso_id' => '7']);

        $this->withToken('core-key')->getJson('/api/internal/assistant/tools?sso_user_id=7&sso_company_id=42')
            ->assertNotFound();

        $this->withToken('core-key')->postJson('/api/internal/assistant/tools/find_shifts', $this->identity(['args' => ['date' => '2026-11-01']]))
            ->assertNotFound()
            ->assertJsonPath('ok', false);
    }

    public function test_execute_prepares_the_actor_and_runs_the_tool(): void
    {
        $provider = $this->bindProvider();
        [$user, $company] = $this->seedMember();

        $this->withToken('core-key')
            ->postJson('/api/internal/assistant/tools/find_shifts', $this->identity(['args' => ['date' => '2026-11-01']]))
            ->assertOk()
            ->assertExactJson(['ok' => true, 'content' => ['shifts' => [['id' => 5, 'name' => 'C shift']]], 'summary' => null, 'undo' => null]);

        $this->assertSame(['date' => '2026-11-01'], $provider->lastArgs);
        $this->assertSame($user->id, $provider->preparedActor?->user->getKey());
        $this->assertSame($company->id, session('selected_company_id'));
        $this->assertTrue(Auth::user()?->is($user));
    }

    public function test_execute_returns_summary_and_undo_for_a_write(): void
    {
        $this->bindProvider();
        $this->seedMember();

        $this->withToken('core-key')
            ->postJson('/api/internal/assistant/tools/assign_shift', $this->identity(['args' => ['shift' => 5, 'person' => 3]]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('summary', 'Assigned Dana Admin to C shift (Paramedic), Sat Nov 1')
            ->assertJsonPath('undo.tool', 'unassign_by_assignment')
            ->assertJsonPath('undo.args.assignment', 77);
    }

    public function test_execute_runs_hidden_tools_so_undo_can_replay_them(): void
    {
        $this->bindProvider();
        $this->seedMember();

        $this->withToken('core-key')
            ->postJson('/api/internal/assistant/tools/unassign_by_assignment', $this->identity(['args' => ['assignment' => 77]]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('content.removed', 77);
    }

    public function test_execute_confirm_path(): void
    {
        $this->bindProvider();
        $this->seedMember();

        $this->withToken('core-key')
            ->postJson('/api/internal/assistant/tools/assign_shift', $this->identity(['args' => ['shift' => 5, 'person' => 3, 'bulk' => true]]))
            ->assertOk()
            ->assertExactJson(['ok' => false, 'needs_confirmation' => true, 'question' => 'This changes 12 shifts. Shall I go ahead?', 'content' => ['count' => 12]]);

        $this->withToken('core-key')
            ->postJson('/api/internal/assistant/tools/assign_shift', $this->identity(['args' => ['shift' => 5, 'person' => 3, 'bulk' => true, 'confirmed' => true]]))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_execute_failed_path(): void
    {
        $this->bindProvider();
        $this->seedMember();

        $this->withToken('core-key')
            ->postJson('/api/internal/assistant/tools/assign_shift', $this->identity(['args' => ['shift' => 5, 'person' => 0]]))
            ->assertOk()
            ->assertExactJson(['ok' => false, 'message' => 'You do not have permission to do that here.']);
    }

    public function test_a_tool_that_throws_becomes_a_plain_failure(): void
    {
        $this->bindProvider();
        $this->seedMember();

        $this->withToken('core-key')
            ->postJson('/api/internal/assistant/tools/assign_shift', $this->identity(['args' => ['shift' => 5, 'person' => 3, 'explode' => true]]))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'That did not go through. Try again in a minute.')
            ->assertJsonMissingPath('errors');
    }

    public function test_unknown_tool_and_missing_provider_are_404(): void
    {
        $this->withToken('core-key')->postJson('/api/internal/assistant/tools/find_shifts', $this->identity())->assertNotFound();

        $this->bindProvider();
        $this->seedMember();

        $this->withToken('core-key')->postJson('/api/internal/assistant/tools/nope', $this->identity())->assertNotFound();
    }

    public function test_malformed_body_is_422(): void
    {
        $this->bindProvider();

        $this->withToken('core-key')->postJson('/api/internal/assistant/tools/find_shifts', ['args' => []])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }
}
