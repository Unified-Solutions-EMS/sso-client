<?php

namespace Unified\SsoClient\Tests\Stubs\Models;

use Illuminate\Database\Eloquent\Model;
use Unified\SsoClient\Concerns\SyncsCompanyRoles;

/**
 * Minimal user model exercising the SyncsCompanyRoles::hasDeviceBypass() helper
 * in isolation (without the full Spatie HasRoles setup, which that method does
 * not need).
 */
class BypassUser extends Model
{
    use SyncsCompanyRoles;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = true;
}
