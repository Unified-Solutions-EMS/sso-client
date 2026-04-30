<?php

declare(strict_types=1);

namespace Unified\SsoClient\Metrics\Facades;

use Illuminate\Support\Facades\Facade;
use Unified\SsoClient\Metrics\Metrics as MetricsManager;

/**
 * @method static void increment(string $metric, float $value = 1.0, array $context = [])
 * @method static void record(string $metric, float $value, array $context = [])
 *
 * @see MetricsManager
 */
class Metrics extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return MetricsManager::class;
    }
}
