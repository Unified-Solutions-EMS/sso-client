<?php

declare(strict_types=1);

namespace Unified\SsoClient\Trial\Contracts;

use Unified\SsoClient\Trial\TrialMirrorCleaner;
use Unified\SsoClient\Trial\TrialMirrorCleanupAction;

/**
 * The app's say over a mirrored row {@see TrialMirrorCleaner}
 * is about to delete. Bind an implementation (usually extending
 * DefaultTrialMirrorCleanupPolicy) in AppServiceProvider::register() to veto
 * a delete the app knows is unsafe, e.g. CloudPCR deactivating a
 * `dem_vehicles` row a chart still references.
 */
interface TrialMirrorCleanupPolicy
{
    /**
     * @param  'divisions'|'locations'|'vehicles'|'qualifications'  $entity
     * @param  object  $row  the local mirror row (already pinned to the company)
     */
    public function decide(string $entity, int $localCompanyId, object $row): TrialMirrorCleanupAction;

    /**
     * Runs in the delete's transaction right before the row is deleted:
     * remove app-owned child rows that would otherwise block it (CloudPCR's
     * `dem_location_phones`). Vehicle crew levels and yearly usage are
     * removed by the package.
     */
    public function beforeDelete(string $entity, int $localCompanyId, object $row): void;
}
