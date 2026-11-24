<?php

namespace Unified\SsoClient\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One pending session action that should be applied on this user's
 * next authenticated request. Drained by EnforceSsoSessionActions
 * middleware. See migration for table semantics.
 */
class SsoSessionAction extends Model
{
    public const ACTION_FORCE_LOGOUT = 'force_logout';

    public const ACTION_SET_COMPANY = 'set_company';

    /**
     * The user's roles in one company changed (payload `company_id`, and
     * `removed: true` for No access). Unlike the other actions it is not
     * deleted when applied: every session the user has open must see it once,
     * so each session records the highest id it has applied and the row
     * lives until it expires.
     */
    public const ACTION_RELOAD_ROLES = 'reload_roles';

    protected $table = 'sso_session_actions';

    protected $fillable = [
        'user_id',
        'action',
        'payload',
        'expires_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'expires_at' => 'datetime',
    ];
}
