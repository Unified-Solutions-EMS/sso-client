<?php

namespace Unified\SsoClient\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\Contracts\SsoActionHandler;

class SsoActionController extends Controller
{
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

    protected function verifySignature(Request $request): bool
    {
        $secret = config('sso.webhook_secret');

        if (! $secret) {
            return false;
        }

        $signature = $request->header('X-SSO-Signature');

        if (! $signature) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expectedSignature, $signature);
    }
}
