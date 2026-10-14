<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Procedures;

use Unified\SsoClient\MasterData\CodeLists\CodeListCatalog;

/**
 * The company's mirrored procedures (CodeListCatalog).
 */
class ProcedureCatalog extends CodeListCatalog
{
    protected static function entity(): string
    {
        return 'procedures';
    }
}
