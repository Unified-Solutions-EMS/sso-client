<?php

namespace Unified\SsoClient\Tests\Feature\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Unified\SsoClient\Tests\Stubs\Models\User;

class AssistantProxyTest extends AssistantTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The package's default proxy middleware is ['web', 'auth']; Testbench
        // has no login route, so a signed-out request is asserted on the 401
        // JSON path by sending Accept: application/json.
        $app['config']->set('sso.assistant.middleware', ['web', 'auth']);
    }

    private function fakeSso(): void
    {
        Http::fake(['https://sso.test/api/internal/assistant/*' => Http::response(['reply' => 'Done.', 'actions' => []], 200)]);
    }

    private function signedInMember(): User
    {
        [$user, $company] = $this->seedMember();
        $this->actingAs($user)->withSession(['selected_company_id' => $company->id]);

        return $user;
    }

    public function test_routes_are_registered_only_when_enabled_and_proxying(): void
    {
        $this->assertTrue(Route::has('sso.assistant.bootstrap'));
        $this->assertTrue(Route::has('sso.assistant.widget'));
        $this->assertTrue(Route::has('sso.assistant.tools'));
    }

    public function test_signed_out_request_is_refused(): void
    {
        $this->fakeSso();

        $this->getJson('/assistant/bootstrap')->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_user_without_sso_id_or_company_gets_204_and_nothing_is_sent(): void
    {
        $this->fakeSso();

        $user = User::query()->create(['name' => 'Local Only']);
        $this->actingAs($user)->withSession(['selected_company_id' => 1])->getJson('/assistant/bootstrap')->assertNoContent();

        $this->flushSession();
        [$member] = $this->seedMember();
        $this->actingAs($member)->getJson('/assistant/bootstrap')->assertNoContent();

        Http::assertNothingSent();
    }

    public function test_bootstrap_stamps_identity_from_the_session(): void
    {
        $this->fakeSso();
        $this->signedInMember();

        $this->getJson('/assistant/bootstrap')->assertOk()->assertJsonPath('reply', 'Done.');

        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://sso.test/api/internal/assistant/conversation')
                && $request->hasHeader('Authorization', 'Bearer core-key')
                && $query['sso_user_id'] === '7'
                && $query['sso_company_id'] === '42'
                && $query['app_slug'] === 'crew-scheduling'
                && $query['impersonating'] === '0';
        });
    }

    public function test_bootstrap_forwards_the_conversation_query_to_reopen_a_thread(): void
    {
        $this->fakeSso();
        $this->signedInMember();

        $this->getJson('/assistant/bootstrap?conversation=31')->assertOk();

        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://sso.test/api/internal/assistant/conversation')
                && $query['conversation'] === '31'
                && $query['sso_user_id'] === '7'
                && $query['sso_company_id'] === '42';
        });
    }

    public function test_bootstrap_without_a_conversation_sends_none(): void
    {
        $this->fakeSso();
        $this->signedInMember();

        $this->getJson('/assistant/bootstrap')->assertOk();

        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ! array_key_exists('conversation', $query);
        });
    }

    public function test_bootstrap_rejects_a_non_numeric_conversation(): void
    {
        $this->fakeSso();
        $this->signedInMember();

        $this->getJson('/assistant/bootstrap?conversation=abc')->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_message_passes_conversation_id_through_while_identity_still_comes_from_the_session(): void
    {
        $this->fakeSso();
        $this->signedInMember();

        $this->postJson('/assistant/messages', [
            'message' => 'Add UPMC Hamot',
            'conversation_id' => 31,
            'sso_user_id' => 999,
            'sso_company_id' => 888,
        ])->assertOk();

        Http::assertSent(function (ClientRequest $request): bool {
            $data = $request->data();

            return $request->url() === 'https://sso.test/api/internal/assistant/turn'
                && $data['conversation_id'] === 31
                && $data['sso_user_id'] === 7
                && $data['sso_company_id'] === 42
                && $data['app_slug'] === 'crew-scheduling';
        });
    }

    public function test_message_without_a_conversation_id_sends_none(): void
    {
        $this->fakeSso();
        $this->signedInMember();

        $this->postJson('/assistant/messages', ['message' => 'hi'])->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => ! array_key_exists('conversation_id', $request->data()));
    }

    public function test_delete_conversation_passes_the_new_thread_bootstrap_shape_back(): void
    {
        $bootstrap = [
            'enabled' => true,
            'conversation' => ['id' => 32, 'title' => null, 'started_at' => '2026-10-30T12:00:00Z', 'messages' => []],
            'conversations' => [['id' => 31, 'title' => 'Add UPMC Hamot', 'last_activity_at' => '2026-10-29T12:00:00Z', 'message_count' => 4]],
            'suggestions' => [['label' => 'Add a person', 'prompt' => 'Add a new person to our agency']],
            'review' => null,
            'notice' => null,
        ];
        Http::fake(['https://sso.test/api/internal/assistant/conversation*' => Http::response($bootstrap, 200)]);
        $this->signedInMember();

        $this->deleteJson('/assistant/conversation')->assertOk()->assertExactJson($bootstrap);

        Http::assertSent(fn (ClientRequest $r): bool => $r->method() === 'DELETE'
            && str_starts_with($r->url(), 'https://sso.test/api/internal/assistant/conversation')
            && $r->data()['sso_user_id'] === 7
            && $r->data()['sso_company_id'] === 42);
    }

    public function test_message_identity_comes_from_the_session_never_the_body(): void
    {
        $this->fakeSso();
        $this->signedInMember();

        $this->postJson('/assistant/messages', [
            'message' => 'Put John on C shift',
            'page' => ['url' => 'https://crew.test/schedule', 'title' => 'Schedule'],
            'sso_user_id' => 999,
            'sso_company_id' => 888,
            'app_slug' => 'sso',
            'impersonating' => true,
        ])->assertOk();

        Http::assertSent(function (ClientRequest $request): bool {
            $data = $request->data();

            return $request->url() === 'https://sso.test/api/internal/assistant/turn'
                && $data['message'] === 'Put John on C shift'
                && $data['page']['title'] === 'Schedule'
                && $data['sso_user_id'] === 7
                && $data['sso_company_id'] === 42
                && $data['app_slug'] === 'crew-scheduling'
                && $data['impersonating'] === false;
        });
    }

    public function test_impersonation_flag_follows_the_session(): void
    {
        $this->fakeSso();
        [$user, $company] = $this->seedMember();

        $this->actingAs($user)
            ->withSession(['selected_company_id' => $company->id, 'impersonator_id' => 1])
            ->postJson('/assistant/messages', ['message' => 'hi'])
            ->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => $request->data()['impersonating'] === true);
    }

    public function test_sso_status_and_body_pass_straight_through(): void
    {
        Http::fake(['https://sso.test/api/internal/assistant/turn' => Http::response(['error' => 'Not an admin here.'], 403)]);
        $this->signedInMember();

        $this->postJson('/assistant/messages', ['message' => 'hi'])
            ->assertForbidden()
            ->assertExactJson(['error' => 'Not an admin here.']);
    }

    public function test_unreachable_sso_is_a_plain_502(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $this->signedInMember();

        $this->postJson('/assistant/messages', ['message' => 'hi'])
            ->assertStatus(502)
            ->assertJsonPath('error', 'The assistant is not reachable right now. Try again in a minute.');
    }

    public function test_undo_review_and_clear_forward_to_the_matching_sso_paths(): void
    {
        $this->fakeSso();
        $this->signedInMember();

        $this->postJson('/assistant/actions/123/undo')->assertOk();
        $this->postJson('/assistant/review/abc/approve', ['plan_hash' => 'h1', 'confirmed_danger' => [2]])->assertOk();
        $this->postJson('/assistant/review/abc/remove', ['plan_hash' => 'h1', 'index' => 3])->assertOk();
        $this->postJson('/assistant/review/abc/set-aside')->assertOk();
        $this->deleteJson('/assistant/conversation')->assertOk();

        Http::assertSent(fn (ClientRequest $r): bool => $r->url() === 'https://sso.test/api/internal/assistant/actions/123/undo' && $r->data()['sso_user_id'] === 7);
        Http::assertSent(fn (ClientRequest $r): bool => $r->url() === 'https://sso.test/api/internal/assistant/review/abc/approve' && $r->data()['plan_hash'] === 'h1' && $r->data()['confirmed_danger'] === [2]);
        Http::assertSent(fn (ClientRequest $r): bool => $r->url() === 'https://sso.test/api/internal/assistant/review/abc/remove' && $r->data()['index'] === 3);
        Http::assertSent(fn (ClientRequest $r): bool => $r->url() === 'https://sso.test/api/internal/assistant/review/abc/set-aside');
        Http::assertSent(fn (ClientRequest $r): bool => $r->method() === 'DELETE' && str_starts_with($r->url(), 'https://sso.test/api/internal/assistant/conversation'));
    }

    public function test_upload_forwards_the_file_as_multipart_with_identity(): void
    {
        $this->fakeSso();
        $this->signedInMember();

        $this->post('/assistant/uploads', ['file' => UploadedFile::fake()->createWithContent('roster.csv', "name,email\nJohn,j@x.test\n")], ['Accept' => 'application/json'])
            ->assertOk();

        Http::assertSent(function (ClientRequest $request): bool {
            if ($request->url() !== 'https://sso.test/api/internal/assistant/uploads' || ! $request->isMultipart()) {
                return false;
            }

            $names = array_column($request->data(), 'name');

            return in_array('file', $names, true)
                && in_array('sso_user_id', $names, true)
                && in_array('sso_company_id', $names, true)
                && in_array('app_slug', $names, true)
                && $request->hasFile('file', "name,email\nJohn,j@x.test\n", 'roster.csv');
        });
    }
}
