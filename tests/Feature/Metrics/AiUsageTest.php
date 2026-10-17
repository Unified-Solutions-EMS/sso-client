<?php

namespace Unified\SsoClient\Tests\Feature\Metrics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use ReflectionProperty;
use RuntimeException;
use Unified\SsoClient\Metrics\AiUsage as AiUsageRecorder;
use Unified\SsoClient\Metrics\Facades\AiUsage;
use Unified\SsoClient\Metrics\Facades\Metrics;
use Unified\SsoClient\Metrics\Jobs\SendMetricToUnified;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\User;
use Unified\SsoClient\Tests\TestCase;

class AiUsageTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('metrics.app_key', 'testapp');
        $app['config']->set('metrics.endpoint', 'https://sso.test/api/internal/metrics/ingest');
        $app['config']->set('metrics.token', 'core-api-key');
        $app['config']->set('metrics.company_model', Company::class);
        $app['config']->set('metrics.user_model', User::class);
    }

    /**
     * SendMetricToUnified keeps its payload protected; changing that would
     * rename the serialized property and strand jobs already on the queue.
     *
     * @return array<string, mixed>
     */
    private static function payload(SendMetricToUnified $job): array
    {
        return (new ReflectionProperty($job, 'payload'))->getValue($job);
    }

    public function test_record_sends_one_ai_request_metric_with_full_context(): void
    {
        Queue::fake();

        AiUsage::record(
            feature: 'dictation-extract',
            purpose: 'extraction',
            model: 'gpt-5.6-luna-2026-09-01',
            inputTokens: 1200,
            outputTokens: 340,
            cachedTokens: 800,
            audioSeconds: 12.5,
            durationMs: 2100,
            outcome: 'ok',
            surface: 'web',
            attribution: ['sso_company_id' => 42, 'sso_user_id' => 17],
        );

        Queue::assertPushed(SendMetricToUnified::class, 1);
        Queue::assertPushed(SendMetricToUnified::class, function (SendMetricToUnified $job): bool {
            return self::payload($job)['app_key'] === 'testapp'
                && self::payload($job)['metric'] === 'ai.request'
                && self::payload($job)['value'] === 1.0
                && self::payload($job)['context'] === [
                    'feature' => 'dictation-extract',
                    'purpose' => 'extraction',
                    'model' => 'gpt-5.6-luna-2026-09-01',
                    'input_tokens' => 1200,
                    'output_tokens' => 340,
                    'cached_tokens' => 800,
                    'audio_seconds' => 12.5,
                    'duration_ms' => 2100,
                    'outcome' => 'ok',
                    'surface' => 'web',
                    'sso_company_id' => 42,
                    'sso_user_id' => 17,
                ];
        });
    }

    public function test_metric_constant_is_ai_request(): void
    {
        $this->assertSame('ai.request', AiUsageRecorder::METRIC);
    }

    public function test_feature_is_lowercased_sanitised_and_truncated(): void
    {
        Queue::fake();

        AiUsage::record(feature: ' Analyst Chat/v2.Beta ', purpose: 'chat', model: 'm', surface: 'web');
        AiUsage::record(feature: str_repeat('a', 60), purpose: 'chat', model: 'm', surface: 'web');

        $features = Queue::pushed(SendMetricToUnified::class)
            ->map(fn (SendMetricToUnified $job) => self::payload($job)['context']['feature'])
            ->all();

        $this->assertSame(['analyst-chat-v2-beta', str_repeat('a', 40)], $features);
    }

    public function test_null_and_zero_optionals_are_dropped_but_token_counts_kept(): void
    {
        Queue::fake();

        AiUsage::record(
            feature: 'dictation-captions',
            purpose: 'captions',
            model: null,
            cachedTokens: 0,
            audioSeconds: 0.0,
            durationMs: 0,
            outcome: 'error',
            surface: 'web',
        );

        Queue::assertPushed(SendMetricToUnified::class, function (SendMetricToUnified $job): bool {
            return self::payload($job)['context'] === [
                'feature' => 'dictation-captions',
                'purpose' => 'captions',
                'input_tokens' => 0,
                'output_tokens' => 0,
                'outcome' => 'error',
                'surface' => 'web',
            ];
        });
    }

    public function test_local_attribution_is_translated_to_sso_ids(): void
    {
        Queue::fake();

        DB::table('companies')->insert(['name' => 'A', 'sso_company_id' => '42']);
        DB::table('users')->insert(['name' => 'U', 'email' => 'u@test.com', 'sso_id' => '17']);

        AiUsage::record('qa-clinical-review', 'chat', 'gpt-5.6', 10, 5, surface: 'queue', attribution: [
            'local_company_id' => (int) DB::table('companies')->value('id'),
            'local_user_id' => (int) DB::table('users')->value('id'),
        ]);

        Queue::assertPushed(SendMetricToUnified::class, function (SendMetricToUnified $job): bool {
            $context = self::payload($job)['context'];

            return $context['sso_company_id'] === 42
                && $context['sso_user_id'] === 17
                && ! array_key_exists('local_company_id', $context)
                && ! array_key_exists('local_user_id', $context);
        });
    }

    public function test_explicit_sso_ids_win_over_local_ids_and_session(): void
    {
        Queue::fake();

        DB::table('companies')->insert(['name' => 'A', 'sso_company_id' => '42']);
        $localCompanyId = (int) DB::table('companies')->value('id');
        session(['selected_company_id' => $localCompanyId]);

        AiUsage::record('analyst-chat', 'chat', 'gpt-5.6', surface: 'web', attribution: [
            'local_company_id' => $localCompanyId,
            'sso_company_id' => 99,
            'sso_user_id' => 7,
        ]);

        Queue::assertPushed(SendMetricToUnified::class, function (SendMetricToUnified $job): bool {
            return self::payload($job)['context']['sso_company_id'] === 99
                && self::payload($job)['context']['sso_user_id'] === 7;
        });
    }

    public function test_session_company_is_used_when_no_attribution_given(): void
    {
        Queue::fake();

        DB::table('companies')->insert(['name' => 'A', 'sso_company_id' => '42']);
        session(['selected_company_id' => (int) DB::table('companies')->value('id')]);

        AiUsage::record('qi-rule-generator', 'chat', 'gpt-5.6', surface: 'web');

        Queue::assertPushed(SendMetricToUnified::class, fn (SendMetricToUnified $job): bool => self::payload($job)['context']['sso_company_id'] === 42);
    }

    public function test_unknown_attribution_keys_are_not_forwarded(): void
    {
        Queue::fake();

        AiUsage::record('analyst-chat', 'chat', 'gpt-5.6', surface: 'web', attribution: [
            'sso_company_id' => 3,
            'patient_name' => 'should never leave',
        ]);

        Queue::assertPushed(SendMetricToUnified::class, fn (SendMetricToUnified $job): bool => ! array_key_exists('patient_name', self::payload($job)['context']));
    }

    public function test_surface_defaults_to_queue_in_console_without_a_routed_request(): void
    {
        Queue::fake();

        AiUsage::record('field-detection', 'extraction', 'gpt-5.6');

        Queue::assertPushed(SendMetricToUnified::class, fn (SendMetricToUnified $job): bool => self::payload($job)['context']['surface'] === 'queue');
    }

    public function test_surface_defaults_to_web_for_a_routed_request(): void
    {
        Queue::fake();

        Route::post('/ai/suggest', function () {
            AiUsage::record('trip-assignment', 'chat', 'gpt-5.6');

            return 'ok';
        });

        $this->post('/ai/suggest')->assertOk();

        Queue::assertPushed(SendMetricToUnified::class, fn (SendMetricToUnified $job): bool => self::payload($job)['context']['surface'] === 'web');
    }

    public function test_surface_defaults_to_mobile_for_api_mobile_paths(): void
    {
        Queue::fake();

        Route::post('/api/mobile/dictation/extract', function () {
            AiUsage::record('dictation-extract', 'extraction', 'gpt-5.6');

            return 'ok';
        });

        $this->post('/api/mobile/dictation/extract')->assertOk();

        Queue::assertPushed(SendMetricToUnified::class, fn (SendMetricToUnified $job): bool => self::payload($job)['context']['surface'] === 'mobile');
    }

    public function test_explicit_surface_wins_over_detection(): void
    {
        Queue::fake();

        Route::post('/api/mobile/replay', function () {
            AiUsage::record('trip-assignment', 'chat', 'gpt-5.6', surface: 'api');

            return 'ok';
        });

        $this->post('/api/mobile/replay')->assertOk();

        Queue::assertPushed(SendMetricToUnified::class, fn (SendMetricToUnified $job): bool => self::payload($job)['context']['surface'] === 'api');
    }

    public function test_nothing_is_sent_when_metrics_is_unconfigured(): void
    {
        Queue::fake();

        config()->set('metrics.endpoint', null);

        AiUsage::record('dictation-extract', 'extraction', 'gpt-5.6', 10, 5);

        Queue::assertNotPushed(SendMetricToUnified::class);
    }

    public function test_failures_never_reach_the_caller(): void
    {
        Metrics::shouldReceive('increment')->once()->andThrow(new RuntimeException('queue down'));

        AiUsage::record('dictation-extract', 'extraction', 'gpt-5.6', 10, 5);

        $this->addToAssertionCount(1);
    }
}
