<?php

declare(strict_types=1);

namespace Unified\SsoClient\Metrics;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use Unified\SsoClient\Metrics\Facades\Metrics as MetricsFacade;

/**
 * Shapes the platform-wide `ai.request` usage event: one event per model
 * call, PHI-free context only (never prompt text, transcripts, file names
 * or patient data). Delivery, id translation and queueing stay in Metrics.
 *
 * Usage:
 *   AiUsage::record('dictation-extract', 'extraction', 'gpt-5.6-luna',
 *       inputTokens: 1200, outputTokens: 340, durationMs: 2100,
 *       attribution: ['local_company_id' => $companyId, 'local_user_id' => $userId]);
 */
class AiUsage
{
    public const METRIC = 'ai.request';

    private const FEATURE_MAX_LENGTH = 40;

    private const ATTRIBUTION_KEYS = ['local_company_id', 'local_user_id', 'sso_company_id', 'sso_user_id'];

    /**
     * @param  string  $feature  [a-z0-9_-]{1,40}
     * @param  string  $purpose  chat|extraction|transcription|captions
     * @param  string  $outcome  ok|error|timeout|busy|refused|rejected|cap
     * @param  string|null  $surface  web|mobile|api|queue
     * @param  array{local_company_id?:int|null, local_user_id?:int|null, sso_company_id?:int|null, sso_user_id?:int|null}  $attribution
     */
    public function record(
        string $feature,
        string $purpose,
        ?string $model,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $cachedTokens = 0,
        ?float $audioSeconds = null,
        ?int $durationMs = null,
        string $outcome = 'ok',
        ?string $surface = null,
        array $attribution = [],
    ): void {
        try {
            $context = array_filter([
                'feature' => $this->sanitiseFeature($feature),
                'purpose' => $purpose,
                'model' => $model !== null && $model !== '' ? $model : null,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cached_tokens' => $cachedTokens > 0 ? $cachedTokens : null,
                'audio_seconds' => $audioSeconds !== null && $audioSeconds > 0 ? $audioSeconds : null,
                'duration_ms' => $durationMs !== null && $durationMs > 0 ? $durationMs : null,
                'outcome' => $outcome,
                'surface' => $surface ?? $this->detectSurface(),
            ], static fn ($value): bool => $value !== null);

            $attribution = array_filter(
                array_intersect_key($attribution, array_flip(self::ATTRIBUTION_KEYS)),
                static fn ($value): bool => $value !== null,
            );

            MetricsFacade::increment(self::METRIC, 1.0, $context + $attribution);
        } catch (Throwable $e) {
            try {
                Log::warning('AI usage metric not recorded', [
                    'feature' => $feature,
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable) {
                // Recording must never break the calling request, not even via a broken logger.
            }
        }
    }

    private function sanitiseFeature(string $feature): string
    {
        $sanitised = (string) preg_replace('/[^a-z0-9_-]/', '-', strtolower(trim($feature)));
        $sanitised = substr($sanitised, 0, self::FEATURE_MAX_LENGTH);

        return $sanitised !== '' ? $sanitised : 'unknown';
    }

    private function detectSurface(): string
    {
        $request = app()->bound('request') ? app('request') : null;

        if ($request instanceof Request && $request->is('api/mobile', 'api/mobile/*')) {
            return 'mobile';
        }

        if (app()->runningInConsole() && ($request === null || $request->route() === null)) {
            return 'queue';
        }

        return 'web';
    }
}
