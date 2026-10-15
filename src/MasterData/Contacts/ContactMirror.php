<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Contacts;

use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;
use Unified\SsoClient\MasterData\ProjectedMirror;

/**
 * Mirrors SSO's agency contacts (medical directors, administrators and the
 * other NEMSIS dContact people an agency lists) into the app's contact
 * table through the bound ContactProjection. ProjectedMirror has the rules:
 * link by `sso_contact_id`, adopt an unlinked local contact by last name,
 * first name and type (SSO's import key, ignoring case), stale guard, kept
 * values, turn off never delete, `contact.*` events and the push
 * (`POST .../contacts/import`, 500 rows a request).
 */
class ContactMirror extends ProjectedMirror
{
    public const SSO_ID_COLUMN = 'sso_contact_id';

    public function __construct(LocalTenantResolver $tenants, MasterDataClient $client, ContactProjection $projection)
    {
        parent::__construct($tenants, $client, $projection);
    }

    public function entity(): string
    {
        return 'contacts';
    }

    public static function events(): array
    {
        return [
            'contact.created',
            'contact.updated',
            'contact.deactivated',
            'contact.batch_updated',
        ];
    }

    public function ssoIdColumn(): string
    {
        return self::SSO_ID_COLUMN;
    }

    protected function recordKey(): string
    {
        return 'contact';
    }

    protected function recordName(array $record): string
    {
        $name = trim((string) ($record['name'] ?? ''));

        return $name !== '' ? $name : trim(((string) ($record['first_name'] ?? '')).' '.((string) ($record['last_name'] ?? '')));
    }
}
