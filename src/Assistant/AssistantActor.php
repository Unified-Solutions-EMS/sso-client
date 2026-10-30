<?php

namespace Unified\SsoClient\Assistant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Who the assistant is acting for inside this app: the SSO identity SSO
 * vouched for, plus the local user and company rows the package resolved
 * from users.sso_id and companies.sso_company_id.
 */
final readonly class AssistantActor
{
    public function __construct(
        public int $ssoUserId,
        public int $ssoCompanyId,
        public Authenticatable&Model $user,
        public Model $company,
        /** Unified staff acting as the admin through SSO impersonation. */
        public bool $impersonating = false,
    ) {}
}
