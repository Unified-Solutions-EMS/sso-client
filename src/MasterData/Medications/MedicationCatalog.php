<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Medications;

use Unified\SsoClient\MasterData\CodeLists\CodeListCatalog;

/**
 * The company's mirrored medications (CodeListCatalog). Rows with no levels
 * are stock only: usable for linking stock, never chartable.
 */
class MedicationCatalog extends CodeListCatalog
{
    protected static function entity(): string
    {
        return 'medications';
    }
}
