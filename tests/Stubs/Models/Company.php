<?php

namespace Unified\SsoClient\Tests\Stubs\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $table = 'companies';

    protected $guarded = [];

    protected $casts = [
        'enabled_modules' => 'array',
    ];

    public $timestamps = true;
}
