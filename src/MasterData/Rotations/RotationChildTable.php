<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Rotations;

use Unified\SsoClient\MasterData\Support\ChildRowPolicy;
use Unified\SsoClient\MasterData\Support\DeclaresChildRowPolicy;

/**
 * One of a rotation's two child tables in the package's read model:
 * `sso_rotation_blocks` (the timed on-duty blocks) and
 * `sso_rotation_assignments` (which units and people run it, from which
 * cycle day). Both are pure mirrors the package owns, with every column
 * written from SSO and nothing of the app's own, so a child SSO removed is
 * deleted (ChildRowPolicy::delete()) instead of kept unlinked: a stale
 * block or assignment left behind would put a unit on duty that SSO took
 * off. Rows are still updated in place by SSO's child id.
 */
final class RotationChildTable implements DeclaresChildRowPolicy
{
    public const BLOCKS = 'sso_rotation_blocks';

    public const ASSIGNMENTS = 'sso_rotation_assignments';

    public const PARENT_COLUMN = 'rotation_id';

    private function __construct(
        public readonly string $table,
        public readonly string $ssoIdColumn,
    ) {}

    public static function blocks(): self
    {
        return new self(self::BLOCKS, 'sso_rotation_block_id');
    }

    public static function assignments(): self
    {
        return new self(self::ASSIGNMENTS, 'sso_rotation_assignment_id');
    }

    public function onRemoved(): ChildRowPolicy
    {
        return ChildRowPolicy::delete();
    }
}
