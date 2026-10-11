<?php

namespace Unified\SsoClient\Tests\Stubs;

use Unified\SsoClient\MasterData\Procedures\ProcedureProjection;

/**
 * CloudPCR's procedure groups (DemFormularyProjection).
 */
class DemProcedureProjection extends DemFormularyProjection implements ProcedureProjection
{
    protected function groupsTable(): string
    {
        return 'dem_configuration_procedure_groups';
    }

    protected function itemsTable(): string
    {
        return 'dem_configuration_procedure_items';
    }

    protected function groupKey(): string
    {
        return 'dem_configuration_procedure_group_id';
    }

    protected function levelColumn(): string
    {
        return 'dconfiguration_06';
    }

    protected function codeColumn(): string
    {
        return 'dconfiguration_07';
    }
}
