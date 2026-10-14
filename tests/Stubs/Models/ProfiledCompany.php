<?php

namespace Unified\SsoClient\Tests\Stubs\Models;

use Illuminate\Database\Eloquent\Model;
use Unified\SsoClient\Concerns\HasSsoProfile;
use Unified\SsoClient\MasterData\Contacts\HasMirroredAgencyContacts;

/**
 * A Company model the way an app adopts the agency read helpers.
 */
class ProfiledCompany extends Model
{
    use HasMirroredAgencyContacts;
    use HasSsoProfile;

    protected $table = 'companies';

    protected $guarded = [];
}
