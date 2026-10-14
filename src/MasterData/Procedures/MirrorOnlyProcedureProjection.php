<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Procedures;

/**
 * The package default: the app keeps no list of its own (nothing to push)
 * and reads SSO's list from the mirror table through ProcedureCatalog.
 */
class MirrorOnlyProcedureProjection implements ProcedureProjection
{
    public function localItems(int $localCompanyId): array
    {
        return [];
    }

    public function project(int $localCompanyId, array $items): void {}
}
