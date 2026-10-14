<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Procedures;

use Unified\SsoClient\MasterData\CodeLists\CodeListMirror;
use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;

/**
 * Mirrors SSO's procedures (SNOMED codes, each with the levels permitted to
 * perform it) into `sso_procedures`, and through the bound
 * ProcedureProjection into the app (CodeListMirror has the rules).
 */
class ProcedureMirror extends CodeListMirror
{
    public const TABLE = 'sso_procedures';

    public const SSO_ID_COLUMN = 'sso_procedure_id';

    public function __construct(LocalTenantResolver $tenants, MasterDataClient $client, ProcedureProjection $projection)
    {
        parent::__construct($tenants, $client, $projection);
    }

    public function entity(): string
    {
        return 'procedures';
    }

    protected static function prefix(): string
    {
        return 'procedure';
    }

    public function table(): string
    {
        return self::TABLE;
    }

    public function ssoIdColumn(): string
    {
        return self::SSO_ID_COLUMN;
    }
}
