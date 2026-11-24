<?php

namespace Unified\SsoClient\Tests\Stubs\Models;

use Spatie\Permission\Models\Role;

/** A company-scoped role row, the shape every app's Role model has. */
class SpatieRole extends Role
{
    protected $guarded = [];
}
