<?php

namespace Unified\SsoClient\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\Contracts\SsoActionHandler;
use Unified\SsoClient\Http\Concerns\VerifiesSsoWebhookSignature;

class SsoActionController extends Controller
{
    use VerifiesSsoWebhookSignature;

    /**
     * Handle an HMAC-signed action request from SSO.
     */
    public function __invoke(Request $request, string $action): JsonResponse
    {
        if (! $this->verifySignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $handlers = config('sso.action_handlers', []);

        if (! isset($handlers[$action])) {
            return response()->json(['error' => "Unknown action: {$action}"], 404);
        }

        $handlerClass = $handlers[$action];

        if (! class_exists($handlerClass)) {
            return response()->json(['error' => "Handler not found for action: {$action}"], 501);
        }

        try {
            $handler = app($handlerClass);

            if (! $handler instanceof SsoActionHandler) {
                return response()->json(['error' => 'Invalid action handler'], 500);
            }

            $result = $handler->handle($request->json()->all());

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error("SSO action [{$action}] failed", [
                'error' => $e->getMessage(),
                'payload' => $request->json()->all(),
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
