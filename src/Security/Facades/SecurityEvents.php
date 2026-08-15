<?php

declare(strict_types=1);

namespace Unified\SsoClient\Security\Facades;

use Illuminate\Support\Facades\Facade;
use Unified\SsoClient\Security\SecurityEvents as SecurityEventsManager;

/**
 * @method static void info(string $event, array $context = [])
 * @method static void warning(string $event, array $context = [])
 * @method static void critical(string $event, array $context = [])
 * @method static void record(string $event, array $context = [], string $severity = 'warning')
 * @method static bool isHoneytoken(?string $value)
 * @method static bool isCanaryEmail(?string $email)
 *
 * @see SecurityEventsManager
 */
class SecurityEvents extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return SecurityEventsManager::class;
    }
}
