<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Exceptions;

/**
 * SSO answered 404 on a company's master-data collection (the snapshot or the
 * import): the local company's sso_company_id names a company SSO no longer
 * has (deleted or merged). The push and resync commands count it as skipped
 * and never report it; every other caller still sees a MasterDataSyncException.
 */
class CompanyNotInSsoException extends MasterDataSyncException
{
    public function __construct(public readonly int|string $ssoCompanyId, string $entity)
    {
        parent::__construct("SSO has no company {$ssoCompanyId} ({$entity}); its local sso_company_id is stale.");
    }
}
