<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Stubs;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Unified\SsoClient\Contracts\ReleaseHandOff;

/**
 * Records what the middleware asked and answers with a page on this address
 * that names the target, like an app that drains browser-held work first.
 */
class StubReleaseHandOff implements ReleaseHandOff
{
    /** @var list<string> paths that stay where they are */
    public static array $keep = [];

    public static bool $answer = true;

    /** @var list<array{kind: string, target: string}> */
    public static array $calls = [];

    /** @var list<string> */
    public static array $keepAsked = [];

    public static function reset(): void
    {
        self::$keep = [];
        self::$answer = true;
        self::$calls = [];
        self::$keepAsked = [];
    }

    public function keepInPlace(Request $request): bool
    {
        self::$keepAsked[] = $request->path();

        return $request->is(...(self::$keep === [] ? ['__none__'] : self::$keep));
    }

    public function response(Request $request, string $target, string $kind): ?Response
    {
        self::$calls[] = ['kind' => $kind, 'target' => $target];

        if (! self::$answer) {
            return null;
        }

        return response('hand-off '.$kind.' to '.$target, $kind === self::KIND_INERTIA ? 409 : 200, ['X-Stub-Hand-Off' => $target]);
    }
}
