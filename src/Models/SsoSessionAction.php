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
