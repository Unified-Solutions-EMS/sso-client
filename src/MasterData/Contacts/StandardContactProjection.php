<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Contacts;

use Unified\SsoClient\MasterData\Support\StandardRecordProjection;

/**
 * The default projection: the package-owned `sso_agency_contacts` read
 * model, phones, emails, address and medical director details as JSON text.
 */
class StandardContactProjection extends StandardRecordProjection implements ContactProjection
{
    public const TABLE = 'sso_agency_contacts';

    public function table(): string
    {
        return self::TABLE;
    }

    public function nameColumn(): string
    {
        return 'last_name';
    }

    public function matchColumns(): array
    {
        return ['last_name', 'first_name', 'type'];
    }

    protected function fields(): array
    {
        return [
            'type' => 'type',
            'nemsis_type' => 'nemsis_type',
            'is_primary' => 'is_primary',
            'first_name' => 'first_name',
            'middle_name' => 'middle_name',
            'last_name' => 'last_name',
            'title' => 'title',
            'phones' => 'phones',
            'emails' => 'emails',
            'address' => 'address',
            'website' => 'website',
            'medical_director' => 'medical_director',
            'fellowship_status' => 'fellowship_status',
            'sso_user_id' => 'sso_user_id',
        ];
    }

    protected function booleanColumns(): array
    {
        return ['is_primary'];
    }

    protected function jsonColumns(): array
    {
        return ['phones', 'emails', 'address', 'medical_director'];
    }
}
