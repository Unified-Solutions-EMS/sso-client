<?php

namespace Unified\SsoClient\Tests\Stubs;

use Unified\SsoClient\MasterData\Medications\MedicationProjection;

/**
 * CloudPCR's medication groups (DemFormularyProjection).
 */
class DemMedicationProjection extends DemFormularyProjection implements MedicationProjection
{
    protected function groupsTable(): string
    {
        return 'dem_configuration_medication_groups';
    }

    protected function itemsTable(): string
    {
        return 'dem_configuration_medication_items';
    }

    protected function groupKey(): string
    {
        return 'dem_configuration_medication_group_id';
    }

    protected function levelColumn(): string
    {
        return 'dconfiguration_08';
    }

    protected function codeColumn(): string
    {
        return 'dconfiguration_09';
    }

    protected function controlled(): bool
    {
        return true;
    }
}
