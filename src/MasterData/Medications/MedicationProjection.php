<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Medications;

use Unified\SsoClient\MasterData\CodeLists\CodeListProjection;

/**
 * The app side of the medications mirror (see CodeListProjection).
 * localItems() entries also carry `is_controlled` and `dea_schedule`;
 * project() items also carry `ingredient_rxcui`, `is_controlled`,
 * `dea_schedule` and `is_blood_product`.
 *
 * CloudPCR: localItems() collapses dem_configuration_medication_items
 * (dconfiguration_09 code, name, code_type, is_controlled) across every
 * dem_configuration of the company (dconfiguration_01 state) and every
 * dem_configuration_medication_groups level (dconfiguration_08) into one
 * entry per code; project() rebuilds each state configuration's groups.
 * tests/Stubs/DemFormularyProjection.php is the template.
 */
interface MedicationProjection extends CodeListProjection {}
