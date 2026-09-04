<?php

namespace Unified\SsoClient\Exceptions;

use RuntimeException;

/**
 * Raised (reported, never thrown out of the login path) when the company row
 * matched for an SSO payload needs a unique link value that a different local
 * row already owns.
 *
 * This is a data-merge problem for a human, not a runtime failure: the login
 * continues with the stamp skipped, and the conflict reaches Sentry so someone
 * can reconcile the two rows.
 */
class CompanyLinkCollisionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $column,
        public readonly string $value,
        public readonly int|string|null $matchedCompanyId,
        public readonly int|string|null $conflictingCompanyId,
        public readonly int|string|null $ssoCompanyId,
    ) {
        parent::__construct($message);
    }

    public static function forColumn(
        string $column,
        int|string $value,
        int|string|null $matchedCompanyId,
        int|string|null $conflictingCompanyId,
        int|string|null $ssoCompanyId,
    ): self {
        return new self(
            sprintf(
                'SSO sync could not stamp %s=%s onto company %s: company %s already holds it. Login continued with the link unset; the two rows need a manual merge.',
                $column,
                $value,
                $matchedCompanyId ?? 'unknown',
                $conflictingCompanyId ?? 'unknown',
            ),
            $column,
            (string) $value,
            $matchedCompanyId,
            $conflictingCompanyId,
            $ssoCompanyId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'column' => $this->column,
            'value' => $this->value,
            'matched_company_id' => $this->matchedCompanyId,
            'conflicting_company_id' => $this->conflictingCompanyId,
            'sso_company_id' => $this->ssoCompanyId,
        ];
    }
}
