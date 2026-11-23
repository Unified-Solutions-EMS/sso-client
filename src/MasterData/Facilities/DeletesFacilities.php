<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Facilities;

/**
 * Optional for a FacilityProjection: the app can tell, from its own records,
 * which of its facility rows nothing references, and remove those when SSO
 * deletes the facility (`facility.deleted`).
 *
 * SSO deletes a facility only after every app that can hold a reference said
 * it is unused, but the app checks again: a record may have been saved since
 * SSO asked. FacilityMirror turns off and unlinks every row this method does
 * not return, so a projection without it (or one that returns nothing) keeps
 * every row, which is always safe.
 */
interface DeletesFacilities
{
    /**
     * Delete the rows among $localIds that no record of the company
     * references (with whatever child rows they own) and return the ids
     * deleted. Every query is filtered by $localCompanyId (DEV_GUIDELINES §4a).
     *
     * @param  list<int>  $localIds  rows of the company linked to the deleted SSO facilities
     * @return list<int>
     */
    public function deleteUnreferenced(int $localCompanyId, array $localIds): array;
}
