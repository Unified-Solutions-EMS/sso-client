<?php

declare(strict_types=1);

namespace Unified\SsoClient\Metrics\Facades;

use Illuminate\Support\Facades\Facade;
use Unified\SsoClient\Metrics\AiUsage as AiUsageRecorder;

/**
 * @method static void record(string $feature, string $purpose, ?string $model, int $inputTokens = 0, int $outputTokens = 0, int $cachedTokens = 0, ?float $audioSeconds = null, ?int $durationMs = null, string $outcome = 'ok', ?string $surface = null, array $attribution = [])
 *
 * @see AiUsageRecorder
 */
class AiUsage extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return AiUsageRecorder::class;
    }
}
