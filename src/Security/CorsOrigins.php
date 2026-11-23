<?php

declare(strict_types=1);

namespace Unified\SsoClient\Security;

/**
 * Builds the `allowed_origins` list for an app's config/cors.php.
 *
 * Several apps send credentialed CORS on api/* with
 * `allowed_origins` = WIDGET_ALLOWED_ORIGINS defaulting to '*'. With
 * supports_credentials on, Laravel's CORS handler answers '*' by echoing
 * the caller's Origin, so any website could read credentialed responses.
 * This helper returns a named list instead and never '*':
 *
 *   'allowed_origins' => \Unified\SsoClient\Security\CorsOrigins::allowed(
 *       env('WIDGET_ALLOWED_ORIGINS'),
 *       env('SSO_BASE_URL', 'https://sso.unified-apps.com'),
 *       env('APP_URL'),
 *   ),
 *
 * It runs while the config repository is still being built, so it takes
 * raw values (env() is allowed in config files) and never calls config().
 */
final class CorsOrigins
{
    /**
     * @return list<string> normalized origins (scheme://host[:port]); the
     *                      defaults are always included, '*' never is
     */
    public static function allowed(?string $configured, ?string ...$defaults): array
    {
        $origins = [];

        foreach ([...$defaults, ...explode(',', (string) $configured)] as $candidate) {
            $origin = self::origin((string) $candidate);

            if ($origin !== null && ! in_array($origin, $origins, true)) {
                $origins[] = $origin;
            }
        }

        return $origins;
    }

    /**
     * The origin of a URL, or null for '*', blanks and anything that is not
     * an absolute http(s) URL.
     */
    public static function origin(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || str_contains($url, '*')) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }
}
