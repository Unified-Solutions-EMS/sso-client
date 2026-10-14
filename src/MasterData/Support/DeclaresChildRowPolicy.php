<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Support;

/**
 * Implemented by a child-table definition (PersonnelChildTable,
 * VehicleChildTable) to say what happens to its rows when SSO removes the
 * child. A definition that does not implement it is unlinked, never deleted.
 */
interface DeclaresChildRowPolicy
{
    public function onRemoved(): ChildRowPolicy;
}
