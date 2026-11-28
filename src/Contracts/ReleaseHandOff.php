<?php

declare(strict_types=1);

namespace Unified\SsoClient\Contracts;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An app's say in the release-channel move (`sso.release.hand_off`).
 *
 * For an app that holds work in the browser under the address the person is
 * on (offline save queues, uploads in progress): that work belongs to this
 * address, so the app gets to keep some pages where they are and to answer
 * the move itself, typically with a page on THIS address that sends what is
 * waiting before it goes on to `$target`.
 */
interface ReleaseHandOff
{
    /** A plain page load (or frame): the package would redirect to `$target`. */
    public const KIND_REDIRECT = 'redirect';

    /** A Livewire `wire:navigate` fetch: the package would answer its full-load page. */
    public const KIND_NAVIGATE = 'navigate';

    /** An Inertia visit: the package would answer 409 with `X-Inertia-Location`. */
    public const KIND_INERTIA = 'inertia';

    /**
     * True to serve this request here even though the agency is on the
     * other deployment (the person is moved at a later page load). Asked
     * only for a signed-in person whose agency is on the other channel.
     */
    public function keepInPlace(Request $request): bool;

    /**
     * The answer for moving this request to `$target` (the other
     * deployment's full URL, `sso_company` included), or null for the
     * package's own answer.
     */
    public function response(Request $request, string $target, string $kind): ?Response;
}
