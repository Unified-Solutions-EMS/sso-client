<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\OtherAgencies;

use Unified\SsoClient\MasterData\Support\StandardRecordProjection;

/**
 * The default projection: the package-owned `sso_other_agencies` read model.
 */
class StandardOtherAgencyProjection extends StandardRecordProjection implements OtherAgencyProjection
{
    public const TABLE = 'sso_other_agencies';

    public function table(): string
    {
        return self::TABLE;
    }

    public function nameColumn(): string
    {
        return 'name';
    }

    public function matchColumns(): array
    {
        return ['name', 'state'];
    }

    protected function fields(): array
    {
        return [
            'uuid' => 'uuid',
            'name' => 'name',
            'state' => 'state',
            'state_agency_id' => 'state_agency_id',
            'agency_number' => 'agency_number',
            'service_type' => 'service_type',
        ];
    }
}
