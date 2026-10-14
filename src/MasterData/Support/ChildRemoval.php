<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Support;

/**
 * What a child-table sync does with a local child row SSO stopped listing.
 * See ChildRowPolicy.
 */
enum ChildRemoval: string
{
    case Unlink = 'unlink';
    case Deactivate = 'deactivate';
    case Delete = 'delete';
}
