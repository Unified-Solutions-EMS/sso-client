<?php

namespace Unified\SsoClient\Assistant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Who the assistant is acting for inside this app: the SSO identity SSO
 * vouched for, plus the local user and company rows the package resolved
 * from users.sso_id and companies.sso_company_id.
 *
 * On the public website it can instead be a visitor (isVisitor()): an
 * anonymous person with only a `visitorId`, no user, no company, nobody
 * signed in. Only tools that implement VisitorAssistantTool ever see one.
 */
final readonly class AssistantActor
{
    public function __construct(
        public ?int $ssoUserId,
        public ?int $ssoCompanyId,
        public (Authenticatable&Model)|null $user,
        public ?Model $company,
        /** Unified staff acting as the admin through SSO impersonation. */
        public bool $impersonating = false,
        /** The website visitor's anonymous id (UUID); null for a signed-in member. */
        public ?string $visitorId = null,
    ) {}

    public static function visitor(string $visitorId): self
    {
        return new self(null, null, null, null, false, $visitorId);
    }

    public function isVisitor(): bool
    {
        return $this->visitorId !== null;
    }
}
