<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Contracts;

/**
 * A mirror with webhook events that finish the one-time push rather than
 * mirror SSO: they are applied while the entity is still turned off (the
 * push runs before the flag is on), as long as the mirror migration has run.
 * The code lists' `{entity}.review_completed` is one: the agency finishes
 * the cutover review in SSO, and the mapping links this app's rows.
 */
interface ContinuesPush
{
    /**
     * @return list<string>
     */
    public static function pushEvents(): array;
}
