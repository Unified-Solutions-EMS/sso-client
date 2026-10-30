<?php

namespace Unified\SsoClient\Assistant\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Serves the prebuilt widget bundle (dist/assistant.js, committed) from the
 * app's own origin so no CDN or Vite step is needed to mount it.
 */
class AssistantWidgetController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $path = __DIR__.'/../../../dist/assistant.js';
        $contents = (string) file_get_contents($path);
        $etag = '"'.md5($contents).'"';

        $headers = [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => $etag,
        ];

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, $headers);
        }

        return response($contents, 200, $headers);
    }
}
