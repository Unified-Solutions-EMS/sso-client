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
     * Set on every response a handler produced, so SSO can tell a handler's
     * 404/501 ("no such run", "not configured") from the controller's own
     * "this app has no such action" 404/501.
     */
    public const HANDLED_HEADER = 'X-SSO-Action-Handled';

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

        $payload = $request->json()->all();

        try {
            $handler = app($handlerClass);

            if (! $handler instanceof SsoActionHandler) {
                return response()->json(['error' => 'Invalid action handler'], 500);
            }

            return ActionResponse::from($handler->handle($payload))
                ->toResponse()
                ->header(self::HANDLED_HEADER, '1');
        } catch (\Throwable $e) {
            // Never log the payload itself: action payloads can carry PHI.
            Log::error("SSO action [{$action}] failed", array_filter([
                'action' => $action,
                'sso_company_id' => $payload['sso_company_id'] ?? null,
                'company_id' => $payload['company_id'] ?? null,
                'exception' => $e,
            ], fn (mixed $value): bool => is_scalar($value) || $value instanceof \Throwable));

            return response()->json(['error' => 'Action failed'], 500);
        }
    }
}
