<?php

namespace Unified\SsoClient\Tests\Stubs\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'roles';

    protected $guarded = [];

    public $timestamps = true;
}
