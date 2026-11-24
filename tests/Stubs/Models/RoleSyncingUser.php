<?php

namespace Unified\SsoClient\Tests\Stubs\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;
use Unified\SsoClient\Concerns\SyncsCompanyRoles;

/** A consuming app's User model: Spatie roles hydrated per company by the package trait. */
class RoleSyncingUser extends Authenticatable
{
    use HasRoles;
    use SyncsCompanyRoles;

    protected $table = 'users';

    protected $guarded = [];

    protected string $guard_name = 'web';

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user', 'user_id', 'company_id')->withTimestamps();
    }
}
