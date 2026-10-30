<?php

namespace Unified\SsoClient\Assistant;

use Illuminate\Support\Facades\Cache;

/**
 * The widget bundle this app serves at /assistant/widget.js, and the short
 * version the mount tag appends to that URL so a deploy that changes the
 * bundle changes the URL and browsers fetch it again.
 */
class AssistantBundle
{
    public const CACHE_KEY = 'sso-client.assistant.widget';

    /** @var array{body: string, etag: string}|null */
    private static ?array $local = null;

    public static function servesLocal(): bool
    {
        return config('sso.assistant.bundle_source', 'sso') === 'local'
            || config('sso.assistant.proxy', true) === false;
    }

    /**
     * The committed dist/assistant.js, read once per process: it only changes
     * with a deploy, which starts new workers.
     *
     * @return array{body: string, etag: string}
     */
    public static function local(): array
    {
        if (self::$local === null) {
            $body = (string) file_get_contents(__DIR__.'/../../dist/assistant.js');
            self::$local = ['body' => $body, 'etag' => self::etagFor($body)];
        }

        return self::$local;
    }

    /**
     * The 12-character version of the bundle the next /assistant/widget.js
     * request will get. In SSO mode the SSO copy is used once it is cached;
     * before that the committed copy's hash stands in, and the page load after
     * the first widget request picks up the SSO version.
     */
    public static function version(): string
    {
        $etag = self::local()['etag'];

        if (! self::servesLocal()) {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached) && is_string($cached['etag'] ?? null) && $cached['etag'] !== '') {
                $etag = $cached['etag'];
            }
        }

        $clean = preg_replace('/^W\//', '', trim($etag, " \t\"")) ?? '';

        return substr(strlen($clean) >= 12 && ctype_xdigit($clean) ? $clean : md5($etag), 0, 12);
    }

    public static function etagFor(string $body): string
    {
        return '"'.md5($body).'"';
    }
}
