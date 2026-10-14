<?php

declare(strict_types=1);

namespace Unified\SsoClient\Trial\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * SSO's lists for a new trial are not mirrored here yet. Thrown to make the
 * queued seed job retry; not reported per attempt because SSO finishing a
 * moment later is normal. The job reports once if it gives up.
 */
class TrialMasterDataNotReadyException extends RuntimeException implements ShouldntReport {}
