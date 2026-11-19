<?php

namespace Unified\SsoClient\Assistant;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * The anonymous person on the public website (sso.assistant.visitor). Their
 * id is a UUID v4 kept in the `unified_visitor` cookie, which the web group's
 * EncryptCookies encrypts like any other, so a browser cannot pick its own id
 * or borrow someone else's. Never read from the request body.
 */
final class AssistantVisitor
{
    public const COOKIE = 'unified_visitor';

    /** One year, in minutes. */
    public const COOKIE_MINUTES = 525600;

    public static function enabled(): bool
    {
        return (bool) config('sso.assistant.visitor', false);
    }

    /**
     * The visitor's id from the cookie, or a new one queued on the response
     * when the cookie is missing or holds something that is not a UUID.
     */
    public static function id(Request $request): string
    {
        $id = $request->cookie(self::COOKIE);

        if (is_string($id) && Str::isUuid($id)) {
            return strtolower($id);
        }

        $id = (string) Str::uuid();

        // Later requests in the same PHP worker see it too (Octane, tests).
        $request->cookies->set(self::COOKIE, $id);

        Cookie::queue(Cookie::make(
            self::COOKIE,
            $id,
            self::COOKIE_MINUTES,
            '/',
            null,
            null,
            true,
            false,
            'lax',
        ));

        return $id;
    }
}
