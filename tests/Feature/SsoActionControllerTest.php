<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Unified\SsoClient\Http\ActionResponse;
use Unified\SsoClient\Tests\Stubs\StubActionHandler;
use Unified\SsoClient\Tests\Stubs\StubLegacyArrayActionHandler;
use Unified\SsoClient\Tests\Stubs\StubThrowingActionHandler;
use Unified\SsoClient\Tests\TestCase;

class SsoActionControllerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.webhook_secret', 'whsec');
        $app['config']->set('sso.action_handlers', [
            'stub' => StubActionHandler::class,
            'legacy' => StubLegacyArrayActionHandler::class,
            'throws' => StubThrowingActionHandler::class,
            'missing-class' => 'App\\Sso\\Actions\\DoesNotExist',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        StubActionHandler::$result = [];
        StubActionHandler::$receivedPayload = null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postAction(string $action, array $payload = [], ?string $signature = null): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', "/api/sso/actions/{$action}", [], [], [], [
            'HTTP_X-SSO-Signature' => $signature ?? hash_hmac('sha256', $body, 'whsec'),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    public function test_array_without_http_status_returns_200_with_body_unchanged(): void
    {
        StubActionHandler::$result = ['ok' => true, 'items' => [1, 2]];

        $this->postAction('stub', ['company_id' => 7])
            ->assertStatus(200)
            ->assertExactJson(['ok' => true, 'items' => [1, 2]]);

        $this->assertSame(['company_id' => 7], StubActionHandler::$receivedPayload);
    }

    public function test_legacy_array_typed_handler_still_works(): void
    {
        $this->postAction('legacy', ['value' => 'x'])
            ->assertStatus(200)
            ->assertExactJson(['ok' => true, 'echo' => 'x']);
    }

    public function test_array_with_http_status_sets_status_and_strips_key(): void
    {
        StubActionHandler::$result = ['error' => 'Run not found', 'http_status' => 404, 'safe_for_display' => []];

        $this->postAction('stub')
            ->assertStatus(404)
            ->assertExactJson(['error' => 'Run not found', 'safe_for_display' => []]);
    }

    public function test_array_with_non_integer_http_status_is_a_plain_200(): void
    {
        StubActionHandler::$result = ['http_status' => '404', 'ok' => true];

        $this->postAction('stub')
            ->assertStatus(200)
            ->assertExactJson(['http_status' => '404', 'ok' => true]);
    }

    public function test_array_with_out_of_range_http_status_is_a_plain_200(): void
    {
        StubActionHandler::$result = ['http_status' => 42, 'ok' => true];

        $this->postAction('stub')
            ->assertStatus(200)
            ->assertExactJson(['http_status' => 42, 'ok' => true]);
    }

    public function test_action_response_ok(): void
    {
        StubActionHandler::$result = ActionResponse::ok(['done' => true]);

        $this->postAction('stub')->assertStatus(200)->assertExactJson(['done' => true]);
    }

    public function test_action_response_not_found(): void
    {
        StubActionHandler::$result = ActionResponse::notFound('Run not found');

        $this->postAction('stub')->assertStatus(404)->assertExactJson(['error' => 'Run not found']);
    }

    public function test_action_response_too_many_requests_without_retry_after(): void
    {
        StubActionHandler::$result = ActionResponse::tooManyRequests('Slow down');

        $response = $this->postAction('stub')->assertStatus(429)->assertExactJson(['error' => 'Slow down']);

        $this->assertFalse($response->headers->has('Retry-After'));
    }

    public function test_action_response_too_many_requests_with_retry_after(): void
    {
        StubActionHandler::$result = ActionResponse::tooManyRequests('Slow down', 30);

        $this->postAction('stub')
            ->assertStatus(429)
            ->assertHeader('Retry-After', '30')
            ->assertExactJson(['error' => 'Slow down', 'retry_after' => 30]);
    }

    public function test_action_response_not_implemented(): void
    {
        StubActionHandler::$result = ActionResponse::notImplemented('AI assist is not configured');

        $this->postAction('stub')->assertStatus(501)->assertExactJson(['error' => 'AI assist is not configured']);
    }

    public function test_action_response_error(): void
    {
        StubActionHandler::$result = ActionResponse::error(422, ['error' => 'Bad input', 'field' => 'unit_id']);

        $this->postAction('stub')->assertStatus(422)->assertExactJson(['error' => 'Bad input', 'field' => 'unit_id']);
    }

    public function test_action_response_rejects_status_outside_200_to_599(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ActionResponse(999);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonErrorStatuses(): array
    {
        return ['200' => [200], '204' => [204], '302' => [302], '399' => [399], '600' => [600]];
    }

    #[DataProvider('nonErrorStatuses')]
    public function test_action_response_error_rejects_non_error_statuses(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);

        ActionResponse::error($status, []);
    }

    public function test_handler_responses_carry_the_handled_header_whatever_the_status(): void
    {
        foreach ([
            ['ok' => true],
            ['error' => 'gone', 'http_status' => 404],
            ActionResponse::ok(),
            ActionResponse::notFound('Run not found'),
            ActionResponse::notImplemented('Not configured'),
            ActionResponse::tooManyRequests('Slow down'),
            ActionResponse::error(422, ['error' => 'Bad input']),
        ] as $result) {
            StubActionHandler::$result = $result;

            $this->postAction('stub')->assertHeader('X-SSO-Action-Handled', '1');
        }
    }

    public function test_unknown_action_404_has_no_handled_header(): void
    {
        $this->postAction('nope')
            ->assertStatus(404)
            ->assertHeaderMissing('X-SSO-Action-Handled');
    }

    public function test_missing_handler_class_501_has_no_handled_header(): void
    {
        $this->postAction('missing-class')
            ->assertStatus(501)
            ->assertHeaderMissing('X-SSO-Action-Handled');
    }

    public function test_thrown_exception_is_a_generic_500_and_logs_no_payload(): void
    {
        Log::spy();

        $response = $this->postAction('throws', [
            'sso_company_id' => 'sso-co-1',
            'patient_name' => 'Jane Doe',
        ]);

        $response->assertStatus(500)
            ->assertExactJson(['error' => 'Action failed'])
            ->assertHeaderMissing('X-SSO-Action-Handled');
        $this->assertStringNotContainsString('Jane Doe', (string) $response->getContent());

        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'SSO action [throws] failed'
                && array_keys($context) === ['action', 'sso_company_id', 'exception']
                && $context['action'] === 'throws'
                && $context['sso_company_id'] === 'sso-co-1'
                && $context['exception'] instanceof RuntimeException;
        });
    }

    public function test_invalid_signature_is_rejected_before_the_handler_runs(): void
    {
        StubActionHandler::$result = ActionResponse::ok(['done' => true]);

        $this->postAction('stub', ['company_id' => 7], 'bogus')
            ->assertStatus(403)
            ->assertExactJson(['error' => 'Invalid signature']);

        $this->assertNull(StubActionHandler::$receivedPayload);
    }

    public function test_unknown_action_is_404(): void
    {
        $this->postAction('nope')->assertStatus(404)->assertExactJson(['error' => 'Unknown action: nope']);
    }
}
