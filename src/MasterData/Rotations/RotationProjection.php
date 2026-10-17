<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Rotations;

use Unified\SsoClient\MasterData\Contracts\RecordProjection;

/**
 * How one app stores SSO's rotations (Settings > Agency lists > Rotations:
 * "24 on / 48 off", "Kelly"). RotationMirror owns the link
 * (`sso_rotation_id`), the stale guard, pending adoption, turn off never
 * delete and the two child tables, which are always the package's own
 * `sso_rotation_blocks` and `sso_rotation_assignments` under table();
 * the projection owns the rotation row and the push.
 *
 * fromSso() reads one rotation: `name`, `description`, `cycle_days`,
 * `anchor_date` (company-local "day 1"), `pattern` (SSO's plain-words
 * summary), `source_app`. Blocks and assignments are not the projection's.
 *
 * The push is not read from table(): the package's `sso_rotations` rows ARE
 * SSO's. pushRotations() hands RotationMirror the app's own rotations in
 * the shape of SSO's POST .../rotations/import (Crew Scheduling: its
 * template rotations converted to day cycles), and linkPushed() records
 * the SSO rotation each one became (Crew: `shift_templates.sso_rotation_id`).
 * StandardRotationProjection pushes nothing.
 */
interface RotationProjection extends RecordProjection
{
    /**
     * The app's rotations for SSO's import: `[{local_id, name, description?,
     * cycle_days, anchor_date, is_active?, blocks: [{day_index, start_time,
     * duration_minutes, label?}], assignments?: [{local_id?, unit_sso_id? |
     * unit_local_id? | user_sso_id?, start_day_index?, starts_on?,
     * ends_on?}]}]`, every row of the one local company.
     *
     * @return list<array<string, mixed>>
     */
    public function pushRotations(int $localCompanyId): array;

    /**
     * Records that local rotation $localId is SSO rotation $ssoId. Returns
     * null when linked, or why it was not (reported as a collision).
     *
     * @param  array<string, int>  $assignments  SSO's map of the push's assignment local ids to SSO ids
     */
    public function linkPushed(int $localCompanyId, int|string $localId, int $ssoId, array $assignments): ?string;
}
