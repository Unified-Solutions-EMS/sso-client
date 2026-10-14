<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Contracts;

use Unified\SsoClient\MasterData\Exceptions\MasterDataSyncException;

/**
 * A mirror whose SSO snapshot is not one GET: personnel is paged (200 people
 * a page, cursor by SSO user id), so the mirror walks the pages itself and
 * hands resync() the whole set. MasterDataClient::snapshot() asks the mirror
 * when it implements this and does a plain fetch() otherwise.
 */
interface FetchesSnapshot
{
    /**
     * The complete snapshot for one SSO company, in the shape the mirror's
     * resync() reads.
     *
     * @return array<string, mixed>
     *
     * @throws MasterDataSyncException
     */
    public function fetchSnapshot(int|string $ssoCompanyId): array;
}
