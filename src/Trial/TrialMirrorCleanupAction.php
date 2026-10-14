<?php

declare(strict_types=1);

namespace Unified\SsoClient\Trial;

/**
 * What {@see TrialMirrorCleaner} does with one mirrored row SSO deleted when
 * it purged a trial's sample lists.
 */
enum TrialMirrorCleanupAction: string
{
    /** Hard-delete the row. A foreign-key failure falls back to Deactivate. */
    case Delete = 'delete';

    /** Keep the row but turn it off (`is_active = false`). */
    case Deactivate = 'deactivate';

    /** Leave the row exactly as it is. */
    case Keep = 'keep';
}
