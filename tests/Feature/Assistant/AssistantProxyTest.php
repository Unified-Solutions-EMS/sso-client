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

    public function test_feedback_forwards_the_answer_with_the_session_identity(): void
    {
        Http::fake(['https://sso.test/api/internal/assistant/feedback' => Http::response(['ok' => true, 'id' => 5, 'helpful' => false], 200)]);
        $this->signedInMember();

        $this->postJson('/assistant/feedback', [
            'conversation_id' => 31,
            'message_id' => 12,
            'helpful' => false,
            'sso_user_id' => 999,
        ])->assertOk()->assertJsonPath('id', 5);

        Http::assertSent(function (ClientRequest $request): bool {
            $data = $request->data();

            return $request->url() === 'https://sso.test/api/internal/assistant/feedback'
                && $data['conversation_id'] === 31
                && $data['message_id'] === 12
                && $data['helpful'] === false
                && $data['sso_user_id'] === 7
                && $data['sso_company_id'] === 42;
        });

        $this->postJson('/assistant/feedback', ['conversation_id' => 31])->assertUnprocessable();
    }

    public function test_feedback_follow_up_forwards_to_the_answer_route(): void
    {
        Http::fake(['https://sso.test/api/internal/assistant/feedback/5/follow-up' => Http::response(['ok' => true, 'id' => 5, 'follow_up' => true], 200)]);
        $this->signedInMember();

        $this->postJson('/assistant/feedback/5/follow-up', ['follow_up' => true])->assertOk()->assertJsonPath('follow_up', true);

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://sso.test/api/internal/assistant/feedback/5/follow-up'
            && $request->data()['follow_up'] === true
            && $request->data()['sso_user_id'] === 7);

        $this->postJson('/assistant/feedback/5/follow-up', [])->assertUnprocessable();
        $this->postJson('/assistant/feedback/abc/follow-up', ['follow_up' => true])->assertNotFound();
    }

    public function test_rating_forwards_the_score_and_note_with_the_session_identity(): void
    {
        Http::fake(['https://sso.test/api/internal/assistant/rating' => Http::response(['ok' => true, 'rating' => ['enabled' => true, 'score' => 2, 'note' => 'Slow']], 200)]);
        $this->signedInMember();

        $this->postJson('/assistant/rating', ['conversation_id' => 31, 'score' => 2, 'note' => 'Slow', 'sso_user_id' => 999])
            ->assertOk()
            ->assertJsonPath('rating.score', 2);

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://sso.test/api/internal/assistant/rating'
            && $request->data()['conversation_id'] === 31
            && $request->data()['score'] === 2
            && $request->data()['note'] === 'Slow'
            && $request->data()['sso_user_id'] === 7
            && $request->data()['sso_company_id'] === 42);

        $this->postJson('/assistant/rating', ['conversation_id' => 31, 'score' => 6])->assertUnprocessable();
        $this->postJson('/assistant/rating', ['conversation_id' => 31])->assertUnprocessable();
    }

    public function test_callback_forwards_the_number_and_passes_back_ssos_field_errors(): void
    {
        Http::fake([
            'https://sso.test/api/internal/assistant/callback' => Http::sequence()
                ->push(['enabled' => true, 'support' => ['enabled' => true, 'status' => 'needs_teammate', 'last_message_id' => 50], 'callback' => ['message' => 'Our team will call you at (814) 555-0100 this afternoon. Replies also show up here.']], 200)
                ->push(['message' => 'Enter a US phone number, like (814) 555-0100.', 'errors' => ['phone' => ['Enter a US phone number, like (814) 555-0100.']]], 422),
        ]);
        $this->signedInMember();

        $this->postJson('/assistant/callback', ['conversation_id' => 31, 'phone' => '(814) 555-0100', 'best_time' => 'this_afternoon', 'note' => 'Still broken'])
            ->assertOk()
            ->assertJsonPath('support.status', 'needs_teammate');

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://sso.test/api/internal/assistant/callback'
            && $request->data()['phone'] === '(814) 555-0100'
            && $request->data()['best_time'] === 'this_afternoon'
            && $request->data()['note'] === 'Still broken'
            && $request->data()['sso_user_id'] === 7);

        $this->postJson('/assistant/callback', ['conversation_id' => 31, 'phone' => '555', 'best_time' => 'now'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'Enter a US phone number, like (814) 555-0100.');

        $this->postJson('/assistant/callback', ['conversation_id' => 31, 'phone' => '8145550100', 'best_time' => 'tonight'])->assertUnprocessable();
    }

    public function test_message_forwards_the_attached_file_ids(): void
    {
        $this->fakeSso();
        $this->signedInMember();

        $this->postJson('/assistant/messages', ['message' => 'Here is the error', 'attachments' => [17, 18]])->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://sso.test/api/internal/assistant/turn'
            && $request->data()['attachments'] === [17, 18]
            && $request->data()['sso_user_id'] === 7);

        $this->postJson('/assistant/messages', ['message' => 'x', 'attachments' => ['abc']])->assertUnprocessable();
    }

    public function test_escalate_forwards_the_thread_and_question_with_the_session_identity(): void
    {
        Http::fake(['https://sso.test/api/internal/assistant/escalate' => Http::response(['enabled' => true, 'support' => ['enabled' => true, 'status' => 'needs_teammate', 'last_message_id' => 40]], 200)]);
        $this->signedInMember();

        $this->postJson('/assistant/escalate', ['conversation_id' => 31, 'question' => 'How do I export?', 'sso_user_id' => 999])
            ->assertOk()
            ->assertJsonPath('support.status', 'needs_teammate');

        Http::assertSent(function (ClientRequest $request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://sso.test/api/internal/assistant/escalate'
                && $data['conversation_id'] === 31
                && $data['question'] === 'How do I export?'
                && $data['sso_user_id'] === 7
                && $data['sso_company_id'] === 42
                && $data['app_slug'] === 'crew-scheduling';
        });
    }

    public function test_escalate_forwards_the_message_and_its_files(): void
    {
        Http::fake(['https://sso.test/api/internal/assistant/escalate' => Http::response(['enabled' => true, 'support' => ['enabled' => true, 'status' => 'needs_teammate', 'last_message_id' => 42]], 200)]);
        $this->signedInMember();

        $this->postJson('/assistant/escalate', ['conversation_id' => 31, 'question' => 'The export failed', 'message' => 'The export failed', 'attachments' => [21, 22]])
            ->assertOk();

        Http::assertSent(function (ClientRequest $request): bool {
            $data = $request->data();

            return $request->url() === 'https://sso.test/api/internal/assistant/escalate'
                && $data['message'] === 'The export failed'
                && $data['attachments'] === [21, 22]
                && $data['sso_user_id'] === 7;
        });

        $this->postJson('/assistant/escalate', ['message' => str_repeat('a', 20001)])->assertUnprocessable();
        $this->postJson('/assistant/escalate', ['attachments' => range(1, 11)])->assertUnprocessable();
    }

    public function test_updates_forwards_the_conversation_and_after_and_nothing_else_from_the_browser(): void
    {
        Http::fake(['https://sso.test/api/internal/assistant/updates*' => Http::response(['status' => 'needs_teammate', 'messages' => [], 'last_message_id' => 41], 200)]);
        $this->signedInMember();

        $this->getJson('/assistant/updates?conversation=31&after=40&sso_user_id=999')->assertOk()->assertJsonPath('last_message_id', 41);

        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://sso.test/api/internal/assistant/updates')
                && $query['conversation'] === '31'
                && $query['after'] === '40'
                && $query['sso_user_id'] === '7'
                && $query['sso_company_id'] === '42';
        });

        $this->getJson('/assistant/updates')->assertUnprocessable();
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

    public function test_choose_forwards_the_picked_code_or_null_to_leave_the_row_out(): void
    {
        $this->fakeSso();
        $this->signedInMember();

        $this->postJson('/assistant/review/abc/choose', ['plan_hash' => 'h1', 'index' => 4, 'code' => '7052'])->assertOk();
        $this->postJson('/assistant/review/abc/choose', ['plan_hash' => 'h2', 'index' => 5, 'code' => null])->assertOk();
        $this->postJson('/assistant/review/abc/choose', ['plan_hash' => 'h3', 'index' => 6])->assertUnprocessable();

        Http::assertSent(fn (ClientRequest $r): bool => $r->url() === 'https://sso.test/api/internal/assistant/review/abc/choose'
            && $r->data()['plan_hash'] === 'h1' && $r->data()['index'] === 4 && $r->data()['code'] === '7052' && $r->data()['sso_user_id'] === 7);
        Http::assertSent(fn (ClientRequest $r): bool => $r->url() === 'https://sso.test/api/internal/assistant/review/abc/choose'
            && $r->data()['plan_hash'] === 'h2' && array_key_exists('code', $r->data()) && $r->data()['code'] === null);
        Http::assertSentCount(2);
    }

    public function test_dismiss_intro_forwards_with_identity_from_the_session_never_the_body(): void
    {
        Http::fake(['https://sso.test/api/internal/assistant/intro/dismiss' => Http::response(['ok' => true], 200)]);
        $this->signedInMember();

        $this->postJson('/assistant/intro/dismiss', ['sso_user_id' => 999, 'sso_company_id' => 888, 'app_slug' => 'sso'])
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        Http::assertSent(fn (ClientRequest $r): bool => $r->method() === 'POST'
            && $r->url() === 'https://sso.test/api/internal/assistant/intro/dismiss'
            && $r->hasHeader('Authorization', 'Bearer core-key')
            && $r->data()['sso_user_id'] === 7
            && $r->data()['sso_company_id'] === 42
            && $r->data()['app_slug'] === 'crew-scheduling'
            && $r->data()['impersonating'] === false);
    }

    public function test_dismiss_intro_is_204_without_a_vouched_session_and_401_signed_out(): void
    {
        $this->fakeSso();

        $this->postJson('/assistant/intro/dismiss')->assertUnauthorized();

        [$member] = $this->seedMember();
        $this->actingAs($member)->postJson('/assistant/intro/dismiss')->assertNoContent();

        Http::assertNothingSent();
    }

    public function test_upload_forwards_the_file_as_multipart_with_identity(): void
    {
        $this->fakeSso();
        $this->signedInMember();

        $this->post('/assistant/uploads', [
            'file' => UploadedFile::fake()->createWithContent('roster.csv', "name,email\nJohn,j@x.test\n"),
            'setup_app' => 'cloudpcr',
            'page' => ['url' => 'https://crew.test/setup', 'title' => 'Setup'],
        ], ['Accept' => 'application/json'])
            ->assertOk();

        Http::assertSent(function (ClientRequest $request): bool {
            if ($request->url() !== 'https://sso.test/api/internal/assistant/uploads' || ! $request->isMultipart()) {
                return false;
            }

            $fields = collect($request->data())->mapWithKeys(fn (array $part): array => [$part['name'] => $part['contents'] ?? null]);

            return $fields->has(['file', 'sso_user_id', 'sso_company_id', 'app_slug'])
                && $fields->get('setup_app') === 'cloudpcr'
                && $fields->get('page[url]') === 'https://crew.test/setup'
                && $fields->get('page[title]') === 'Setup'
                && $request->hasFile('file', "name,email\nJohn,j@x.test\n", 'roster.csv');
        });
    }

    public function test_upload_without_a_setup_app_or_page_forwards_only_the_file_and_identity(): void
    {
        $this->fakeSso();
        $this->signedInMember();

        $this->post('/assistant/uploads', ['file' => UploadedFile::fake()->createWithContent('roster.csv', "a,b\n")], ['Accept' => 'application/json'])
            ->assertOk();

        Http::assertSent(function (ClientRequest $request): bool {
            $names = array_column($request->data(), 'name');

            return $request->isMultipart()
                && ! in_array('setup_app', $names, true)
                && ! in_array('page[url]', $names, true);
        });
    }

    public function test_upload_status_forwards_to_sso_with_the_session_identity_and_passes_the_answer_back(): void
    {
        Http::fake(['https://sso.test/api/internal/assistant/uploads/17*' => Http::response(['upload' => ['id' => 17, 'status' => 'ready', 'summary' => 'A roster.', 'counts' => ['people' => 2], 'error' => null]], 200)]);
        $this->signedInMember();

        $this->getJson('/assistant/uploads/17?sso_user_id=999&sso_company_id=999')
            ->assertOk()
            ->assertJsonPath('upload.status', 'ready')
            ->assertJsonPath('upload.summary', 'A roster.');

        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://sso.test/api/internal/assistant/uploads/17?')
                && (int) $query['sso_user_id'] === 7
                && (int) $query['sso_company_id'] === 42
                && $query['app_slug'] === config('sso.app_slug');
        });
    }

    public function test_upload_status_passes_a_not_found_back_and_only_takes_a_number(): void
    {
        Http::fake(['https://sso.test/api/internal/assistant/uploads/*' => Http::response(['message' => 'Not Found'], 404)]);
        $this->signedInMember();

        $this->getJson('/assistant/uploads/18')->assertNotFound();
        $this->getJson('/assistant/uploads/abc')->assertNotFound();

        Http::assertSentCount(1);
    }
}
