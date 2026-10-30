<?php

namespace Unified\SsoClient\Assistant\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Serves the widget bundle from the app's own origin so no CDN or Vite step
 * is needed to mount it.
 *
 * SSO is the origin of the bundle: apps fetch {SSO_BASE_URL}/assistant/widget.js
 * and cache it, so a widget fix ships by deploying SSO rather than updating the
 * package in every app. The committed dist/assistant.js is what SSO itself
 * serves (bundle_source = local, or proxy = false) and the fallback for an app
 * when SSO cannot be reached.
 */
class AssistantWidgetController extends Controller
{
    private const CACHE_KEY = 'sso-client.assistant.widget';

    private const FETCH_TIMEOUT_SECONDS = 5;

    private const FALLBACK_CACHE_SECONDS = 60;

    public function __invoke(Request $request): Response
    {
        ['body' => $body, 'etag' => $etag] = $this->bundle();

        $headers = [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'ETag' => $etag,
        ];

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, $headers);
        }

        return response($body, 200, $headers);
    }

    /**
     * @return array{body: string, etag: string}
     */
    private function bundle(): array
    {
        if ($this->servesLocalBundle()) {
            return $this->local();
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $fetched = $this->fromSso();

        if ($fetched !== null) {
            Cache::put(self::CACHE_KEY, $fetched, (int) config('sso.assistant.bundle_cache_seconds', 3600));

            return $fetched;
        }

        $local = $this->local();
        Cache::put(self::CACHE_KEY, $local, self::FALLBACK_CACHE_SECONDS);

        return $local;
    }

    private function servesLocalBundle(): bool
    {
        return config('sso.assistant.bundle_source', 'sso') === 'local'
            || config('sso.assistant.proxy', true) === false;
    }

    /**
     * @return array{body: string, etag: string}|null
     */
    private function fromSso(): ?array
    {
        $url = rtrim((string) config('sso.base_url'), '/').'/assistant/widget.js';

        try {
            $response = Http::timeout(self::FETCH_TIMEOUT_SECONDS)->get($url);
        } catch (\Throwable) {
            // Any failure to reach SSO (connection errors, an app test suite that
            // forbids outbound HTTP, a misconfigured URL) means the committed copy.
            return null;
        }

        if (! $response->ok() || $response->body() === '') {
            return null;
        }

        $body = $response->body();
        $etag = trim((string) $response->header('ETag'));

        return ['body' => $body, 'etag' => $etag !== '' ? $etag : $this->etagFor($body)];
    }

    /**
     * @return array{body: string, etag: string}
     */
    private function local(): array
    {
        $body = (string) file_get_contents(__DIR__.'/../../../dist/assistant.js');

        return ['body' => $body, 'etag' => $this->etagFor($body)];
    }

    private function etagFor(string $body): string
    {
        return '"'.md5($body).'"';
    }
}
