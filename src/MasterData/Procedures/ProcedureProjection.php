<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Procedures;

use Unified\SsoClient\MasterData\CodeLists\CodeListProjection;

/**
 * The app side of the procedures mirror (see CodeListProjection). SNOMED
 * codes only; blood transfusion is never on the list (CloudPCR adds it to
 * every chart itself).
 *
 * CloudPCR: the same tree as medications with dem_configuration_procedure_groups
 * (dconfiguration_06 level) and dem_configuration_procedure_items
 * (dconfiguration_07 code). tests/Stubs/DemFormularyProjection.php is the
 * template.
 */
interface ProcedureProjection extends CodeListProjection {}
